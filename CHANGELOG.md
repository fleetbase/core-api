# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]
### Added
- `ResourceTransformerRegistry` rewritten as a container singleton so any extension can decorate resource output without modifying the resource or model. Transformers target a resource class, model class, interface or `'*'` (subclasses match), chain in ascending `priority`, and can be limited by `contexts` (`http`, `webhook`, `broadcast`) and `only` (`internal`, `public`).
- `Fleetbase\Contracts\ResourceTransformer`, `Fleetbase\Contracts\PreparesResourceTransformation` (batch `prepare()` hook to avoid N+1 queries), `Fleetbase\Support\ResourceTransformerContext` and the `Fleetbase\Http\Transformers\Transformer` base class.
- Closure/callable transformers via `ResourceTransformerRegistry::register(fn (...) => ..., ['target' => ...])`.
- `CoreServiceProvider::$transformers`, `registerTransformers()` and `registerTransformersFrom()` for declarative and directory-based registration from extensions.

### Changed
- `FleetbaseResource::resolve()` applies registered transformers to every resource, nested resource and collection item; `FleetbaseResourceCollection` resolves items (instead of calling `toArray()`), sharing one `prepare()` pass per collection. A hand-built collection with a manually set `preserveKeys` now filters item arrays with the item's flag.
- `ResourceLifecycleEvent::getEventData()` and `broadcastWith()`, chat participant broadcast events, `Utils::serializeJsonResource()` and the cached internal user payload serialize through `resolve()`, so transformers apply to webhook and broadcast payloads and conditional `MissingValue`s are no longer emitted as `{}`.
- `Find::httpResourceForModel()` caches internal and public resolutions separately.

### Removed
- Legacy duck-typed transformers (`$target` property + static `output($model, $data)`), `ResourceTransformerRegistry::transform(Model, array)`, `resolveByTarget()`, `fixClassName()` and the static `$transformers` array. The `User` resource no longer calls the registry directly.
- Adds first version
