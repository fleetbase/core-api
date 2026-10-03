# v1.6.68 — Resource transformers apply to every resource

## Added

- **Agnostic resource transformers.** Any extension can decorate the serialized output of any API resource without modifying the resource or its model. Register a transformer against an HTTP resource class, an Eloquent model class, an interface, or `'*'` (subclasses match), and `FleetbaseResource::resolve()` applies it to JSON responses, nested resources, collection items, webhook payloads and broadcast payloads. Transformers chain in ascending `priority` and can be scoped by `contexts` (`http`, `webhook`, `broadcast`) and `only` (`internal`, `public`). (#285)
- `Fleetbase\Contracts\ResourceTransformer`, `Fleetbase\Contracts\PreparesResourceTransformation` (a once-per-collection `prepare()` hook for batch loading, so transformers never add N+1 queries), `Fleetbase\Support\ResourceTransformerContext`, and the `Fleetbase\Http\Transformers\Transformer` base class.
- Closure transformers via `ResourceTransformerRegistry::register(fn (...) => ..., ['target' => ...])`.
- `CoreServiceProvider::$transformers`, `registerTransformers()` and `registerTransformersFrom(__DIR__ . '/../Http/Transformers')` for declarative and directory-based registration from extensions, mirroring expansions.

## Changed

- `FleetbaseResourceCollection` resolves items (instead of calling `toArray()`), sharing one `prepare()` pass per collection. A hand-built collection with a manually set `preserveKeys` now filters item arrays with the item's flag.
- `ResourceLifecycleEvent` payloads, chat participant broadcasts, `Utils::serializeJsonResource()` and the cached internal user payload serialize through `resolve()`, so transformers reach them and conditional `MissingValue`s are no longer emitted as `{}`.
- `Find::httpResourceForModel()` caches internal and public resolutions separately, consulting the request only when a model has a dedicated `Internal` resource.

## Removed

- Legacy duck-typed transformers (`$target` property + static `output($model, $data)`), `ResourceTransformerRegistry::transform(Model, array)`, `resolveByTarget()`, `fixClassName()` and the static `$transformers` array. The `User` resource no longer calls the registry directly.

## Dependencies

- `fleetbase/laravel-mysql-spatial` `^1.0.3`. The spatial `MysqlConnection` no longer connects to MySQL when the connection object is built, so resolving `DB::connection()` during boot (for example `artisan package:discover` during `composer install`) no longer requires a reachable database.

## Upgrade Steps

- Extensions that registered a legacy transformer must implement `Fleetbase\Contracts\ResourceTransformer` (or extend `Fleetbase\Http\Transformers\Transformer`) and register it through `$transformers` or `registerTransformersFrom()`. See the README section "Resource transformers". The only known legacy consumer, aws-marketplace, is deprecated and is not updated.
