<?php

use Fleetbase\Contracts\PreparesResourceTransformation;
use Fleetbase\Contracts\ResourceTransformer;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Http\Transformers\Transformer;
use Fleetbase\Support\ResourceTransformerContext;
use Fleetbase\Support\ResourceTransformerRegistry;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;

interface RtrTaggable
{
}

class RtrModel extends Model
{
    protected $guarded = [];
}

class RtrChildModel extends RtrModel implements RtrTaggable
{
}

class RtrResource extends FleetbaseResource
{
    public function toArray($request)
    {
        return [
            'id'   => $this->resource->id ?? null,
            'name' => $this->resource->name ?? null,
        ];
    }
}

class RtrChildResource extends RtrResource
{
}

class RtrOtherResource extends FleetbaseResource
{
}

class RtrAppendTransformer extends Transformer
{
    protected static $target = RtrResource::class;

    public function transform(array $data, JsonResource $resource, Request $request, ResourceTransformerContext $context): array
    {
        return $data + ['appended' => true];
    }
}

class RtrModelTargetTransformer extends Transformer
{
    protected static $target = RtrModel::class;

    public function transform(array $data, JsonResource $resource, Request $request, ResourceTransformerContext $context): array
    {
        return $data + ['model_targeted' => $context->channel];
    }
}

class RtrInterfaceTransformer extends Transformer
{
    protected static $target = RtrTaggable::class;

    public function transform(array $data, JsonResource $resource, Request $request, ResourceTransformerContext $context): array
    {
        return $data + ['tagged' => true];
    }
}

class RtrWildcardOptionsTransformer extends Transformer
{
    protected static $target   = '*';
    protected static $priority = 5;
    protected static $contexts = [ResourceTransformerContext::WEBHOOK];
    protected static $only     = 'internal';

    public function transform(array $data, JsonResource $resource, Request $request, ResourceTransformerContext $context): array
    {
        return $data + ['wildcard' => true];
    }
}

class RtrOrderTransformer implements ResourceTransformer
{
    public function __construct(private string $label)
    {
    }

    public static function target(): string|array
    {
        return [RtrResource::class];
    }

    public function transform(array $data, JsonResource $resource, Request $request, ResourceTransformerContext $context): array
    {
        $data['order'] = ($data['order'] ?? '') . $this->label;

        return $data;
    }
}

abstract class RtrAbstractTransformer extends Transformer
{
    protected static $target = RtrResource::class;
}

class RtrNotATransformer
{
    public static $target = RtrResource::class;

    public static function output($model, $data)
    {
        return $data;
    }
}

class RtrPreparingTransformer extends Transformer implements PreparesResourceTransformation
{
    protected static $target = RtrModel::class;

    public static int $prepared        = 0;
    public static array $preparedCount = [];

    public function prepare(Collection $models, Request $request, ResourceTransformerContext $context): void
    {
        static::$prepared++;
        static::$preparedCount[] = $models->count();
        $context->set('names', $models->map(fn ($model) => $model->name)->all());
    }

    public function transform(array $data, JsonResource $resource, Request $request, ResourceTransformerContext $context): array
    {
        return $data + ['prepared_names' => $context->get('names')];
    }
}

function rtr_request(string $uri): Request
{
    $uri     = ltrim($uri, '/');
    $request = Request::create('/' . $uri, 'GET');
    $route   = new Route('GET', $uri, []);
    $request->setRouteResolver(fn () => $route);
    Container::getInstance()->instance('request', $request);

    return $request;
}

beforeEach(function () {
    bind_test_container();
    ResourceTransformerRegistry::reset();
    RtrPreparingTransformer::$prepared      = 0;
    RtrPreparingTransformer::$preparedCount = [];
});

test('registry registers classes, instances, closures and batches with stable ids', function () {
    $registry = ResourceTransformerRegistry::instance();

    $closure = fn (array $data) => $data + ['closure' => true];

    expect($registry->add(RtrAppendTransformer::class))->toBe(RtrAppendTransformer::class)
        ->and($registry->add('\\' . RtrModelTargetTransformer::class))->toBe(RtrModelTargetTransformer::class)
        ->and($registry->add(new RtrOrderTransformer('a'), ['id' => 'order-a']))->toBe('order-a')
        ->and($registry->add($closure, ['target' => RtrResource::class, 'id' => 'closure-a']))->toBe('closure-a')
        ->and($registry->add(fn (array $data) => $data, ['target' => RtrResource::class]))->toStartWith('closure:')
        ->and($registry->has(RtrAppendTransformer::class))->toBeTrue()
        ->and($registry->has('\\' . RtrAppendTransformer::class))->toBeTrue()
        ->and($registry->has('order-a'))->toBeTrue()
        ->and($registry->has(RtrOtherResource::class))->toBeFalse()
        ->and(array_column($registry->all(), 'id'))->toHaveCount(5)
        ->and($registry->all()[0]['id'])->toBe(RtrAppendTransformer::class)
        ->and($registry->all()[0]['targets'])->toBe([RtrResource::class])
        ->and($registry->all()[0]['priority'])->toBe(0)
        ->and($registry->all()[0]['contexts'])->toBeNull()
        ->and($registry->all()[0]['only'])->toBeNull();

    $registry->flush();
    expect($registry->isEmpty())->toBeTrue();

    $ids = $registry->addMany([
        RtrAppendTransformer::class,
        [RtrModelTargetTransformer::class, ['priority' => 3]],
        RtrInterfaceTransformer::class => ['only' => 'public'],
        [new RtrOrderTransformer('b'), ['id' => 'order-b']],
    ]);

    expect($ids)->toBe([RtrAppendTransformer::class, RtrModelTargetTransformer::class, RtrInterfaceTransformer::class, 'order-b'])
        ->and($registry->all()[1]['priority'])->toBe(3)
        ->and($registry->all()[2]['only'])->toBe('public');
});

test('registry batch registration through the static helper accepts every supported form', function () {
    ResourceTransformerRegistry::register([
        RtrAppendTransformer::class,
        [RtrModelTargetTransformer::class, ['priority' => 3]],
        RtrInterfaceTransformer::class => ['only' => 'public'],
        [fn (array $data) => $data, ['target' => '*', 'id' => 'closure-batch']],
    ]);
    ResourceTransformerRegistry::register(fn (array $data) => $data, ['target' => RtrModel::class, 'id' => 'closure-single']);

    $registry = ResourceTransformerRegistry::instance();

    expect($registry->ids())->toBe([
        RtrAppendTransformer::class,
        RtrModelTargetTransformer::class,
        RtrInterfaceTransformer::class,
        'closure-batch',
        'closure-single',
    ])
        ->and($registry->all()[1]['priority'])->toBe(3)
        ->and($registry->all()[2]['only'])->toBe('public')
        ->and($registry->all()[3]['targets'])->toBe(['*'])
        ->and(ResourceTransformerRegistry::forget('closure-batch'))->toBeTrue()
        ->and(ResourceTransformerRegistry::forget('closure-batch'))->toBeFalse()
        ->and($registry->has('closure-batch'))->toBeFalse();

    ResourceTransformerRegistry::reset();
    expect($registry->isEmpty())->toBeTrue();
});

test('registry re-registering a class replaces its options and keeps its original order', function () {
    $registry = ResourceTransformerRegistry::instance();

    $registry->add(RtrAppendTransformer::class, ['priority' => 1]);
    $registry->add(RtrModelTargetTransformer::class);
    $registry->add(RtrAppendTransformer::class, ['priority' => 9, 'only' => 'internal']);

    expect($registry->all())->toHaveCount(2)
        ->and($registry->all()[0]['id'])->toBe(RtrAppendTransformer::class)
        ->and($registry->all()[0]['priority'])->toBe(9)
        ->and($registry->all()[0]['only'])->toBe('internal');
});

test('registry rejects invalid registrations', function () {
    $registry = ResourceTransformerRegistry::instance();

    expect(fn () => $registry->add('Fleetbase\\Missing\\Transformer'))->toThrow(InvalidArgumentException::class, 'does not exist')
        ->and(fn () => $registry->add(RtrNotATransformer::class))->toThrow(InvalidArgumentException::class, 'must be an instantiable class implementing')
        ->and(fn () => $registry->add(RtrAbstractTransformer::class))->toThrow(InvalidArgumentException::class, 'must be an instantiable class implementing')
        ->and(fn () => $registry->add(fn (array $data) => $data))->toThrow(InvalidArgumentException::class, 'has no target')
        ->and(fn () => $registry->add(RtrAppendTransformer::class, ['only' => 'admins']))->toThrow(InvalidArgumentException::class, 'only must be')
        ->and(fn () => $registry->add(RtrAppendTransformer::class, ['contexts' => ['email']]))->toThrow(InvalidArgumentException::class, 'unknown context')
        ->and(fn () => $registry->add(RtrAppendTransformer::class, ['contexts' => []]))->toThrow(InvalidArgumentException::class, 'contexts must be')
        ->and(fn () => $registry->add(RtrAppendTransformer::class, ['priority' => 'high']))->toThrow(InvalidArgumentException::class, 'priority must be')
        ->and(fn () => $registry->add(RtrAppendTransformer::class, ['target' => [123]]))->toThrow(InvalidArgumentException::class, 'invalid target')
        ->and(fn () => $registry->addMany([[RtrAppendTransformer::class]]))->toThrow(InvalidArgumentException::class, 'Attempted to register invalid resource transformer')
        ->and(fn () => $registry->addMany([123]))->toThrow(InvalidArgumentException::class, 'Attempted to register invalid resource transformer')
        ->and(fn () => $registry->newContext(Request::create('/'), 'email'))->toThrow(InvalidArgumentException::class, 'Unknown resource transformer channel')
        ->and($registry->isEmpty())->toBeTrue();
});

test('registry matches targets by resource class hierarchy, model class hierarchy, interface and wildcard', function () {
    $registry = ResourceTransformerRegistry::instance();

    $registry->add(RtrAppendTransformer::class);
    $registry->add(RtrModelTargetTransformer::class);
    $registry->add(RtrInterfaceTransformer::class);
    $registry->add(RtrWildcardOptionsTransformer::class);

    $ids = fn (string $resource, ?string $model = null) => array_column($registry->matching($resource, $model), 'id');

    expect($ids(RtrResource::class))->toBe([RtrAppendTransformer::class, RtrWildcardOptionsTransformer::class])
        ->and($ids('\\' . RtrChildResource::class))->toBe([RtrAppendTransformer::class, RtrWildcardOptionsTransformer::class])
        ->and($ids(RtrOtherResource::class))->toBe([RtrWildcardOptionsTransformer::class])
        ->and($ids(RtrOtherResource::class, RtrModel::class))->toBe([RtrModelTargetTransformer::class, RtrWildcardOptionsTransformer::class])
        ->and($ids(RtrOtherResource::class, RtrChildModel::class))->toBe([RtrModelTargetTransformer::class, RtrInterfaceTransformer::class, RtrWildcardOptionsTransformer::class])
        ->and($ids(RtrChildResource::class, RtrChildModel::class))->toBe([RtrAppendTransformer::class, RtrModelTargetTransformer::class, RtrInterfaceTransformer::class, RtrWildcardOptionsTransformer::class])
        ->and($ids(JsonResource::class, 'Fleetbase\\Missing\\Model'))->toBe([RtrWildcardOptionsTransformer::class])
        ->and($registry->hasTransformersFor(RtrOtherResource::class))->toBeTrue()
        ->and($registry->hasTransformersFor(new RtrOtherResource(new RtrChildModel())))->toBeTrue();

    $registry->remove(RtrWildcardOptionsTransformer::class);

    expect($ids(RtrOtherResource::class))->toBe([])
        ->and($registry->hasTransformersFor(RtrOtherResource::class))->toBeFalse()
        ->and($registry->hasTransformersFor(new RtrOtherResource(['id' => 1])))->toBeFalse()
        ->and($registry->hasTransformersFor(new RtrOtherResource(new RtrModel())))->toBeTrue();

    // the match cache is invalidated on registration
    $registry->add(fn (array $data) => $data, ['target' => RtrOtherResource::class, 'id' => 'late']);
    expect($ids(RtrOtherResource::class))->toBe(['late']);
});

test('registry orders transformers by ascending priority then registration order', function () {
    $registry = ResourceTransformerRegistry::instance();

    $registry->add(new RtrOrderTransformer('c'), ['id' => 'c', 'priority' => 10]);
    $registry->add(new RtrOrderTransformer('a'), ['id' => 'a', 'priority' => -1]);
    $registry->add(new RtrOrderTransformer('b1'), ['id' => 'b1']);
    $registry->add(new RtrOrderTransformer('b2'), ['id' => 'b2']);

    $context = $registry->newContext(rtr_request('v1/widgets'));

    expect(array_column($registry->matching(RtrResource::class), 'id'))->toBe(['a', 'b1', 'b2', 'c'])
        ->and($registry->apply([], new RtrResource(new RtrModel()), $context))->toBe(['order' => 'ab1b2c']);
});

test('registry applies channel and audience filters', function () {
    $registry = ResourceTransformerRegistry::instance();

    $registry->add(RtrWildcardOptionsTransformer::class);
    $registry->add(fn (array $data) => $data + ['public_only' => true], ['target' => '*', 'id' => 'public', 'only' => 'public']);
    $registry->add(fn (array $data) => $data + ['http_broadcast' => true], ['target' => '*', 'id' => 'hb', 'contexts' => ['http', 'broadcast']]);

    $resource = new RtrResource(new RtrModel());

    $internal = rtr_request('int/v1/widgets');
    $public   = rtr_request('v1/widgets');

    $internalHttp    = $registry->newContext($internal, ResourceTransformerContext::HTTP);
    $internalWebhook = $registry->newContext($internal, ResourceTransformerContext::WEBHOOK);
    $publicWebhook   = $registry->newContext($public, ResourceTransformerContext::WEBHOOK);
    $publicBroadcast = $registry->newContext($public, ResourceTransformerContext::BROADCAST);

    expect($internalHttp->isInternal())->toBeTrue()
        ->and($internalHttp->isHttp())->toBeTrue()
        ->and($publicWebhook->isPublic())->toBeTrue()
        ->and($publicWebhook->isWebhook())->toBeTrue()
        ->and($publicBroadcast->isBroadcast())->toBeTrue()
        ->and($registry->apply([], $resource, $internalHttp))->toBe(['http_broadcast' => true])
        ->and($registry->apply([], $resource, $internalWebhook))->toBe(['wildcard' => true])
        ->and($registry->apply([], $resource, $publicWebhook))->toBe(['public_only' => true])
        ->and($registry->apply([], $resource, $publicBroadcast))->toBe(['public_only' => true, 'http_broadcast' => true]);
});

test('registry rejects callable transformers that do not return arrays', function () {
    $registry = ResourceTransformerRegistry::instance();
    $registry->add(fn (array $data) => 'nope', ['target' => '*', 'id' => 'bad']);

    $context = $registry->newContext(rtr_request('v1/widgets'));

    expect(fn () => $registry->apply([], new RtrResource(new RtrModel()), $context))
        ->toThrow(UnexpectedValueException::class, 'Resource transformer "bad" must return an array');
});

test('registry prepares transformers once per context and hands data to transform', function () {
    $registry = ResourceTransformerRegistry::instance();
    $registry->add(RtrPreparingTransformer::class);
    $registry->add(RtrAppendTransformer::class);

    $models  = [new RtrModel(['name' => 'one']), new RtrModel(['name' => 'two'])];
    $context = $registry->newContext(rtr_request('v1/widgets'));

    $registry->prepare(RtrResource::class, $models, $context);
    $registry->prepare(RtrResource::class, $models, $context);

    expect(RtrPreparingTransformer::$prepared)->toBe(1)
        ->and(RtrPreparingTransformer::$preparedCount)->toBe([2])
        ->and($context->isPrepared(RtrPreparingTransformer::class))->toBeTrue()
        ->and($context->get('names'))->toBe(['one', 'two'])
        ->and($registry->apply(['id' => 1], new RtrResource($models[0]), $context))->toBe([
            'id'             => 1,
            'prepared_names' => ['one', 'two'],
            'appended'       => true,
        ]);

    // prepare() with nothing to prepare is a no-op
    $registry->prepare(RtrResource::class, [null], $registry->newContext(rtr_request('v1/widgets')));
    expect(RtrPreparingTransformer::$prepared)->toBe(1);

    // the transform() convenience builds a context and prepares the single wrapped model
    $resource = new RtrResource(new RtrModel(['name' => 'solo']));
    expect($registry->transform(['id' => 2], $resource, rtr_request('v1/widgets')))->toBe([
        'id'             => 2,
        'prepared_names' => ['solo'],
        'appended'       => true,
    ])
        ->and(RtrPreparingTransformer::$preparedCount)->toBe([2, 1])
        ->and($registry->transform(['id' => 3], new RtrOtherResource(['id' => 3]), rtr_request('v1/widgets')))->toBe(['id' => 3]);
});

test('registry instance prefers the container binding and falls back when unbound', function () {
    $bound = ResourceTransformerRegistry::instance();
    $bound->add(RtrAppendTransformer::class);

    expect(ResourceTransformerRegistry::instance())->toBe($bound)
        ->and(Container::getInstance()->make(ResourceTransformerRegistry::class))->toBe($bound);

    Container::setInstance(new FleetbaseTestContainer());

    $fallback = ResourceTransformerRegistry::instance();
    $fallback->add(RtrModelTargetTransformer::class);

    expect($fallback)->not->toBe($bound)
        ->and(ResourceTransformerRegistry::instance())->toBe($fallback)
        ->and($fallback->has(RtrAppendTransformer::class))->toBeFalse();

    ResourceTransformerRegistry::reset();
    expect($fallback->isEmpty())->toBeTrue();

    bind_test_container();
    expect(ResourceTransformerRegistry::instance())->not->toBe($fallback);
});

test('transformer context stores, remembers and forgets attributes', function () {
    $context = new ResourceTransformerContext(Request::create('/'), ResourceTransformerContext::BROADCAST, true);

    expect($context->has('a'))->toBeFalse()
        ->and($context->get('a', 'default'))->toBe('default')
        ->and($context->set('a', 1)->get('a'))->toBe(1)
        ->and($context->remember('b', fn (ResourceTransformerContext $ctx) => $ctx->get('a') + 1))->toBe(2)
        ->and($context->remember('b', fn () => 99))->toBe(2)
        ->and($context->all())->toBe(['a' => 1, 'b' => 2])
        ->and($context->forget('a')->has('a'))->toBeFalse()
        ->and($context->channel)->toBe('broadcast')
        ->and($context->internal)->toBeTrue()
        ->and($context->isPrepared('x'))->toBeFalse();

    $context->markPrepared('x');
    expect($context->isPrepared('x'))->toBeTrue();
});

test('abstract transformer base requires a target and exposes options', function () {
    expect(RtrWildcardOptionsTransformer::target())->toBe('*')
        ->and(RtrWildcardOptionsTransformer::options())->toBe(['priority' => 5, 'contexts' => ['webhook'], 'only' => 'internal'])
        ->and(RtrAppendTransformer::options())->toBe(['priority' => 0, 'contexts' => null, 'only' => null])
        ->and(fn () => (new class extends Transformer {
            public function transform(array $data, JsonResource $resource, Request $request, ResourceTransformerContext $context): array
            {
                return $data;
            }
        })::target())->toThrow(LogicException::class, 'must define a static $target property');
});
