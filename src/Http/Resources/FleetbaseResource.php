<?php

namespace Fleetbase\Http\Resources;

use Fleetbase\Support\Http;
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
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
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
