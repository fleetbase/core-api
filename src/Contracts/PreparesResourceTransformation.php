<?php

namespace Fleetbase\Contracts;

use Fleetbase\Support\ResourceTransformerContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Optional companion to `ResourceTransformer` for transformers that need to batch-load data.
 *
 * `prepare()` is called once per resolve with every model about to be serialized (a single model
 * for a singular resource, all items for a collection) before any `transform()` call. Stash the
 * loaded data on the context and read it back in `transform()` to avoid N+1 queries.
 */
interface PreparesResourceTransformation
{
    /**
     * @param Collection<int, mixed> $models
     */
    public function prepare(Collection $models, Request $request, ResourceTransformerContext $context): void;
}
