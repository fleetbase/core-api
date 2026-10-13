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
- Untyped relation methods can no longer be requested by a client (see Security). Give the method a return type (`public function driver(): BelongsTo`) or list its name in `protected $withable = [...]`.
- A relation requested in another case (`subfleets`) loads under the declared method name (`subFleets`), the key resources read.
- `without` on create, update and public list responses adds to the model's hidden attributes (`makeHidden`) instead of replacing them (`setHidden`), so it can no longer un-hide the model's defaults.

### Security
- Request `with` / `expand` / `with_count` / `count` names, and a template query's stored `with` list, reach Eloquent only when they name a real relation. That means a method or Expandable closure that declares an Eloquent Relation return type (a union counts when one member is a Relation), or a name the model lists in a new `$withable` array. Each segment of a nested path is checked against the related model, and the path is cut at the first segment that fails. Nothing after a `morphTo` is kept, and a `morphTo` is never counted. Anything else is dropped without an error. Previously Eloquent called any requested public method with no required arguments on a blank model: `with[]=saveInstance` inserted an empty row, and fleetops' `Order::dispatch()` saved an order and queued its events. See `Fleetbase\Support\RequestRelations`.

### Removed
- `HasApiModelBehavior::normalizeRequestRelations()` (protected, no callers outside the trait).
- Legacy duck-typed transformers (`$target` property + static `output($model, $data)`), `ResourceTransformerRegistry::transform(Model, array)`, `resolveByTarget()`, `fixClassName()` and the static `$transformers` array. The `User` resource no longer calls the registry directly.
- Adds first version

### Security
- `NotificationRegistry` only resolves notifiables registered with `registerNotifiable()`. A company's saved notification settings could name any `dynamic:<property>`, which reached any public no-argument method on the notification subject (for example fleetops `Order::dispatch()`), or any class name, which the container built. Unregistered definitions are now skipped; extensions must register every `dynamic:` name and notifiable class they expect to receive.
