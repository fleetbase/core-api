<?php

namespace Fleetbase\Contracts;

use Fleetbase\Support\ResourceTransformerContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A resource transformer decorates the serialized output of an HTTP resource without the
 * resource or its model having to know about it. Transformers are registered with the
 * `ResourceTransformerRegistry` and applied by `FleetbaseResource::resolve()` to HTTP
 * responses, nested resources, collection items, webhook payloads and broadcast payloads.
 */
interface ResourceTransformer
{
    /**
     * The class(es) this transformer applies to.
     *
     * A target may be an HTTP resource class, an Eloquent model class, an interface implemented
     * by either, or `'*'` to match every resource. Subclasses of a target also match.
     *
     * @return string|array<int, string>
     */
    public static function target(): string|array;

    /**
     * Transform the serialized resource data.
     *
     * @param array<string, mixed> $data the data produced by the resource's `toArray()`, after filtering
     *
     * @return array<string, mixed>
     */
    public function transform(array $data, JsonResource $resource, Request $request, ResourceTransformerContext $context): array;
}
