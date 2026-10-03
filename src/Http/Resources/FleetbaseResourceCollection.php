<?php

namespace Fleetbase\Http\Resources;

use Fleetbase\Http\Resources\Json\FleetbasePaginatedResourceResponse;
use Fleetbase\Support\ResourceTransformerContext;
use Fleetbase\Support\ResourceTransformerRegistry;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Arr;

/**
 * FleetbaseResourceCollection.
 *
 * Usage:
 *   return (new FleetbaseResourceCollection($paginator, Order::class))
 *       ->without(['place', 'places', 'payload.places', 'owner.places']);
 *
 * Notes:
 * - Exclusions are instance-scoped (affect only this response).
 * - Dot-notation is supported (e.g., 'payload.places', 'customer.address.street').
 * - If items are plain models/arrays, they’ll be wrapped with $collects (resource class)
 *   and exclusions applied to the resolved array.
 * - If items are already resources and implement ->without(), that will be used.
 */
class FleetbaseResourceCollection extends ResourceCollection
{
    /**
     * The name of the resource being collected.
     *
     * @var class-string|null
     */
    public $collects;

    /**
     * Whether collection keys should be preserved when serializing.
     */
    public bool $preserveKeys = false;

    /**
     * Keys to exclude from each item's serialized array (dot-notation supported).
     *
     * @var array<int, string>
     */
    protected array $excluded = [];

    /**
     * Create a new resource collection.
     *
     * @param mixed             $resource A paginator, array, or collection
     * @param class-string|null $collects Fully-qualified resource class for items
     *
     * @return void
     */
    public function __construct($resource, $collects = null)
    {
        $this->collects = $collects;

        parent::__construct($resource);
    }

    /**
     * Exclude one or more keys from every item in this collection.
     *
     * @param array<string>|string $keys
     */
    public function without(array|string $keys): static
    {
        $clone           = clone $this;
        $clone->excluded = array_values(array_unique(array_merge($this->excluded, (array) $keys)));

        return $clone;
    }

    /**
     * Convert the resource collection into an array.
     *
     * Applies the exclusion list to every item. If items are not already resources,
     * they are wrapped using the $collects class (if provided). Items are resolved (not just
     * converted) so registered resource transformers apply, sharing one transformer context
     * so `prepare()` runs once for the whole collection.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return array<int, mixed>
     */
    public function toArray($request): array
    {
        $context = $this->transformerContextFor($request);

        return $this->collection->map(function ($item) use ($request, $context) {
            // If the item is already a resource, resolve it so transformers and filtering apply.
            if ($item instanceof JsonResource) {
                if ($item instanceof FleetbaseResource) {
                    $array = $item->without($this->excluded)->withTransformerContext($context)->resolve($request);

                    return $this->applyArrayExclusions($array);
                }

                $array = $item->resolve($request);

                return $this->applyArrayExclusions($array);
            }

            // If $collects is set, wrap raw models/arrays in the resource class.
            if (is_string($this->collects) && class_exists($this->collects)) {
                $resource = new $this->collects($item);

                if ($resource instanceof FleetbaseResource) {
                    return $resource->without($this->excluded)->withTransformerContext($context)->resolve($request);
                }

                if ($resource instanceof JsonResource) {
                    return $this->applyArrayExclusions($resource->resolve($request));
                }

                if (method_exists($resource, 'toArray')) {
                    return $this->applyArrayExclusions((array) $resource->toArray($request));
                }

                return $this->applyArrayExclusions((array) $resource);
            }

            if (is_object($item) && method_exists($item, 'toArray')) {
                $array = $item->toArray();

                return $this->applyArrayExclusions($array);
            }

            // Fallback: treat as plain array/object and filter keys.
            $array = is_array($item) ? $item : (array) $item;

            return $this->applyArrayExclusions($array);
        })->all();
    }

    /**
     * Build one transformer context for the whole collection and run `prepare()` once,
     * or return null when no transformer applies to the item resource class.
     *
     * @param \Illuminate\Http\Request $request
     */
    protected function transformerContextFor($request): ?ResourceTransformerContext
    {
        $registry = ResourceTransformerRegistry::instance();

        if ($registry->isEmpty()) {
            return null;
        }

        $resourceClass = $this->itemResourceClass();

        if ($resourceClass === null) {
            return null;
        }

        $models = $this->collection
            ->map(fn ($item) => $item instanceof JsonResource ? $item->resource : $item)
            ->filter(fn ($model) => is_object($model))
            ->values();

        $first      = $models->first();
        $modelClass = is_object($first) ? get_class($first) : null;

        if (!$registry->hasTransformersFor($resourceClass, $modelClass)) {
            return null;
        }

        $context = $registry->newContext($request, ResourceTransformerContext::HTTP);
        $registry->prepare($resourceClass, $models->all(), $context);

        return $context;
    }

    /**
     * The resource class items are (or will be) wrapped in.
     *
     * @return class-string|null
     */
    protected function itemResourceClass(): ?string
    {
        if (is_string($this->collects) && class_exists($this->collects)) {
            return $this->collects;
        }

        $first = $this->collection->first(fn ($item) => $item instanceof JsonResource);

        return $first instanceof JsonResource ? get_class($first) : null;
    }

    /**
     * Apply the exclusion list to an array using dot-notation.
     *
     * @param array<string, mixed> $array
     *
     * @return array<string, mixed>
     */
    protected function applyArrayExclusions(array $array): array
    {
        if (empty($this->excluded)) {
            return $array;
        }

        foreach ($this->excluded as $key) {
            Arr::forget($array, $key);
        }

        return $array;
    }

    /**
     * Create a paginate-aware HTTP response (unchanged from your original).
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function preparePaginatedResponse($request)
    {
        if ($this->preserveAllQueryParameters) {
            $this->resource->appends($request->query());
        } elseif (!is_null($this->queryParameters)) {
            $this->resource->appends($this->queryParameters);
        }

        return (new FleetbasePaginatedResourceResponse($this))->toResponse($request);
    }
}
