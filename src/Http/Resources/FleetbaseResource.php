<?php

namespace Fleetbase\Http\Resources;

use Fleetbase\Support\Http;
use Fleetbase\Support\ResourceTransformerContext;
use Fleetbase\Support\ResourceTransformerRegistry;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class FleetbaseResource extends JsonResource
{
    /**
     * List of attributes to exclude from the final array.
     */
    protected array $excluded = [];

    /**
     * Transformer context injected by a parent collection so every item shares one `prepare()` pass.
     */
    protected ?ResourceTransformerContext $transformerContext = null;

    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     *
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        $data = parent::toArray($request);
        $data = $this->filterExcluded($data);

        return $data;
    }

    /**
     * Resolve the resource to an array and apply registered resource transformers.
     *
     * Laravel routes every serialization through `resolve()` (responses, nested resources,
     * `jsonSerialize()`), so transformers registered with `ResourceTransformerRegistry` reach
     * every resource without the resource having to opt in.
     *
     * @param Request|null $request
     *
     * @return array<string, mixed>
     */
    public function resolve($request = null)
    {
        $request = $this->resolveRequest($request);

        return $this->applyTransformers(parent::resolve($request), $request, ResourceTransformerContext::HTTP);
    }

    /**
     * Resolve the resource for a specific channel (`webhook`, `broadcast`).
     *
     * @param Request|null $request
     *
     * @return array<string, mixed>
     */
    public function resolveFor(string $channel, $request = null): array
    {
        $request = $this->resolveRequest($request);

        return $this->applyTransformers(parent::resolve($request), $request, $channel);
    }

    /**
     * Apply registered transformers to an already-built payload (e.g. `toWebhookPayload()` output).
     *
     * @param array<string, mixed> $data
     * @param Request|null         $request
     *
     * @return array<string, mixed>
     */
    public function transformPayload(array $data, string $channel = ResourceTransformerContext::HTTP, $request = null): array
    {
        return $this->applyTransformers($data, $this->resolveRequest($request), $channel);
    }

    /**
     * Share a transformer context (clone, like `without()`).
     */
    public function withTransformerContext(?ResourceTransformerContext $context): static
    {
        $clone                     = clone $this;
        $clone->transformerContext = $context;

        return $clone;
    }

    /**
     * Create a new anonymous resource collection.
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public static function collection($resource)
    {
        return tap(
            new FleetbaseResourceCollection($resource, static::class),
            function ($collection) {
                if (property_exists(static::class, 'preserveKeys')) {
                    $collection->preserveKeys = (new static([]))->preserveKeys === true;
                }
            }
        );
    }

    /**
     * Checks if resource is null.
     *
     * @return bool
     */
    public function isEmpty()
    {
        return is_null($this->resource) || is_null($this->resource->resource);
    }

    /**
     * Get all internal id properties, only when internal request.
     */
    public function getInternalIds(): array
    {
        $attributes  = $this->getAttributes();
        $internalIds = [];

        foreach ($attributes as $key => $value) {
            if (Str::endsWith($key, '_uuid')) {
                $internalIds[$key] = $this->when(Http::isInternalRequest(), $value);
            }
        }

        return $internalIds;
    }

    /**
     * Exclude one or more keys from serialization.
     */
    public function without(array|string $keys): static
    {
        $clone           = clone $this;
        $clone->excluded = array_merge($this->excluded, (array) $keys);

        return $clone;
    }

    /**
     * Run registered transformers over serialized data, then re-filter conditional values and exclusions.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function applyTransformers(array $data, Request $request, string $channel): array
    {
        $registry = ResourceTransformerRegistry::instance();

        if (!$registry->hasTransformersFor($this)) {
            return $data;
        }

        $context = $this->transformerContext;

        if ($context === null) {
            $context = $registry->newContext($request, $channel);
            $registry->prepare(static::class, [$this->resource], $context);
        }

        $data = $registry->apply($data, $this, $context);

        // Transformers may return when()/MissingValue/MergeValue values and must not reintroduce excluded keys.
        return $this->filterExcluded($this->filter($data));
    }

    /**
     * @param Request|null $request
     */
    protected function resolveRequest($request): Request
    {
        if ($request instanceof Request) {
            return $request;
        }

        $resolved = Container::getInstance()->make('request');

        if (!$resolved instanceof Request) {
            throw new \RuntimeException('Unable to resolve the current request for resource serialization.');
        }

        return $resolved;
    }

    /**
     * Remove excluded keys recursively.
     */
    protected function filterExcluded(array $data): array
    {
        foreach ($this->excluded as $key) {
            Arr::forget($data, $key);
        }

        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = $this->filterExcluded($v);
            }
        }

        return $data;
    }
}
