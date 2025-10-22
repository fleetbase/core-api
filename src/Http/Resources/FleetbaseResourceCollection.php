<?php

namespace Fleetbase\Http\Resources;

use Fleetbase\Http\Resources\Json\FleetbasePaginatedResourceResponse;
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
     * they are wrapped using the $collects class (if provided).
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return array<int, mixed>
     */
    public function toArray($request): array
    {
        return $this->collection->map(function ($item) use ($request) {
            // If the item is already a resource and has ->without(), use it.
            if (is_object($item) && method_exists($item, 'toArray')) {
                if (method_exists($item, 'without')) {
                    /** @var object $item */
                    $array = $item->without($this->excluded)->toArray($request);

                    return $this->applyArrayExclusions($array);
                }

                // Otherwise, just resolve it to array and then filter.
                $array = $item->toArray($request);

                return $this->applyArrayExclusions($array);
            }

            // If $collects is set, wrap the raw item in the resource class.
            if (is_string($this->collects) && class_exists($this->collects)) {
                $resource = new $this->collects($item);

                if (method_exists($resource, 'without')) {
                    $array = $resource->without($this->excluded)->toArray($request);
                } else {
                    $array = $resource->toArray($request);
                    $array = $this->applyArrayExclusions($array);
                }

                return $array;
            }

            // Fallback: treat as plain array/object and filter keys.
            $array = is_array($item) ? $item : (array) $item;

            return $this->applyArrayExclusions($array);
        })->all();
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
