<?php

namespace Fleetbase\Support;

use Fleetbase\Contracts\PreparesResourceTransformation;
use Fleetbase\Contracts\ResourceTransformer;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Registry of resource transformers.
 *
 * Any extension can register a transformer for an HTTP resource class, an Eloquent model class, an
 * interface, or every resource ('*'). `FleetbaseResource::resolve()` consults the registry for every
 * resource it serializes, so nothing in the resource or model has to change. Transformers chain in
 * priority order (lower first; later ones can override earlier output), can be restricted to a
 * channel (http, webhook, broadcast) or audience (internal, public), and can batch-load data once per
 * collection through `PreparesResourceTransformation`.
 *
 * The registry is bound as a container singleton by `CoreServiceProvider`. Use the static helpers or
 * `app(ResourceTransformerRegistry::class)`:
 *
 *     ResourceTransformerRegistry::register(UserBadgeTransformer::class);
 *     ResourceTransformerRegistry::register(fn (array $data) => $data + ['flag' => true], ['target' => User::class]);
 *
 * @phpstan-type TransformerOptions array{target?: string|array<int, string>, priority?: int, contexts?: array<int, string>|null, only?: string|null, id?: string}
 * @phpstan-type TransformerRegistration array{id: string, transformer: string|callable|object, targets: array<int, string>, priority: int, contexts: array<int, string>|null, only: string|null, order: int}
 */
class ResourceTransformerRegistry
{
    public const WILDCARD      = '*';
    public const ONLY_INTERNAL = 'internal';
    public const ONLY_PUBLIC   = 'public';

    /**
     * @var array<string, TransformerRegistration>
     */
    protected array $registrations = [];

    /**
     * Ordered registration ids per "resourceClass|modelClass" key.
     *
     * @var array<string, array<int, string>>
     */
    protected array $matchCache = [];

    /**
     * Instantiated class-string transformers.
     *
     * @var array<string, object>
     */
    protected array $instances = [];

    protected int $sequence = 0;

    /**
     * Used when the container has no binding (early boot, bare test containers).
     */
    protected static ?self $fallback = null;

    /**
     * Resolve the active registry: the container singleton when bound, else a process-local fallback.
     */
    public static function instance(): self
    {
        $container = Container::getInstance();

        if ($container->bound(static::class)) {
            $instance = $container->make(static::class);
            if ($instance instanceof self) {
                return $instance;
            }
        }

        return static::$fallback ??= new self();
    }

    /**
     * Register one transformer, or many.
     *
     * Accepts a class implementing `ResourceTransformer`, an instance, a closure/callable (requires
     * `options['target']`), or an array of any of those; array entries may be `[transformer, options]`
     * pairs or `class => options` map entries.
     *
     * @param string|callable|object|array<int|string, mixed> $transformer
     * @param TransformerOptions                              $options
     */
    public static function register(string|callable|object|array $transformer, array $options = []): void
    {
        if (is_array($transformer) && !is_callable($transformer)) {
            static::instance()->addMany($transformer);

            return;
        }

        static::instance()->add($transformer, $options);
    }

    public static function forget(string $transformer): bool
    {
        return static::instance()->remove($transformer);
    }

    /**
     * Remove every registration from the active registry and the fallback.
     */
    public static function reset(): void
    {
        static::instance()->flush();
        static::$fallback?->flush();
    }

    /**
     * Register a single transformer and return its registration id (the class name for classes).
     *
     * @param TransformerOptions $options
     *
     * @throws \InvalidArgumentException
     */
    public function add(string|callable|object $transformer, array $options = []): string
    {
        $registration = $this->normalize($transformer, $options);

        $existing = $this->registrations[$registration['id']] ?? null;

        $registration['order'] = $existing !== null ? $existing['order'] : $this->sequence++;

        $this->registrations[$registration['id']] = $registration;

        unset($this->instances[$registration['id']]);
        $this->matchCache = [];

        return $registration['id'];
    }

    /**
     * Register many transformers.
     *
     * @param array<int|string, mixed> $transformers
     *
     * @return array<int, string> registration ids
     */
    public function addMany(array $transformers): array
    {
        $ids = [];

        foreach ($transformers as $key => $entry) {
            if (is_string($key)) {
                /** @var TransformerOptions $entryOptions */
                $entryOptions = is_array($entry) ? $entry : [];
                $ids[]        = $this->add($key, $entryOptions);
                continue;
            }

            if (is_array($entry) && !is_callable($entry)) {
                if (array_is_list($entry) && count($entry) === 2 && is_array($entry[1]) && (is_string($entry[0]) || is_callable($entry[0]) || is_object($entry[0]))) {
                    /** @var TransformerOptions $entryOptions */
                    $entryOptions = $entry[1];
                    $ids[]        = $this->add($entry[0], $entryOptions);
                    continue;
                }

                throw new \InvalidArgumentException('Attempted to register invalid resource transformer: expected a class, callable, instance or [transformer, options] pair.');
            }

            if (is_string($entry) || is_callable($entry) || is_object($entry)) {
                $ids[] = $this->add($entry);
                continue;
            }

            throw new \InvalidArgumentException('Attempted to register invalid resource transformer: ' . get_debug_type($entry));
        }

        return $ids;
    }

    public function remove(string $idOrClass): bool
    {
        $id = ltrim($idOrClass, '\\');

        if (!isset($this->registrations[$id])) {
            return false;
        }

        unset($this->registrations[$id], $this->instances[$id]);
        $this->matchCache = [];

        return true;
    }

    public function flush(): void
    {
        $this->registrations = [];
        $this->instances     = [];
        $this->matchCache    = [];
        $this->sequence      = 0;
    }

    public function has(string $idOrClass): bool
    {
        return isset($this->registrations[ltrim($idOrClass, '\\')]);
    }

    /**
     * @return array<int, TransformerRegistration> in registration order
     */
    public function all(): array
    {
        $registrations = array_values($this->registrations);
        usort($registrations, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        return $registrations;
    }

    /**
     * @return array<int, string>
     */
    public function ids(): array
    {
        return array_keys($this->registrations);
    }

    public function isEmpty(): bool
    {
        return $this->registrations === [];
    }

    /**
     * Registrations applicable to a resource class (and optionally the model it wraps), in execution order.
     *
     * @return array<int, TransformerRegistration>
     */
    public function matching(string $resourceClass, ?string $modelClass = null): array
    {
        if ($this->registrations === []) {
            return [];
        }

        $resourceClass = ltrim($resourceClass, '\\');
        $modelClass    = $modelClass !== null ? ltrim($modelClass, '\\') : null;
        $key           = $resourceClass . '|' . ($modelClass ?? '');

        if (!isset($this->matchCache[$key])) {
            $matches = array_filter(
                $this->registrations,
                fn (array $registration) => $this->targetsMatch($registration['targets'], $resourceClass, $modelClass)
            );

            usort($matches, fn (array $a, array $b) => [$a['priority'], $a['order']] <=> [$b['priority'], $b['order']]);

            $this->matchCache[$key] = array_column($matches, 'id');
        }

        return array_map(fn (string $id) => $this->registrations[$id], $this->matchCache[$key]);
    }

    /**
     * Cheap guard: does anything apply to this resource at all?
     */
    public function hasTransformersFor(JsonResource|string $resource, ?string $modelClass = null): bool
    {
        if ($this->registrations === []) {
            return false;
        }

        if ($resource instanceof JsonResource) {
            $modelClass = is_object($resource->resource) ? get_class($resource->resource) : $modelClass;
            $resource   = get_class($resource);
        }

        return $this->matching($resource, $modelClass) !== [];
    }

    /**
     * Create a context for one resolve.
     *
     * @throws \InvalidArgumentException
     */
    public function newContext(Request $request, string $channel = ResourceTransformerContext::HTTP): ResourceTransformerContext
    {
        if (!in_array($channel, ResourceTransformerContext::CHANNELS, true)) {
            throw new \InvalidArgumentException('Unknown resource transformer channel: ' . $channel);
        }

        return new ResourceTransformerContext($request, $channel, Http::isInternalRequest($request));
    }

    /**
     * Run `prepare()` on every applicable `PreparesResourceTransformation` transformer, once per context.
     *
     * @param iterable<int|string, mixed> $models
     */
    public function prepare(string $resourceClass, iterable $models, ResourceTransformerContext $context): void
    {
        if ($this->registrations === []) {
            return;
        }

        $models = Collection::make($models)->filter(fn ($model) => $model !== null)->values();
        if ($models->isEmpty()) {
            return;
        }

        $first      = $models->first();
        $modelClass = is_object($first) ? get_class($first) : null;

        foreach ($this->matching($resourceClass, $modelClass) as $registration) {
            if ($context->isPrepared($registration['id']) || !$this->applies($registration, $context)) {
                continue;
            }

            if (is_string($registration['transformer']) && !is_a($registration['transformer'], PreparesResourceTransformation::class, true)) {
                continue;
            }

            $instance = $this->resolveInstance($registration);

            if ($instance instanceof PreparesResourceTransformation) {
                $context->markPrepared($registration['id']);
                $instance->prepare($models, $context->request, $context);
            }
        }
    }

    /**
     * Chain every applicable transformer over the serialized data.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     *
     * @throws \UnexpectedValueException when a callable transformer does not return an array
     */
    public function apply(array $data, JsonResource $resource, ResourceTransformerContext $context): array
    {
        if ($this->registrations === []) {
            return $data;
        }

        $modelClass = is_object($resource->resource) ? get_class($resource->resource) : null;

        foreach ($this->matching(get_class($resource), $modelClass) as $registration) {
            if (!$this->applies($registration, $context)) {
                continue;
            }

            $data = $this->invoke($registration, $data, $resource, $context);
        }

        return $data;
    }

    /**
     * Convenience for an already-serialized payload: builds the context and prepares a single model.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function transform(array $data, JsonResource $resource, Request $request, string $channel = ResourceTransformerContext::HTTP, ?ResourceTransformerContext $context = null): array
    {
        if (!$this->hasTransformersFor($resource)) {
            return $data;
        }

        if ($context === null) {
            $context = $this->newContext($request, $channel);
            $this->prepare(get_class($resource), [$resource->resource], $context);
        }

        return $this->apply($data, $resource, $context);
    }

    /**
     * @param TransformerRegistration $registration
     */
    protected function applies(array $registration, ResourceTransformerContext $context): bool
    {
        if ($registration['contexts'] !== null && !in_array($context->channel, $registration['contexts'], true)) {
            return false;
        }

        if ($registration['only'] === self::ONLY_INTERNAL && !$context->internal) {
            return false;
        }

        if ($registration['only'] === self::ONLY_PUBLIC && $context->internal) {
            return false;
        }

        return true;
    }

    /**
     * @param TransformerRegistration $registration
     * @param array<string, mixed>    $data
     *
     * @return array<string, mixed>
     */
    protected function invoke(array $registration, array $data, JsonResource $resource, ResourceTransformerContext $context): array
    {
        $instance = $this->resolveInstance($registration);

        if ($instance instanceof ResourceTransformer) {
            return $instance->transform($data, $resource, $context->request, $context);
        }

        // @codeCoverageIgnoreStart
        // normalize() only admits ResourceTransformer classes/instances and callables, so this cannot be reached.
        if (!is_callable($instance)) {
            throw new \UnexpectedValueException('Resource transformer "' . $registration['id'] . '" is not invokable.');
        }
        // @codeCoverageIgnoreEnd

        $result = $instance($data, $resource, $context->request, $context);

        if (!is_array($result)) {
            throw new \UnexpectedValueException('Resource transformer "' . $registration['id'] . '" must return an array, got ' . get_debug_type($result) . '.');
        }

        /** @var array<string, mixed> $result */
        return $result;
    }

    /**
     * @param TransformerRegistration $registration
     *
     * @return object|callable
     */
    protected function resolveInstance(array $registration): mixed
    {
        $transformer = $registration['transformer'];

        if (!is_string($transformer)) {
            return $transformer;
        }

        if (!isset($this->instances[$registration['id']])) {
            $instance = Container::getInstance()->make($transformer);
            // @codeCoverageIgnoreStart
            // The container returns an instance for a concrete class string; guard against custom bindings.
            if (!is_object($instance)) {
                throw new \UnexpectedValueException('Unable to instantiate resource transformer ' . $transformer . '.');
            }
            // @codeCoverageIgnoreEnd
            $this->instances[$registration['id']] = $instance;
        }

        return $this->instances[$registration['id']];
    }

    /**
     * @param array<int, string> $targets
     */
    protected function targetsMatch(array $targets, string $resourceClass, ?string $modelClass): bool
    {
        foreach ($targets as $target) {
            if ($target === self::WILDCARD) {
                return true;
            }

            if (is_a($resourceClass, $target, true)) {
                return true;
            }

            if ($modelClass !== null && is_a($modelClass, $target, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate a transformer and its options into a registration (without `order`).
     *
     * @param TransformerOptions $options
     *
     * @return TransformerRegistration
     *
     * @throws \InvalidArgumentException
     */
    protected function normalize(string|callable|object $transformer, array $options): array
    {
        $classOptions = [];

        if (is_string($transformer)) {
            $class = ltrim($transformer, '\\');

            if (!Utils::classExists($class)) {
                throw new \InvalidArgumentException('Attempted to register invalid resource transformer: class ' . $class . ' does not exist.');
            }

            /** @var class-string $class */
            $reflection = new \ReflectionClass($class);

            if (!$reflection->isInstantiable() || !$reflection->implementsInterface(ResourceTransformer::class)) {
                throw new \InvalidArgumentException('Attempted to register invalid resource transformer: ' . $class . ' must be an instantiable class implementing ' . ResourceTransformer::class . '.');
            }

            /** @var class-string<ResourceTransformer> $class */
            $targets      = $class::target();
            $classOptions = static::classOptions($class);
            $id           = $class;
            $transformer  = $class;
        } elseif ($transformer instanceof ResourceTransformer) {
            $targets      = $transformer::target();
            $classOptions = static::classOptions(get_class($transformer));
            $id           = $options['id'] ?? get_class($transformer);
        } elseif (is_callable($transformer)) {
            $targets = $options['target'] ?? null;
            $id      = $options['id'] ?? static::callableId($transformer);
        } else {
            throw new \InvalidArgumentException('Attempted to register invalid resource transformer: ' . get_debug_type($transformer));
        }

        if (isset($options['target'])) {
            $targets = $options['target'];
        }

        $options = array_merge($classOptions, $options);

        $targets = $this->normalizeTargets($targets, $id);

        $priority = $options['priority'] ?? 0;
        if (!is_int($priority) && !(is_string($priority) && is_numeric($priority))) {
            throw new \InvalidArgumentException('Resource transformer "' . $id . '" priority must be an integer.');
        }

        $contexts = $options['contexts'] ?? null;
        if ($contexts !== null) {
            if (!is_array($contexts) || $contexts === []) {
                throw new \InvalidArgumentException('Resource transformer "' . $id . '" contexts must be a non-empty array or null.');
            }
            $contexts = array_values(array_unique(array_map(fn ($channel) => is_string($channel) ? $channel : '', $contexts)));
            foreach ($contexts as $channel) {
                if (!in_array($channel, ResourceTransformerContext::CHANNELS, true)) {
                    throw new \InvalidArgumentException('Resource transformer "' . $id . '" has an unknown context "' . $channel . '". Expected one of: ' . implode(', ', ResourceTransformerContext::CHANNELS) . '.');
                }
            }
        }

        $only = $options['only'] ?? null;
        if ($only !== null && !in_array($only, [self::ONLY_INTERNAL, self::ONLY_PUBLIC], true)) {
            throw new \InvalidArgumentException('Resource transformer "' . $id . '" only must be "internal", "public" or null.');
        }

        return [
            'id'          => $id,
            'transformer' => $transformer,
            'targets'     => $targets,
            'priority'    => (int) $priority,
            'contexts'    => $contexts,
            'only'        => $only,
            'order'       => 0,
        ];
    }

    /**
     * @return array<int, string>
     *
     * @throws \InvalidArgumentException
     */
    protected function normalizeTargets(mixed $targets, string $id): array
    {
        if ($targets === null || $targets === '' || $targets === []) {
            throw new \InvalidArgumentException('Resource transformer "' . $id . '" has no target. Provide a resource class, model class, interface or "*".');
        }

        $normalized = [];

        foreach (is_array($targets) ? $targets : [$targets] as $target) {
            if (!is_string($target) || $target === '') {
                throw new \InvalidArgumentException('Resource transformer "' . $id . '" has an invalid target: ' . get_debug_type($target) . '.');
            }

            $normalized[] = $target === self::WILDCARD ? self::WILDCARD : ltrim($target, '\\');
        }

        return array_values(array_unique($normalized));
    }

    protected static function callableId(callable $callable): string
    {
        if ($callable instanceof \Closure) {
            return 'closure:' . spl_object_id($callable);
        }

        if (is_object($callable)) {
            return get_class($callable) . ':' . spl_object_id($callable);
        }

        // Only array callables remain: strings are registered as transformer classes, never as callables.
        /** @var array{0: object|string, 1: string} $callable */
        $target = is_object($callable[0]) ? get_class($callable[0]) . ':' . spl_object_id($callable[0]) : $callable[0];

        return 'callable:' . $target . '::' . $callable[1];
    }

    /**
     * Options declared on the transformer class through a static `options()` method, if any.
     *
     * @param class-string $class
     *
     * @return array<string, mixed>
     */
    protected static function classOptions(string $class): array
    {
        $callable = [$class, 'options'];

        if (!is_callable($callable)) {
            return [];
        }

        $options = call_user_func($callable);

        return is_array($options) ? $options : [];
    }
}
