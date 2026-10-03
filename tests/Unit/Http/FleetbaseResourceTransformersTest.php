<?php

use Fleetbase\Contracts\PreparesResourceTransformation;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Http\Transformers\Transformer;
use Fleetbase\Support\ResourceTransformerContext;
use Fleetbase\Support\ResourceTransformerRegistry;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MergeValue;
use Illuminate\Http\Resources\MissingValue;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;

class FrtModel extends Model
{
    public $incrementing  = false;
    protected $keyType    = 'string';
    protected $guarded    = [];
}

class FrtOwnerResource extends FleetbaseResource
{
    public function toArray($request)
    {
        return ['name' => $this->resource->name];
    }
}

/**
 * Hand-built toArray() that never calls parent::toArray() nor the registry — like most Fleetbase resources.
 */
class FrtResource extends FleetbaseResource
{
    public function toArray($request)
    {
        return [
            'id'     => $this->resource->id,
            'secret' => 'shh',
            'owner'  => $this->resource->owner ? new FrtOwnerResource($this->resource->owner) : null,
            'absent' => $this->when(false, 'never'),
        ];
    }
}

class FrtChildResource extends FrtResource
{
}

class FrtPlainResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'     => $this->resource->id,
            'secret' => 'shh',
            'owner'  => $this->resource->owner ? new FrtOwnerResource($this->resource->owner) : null,
            'absent' => $this->when(false, 'never'),
        ];
    }
}

class FrtWebhookResource extends FleetbaseResource
{
    public function toArray($request)
    {
        return ['id' => $this->resource->id, 'from' => 'toArray'];
    }

    public function toWebhookPayload(): array
    {
        return ['id' => $this->resource->id, 'from' => 'webhook'];
    }
}

class FrtBatchTransformer extends Transformer implements PreparesResourceTransformation
{
    protected static $target = FrtModel::class;

    public static int $prepared       = 0;
    public static array $preparedWith = [];

    public function prepare(Collection $models, Request $request, ResourceTransformerContext $context): void
    {
        static::$prepared++;
        static::$preparedWith[] = $models->count();
        $context->set('lookup', $models->mapWithKeys(fn ($model) => [$model->id => strtoupper((string) $model->id)])->all());
    }

    public function transform(array $data, JsonResource $resource, Request $request, ResourceTransformerContext $context): array
    {
        return $data + ['code' => $context->get('lookup')[$resource->resource->id] ?? null];
    }
}

function frt_request(string $uri = 'int/v1/widgets'): Request
{
    bind_test_container();
    ResourceTransformerRegistry::reset();

    $uri     = ltrim($uri, '/');
    $request = Request::create('/' . $uri, 'GET');
    $route   = new Route('GET', $uri, []);
    $request->setRouteResolver(fn () => $route);
    Container::getInstance()->instance('request', $request);

    return $request;
}

function frt_model(string $id = 'w1', ?string $owner = 'Ann'): FrtModel
{
    return new FrtModel(['id' => $id, 'owner' => $owner ? new FrtModel(['name' => $owner]) : null]);
}

beforeEach(function () {
    FrtBatchTransformer::$prepared     = 0;
    FrtBatchTransformer::$preparedWith = [];
});

test('resolve output is unchanged when no transformers are registered', function () {
    $request = frt_request();
    $model   = frt_model();

    $fleetbase = (new FrtResource($model))->resolve($request);
    $plain     = (new FrtPlainResource($model))->resolve($request);

    expect(json_encode($fleetbase))->toBe(json_encode($plain))
        ->and($fleetbase)->toHaveKeys(['id', 'secret', 'owner'])
        ->and($fleetbase)->not->toHaveKey('absent')
        ->and(json_decode(json_encode(new FrtResource($model)), true))->toBe([
            'id'     => 'w1',
            'secret' => 'shh',
            'owner'  => ['name' => 'Ann'],
        ]);
});

test('transformers apply through resolve without the resource opting in', function () {
    $request = frt_request();
    $model   = frt_model();

    ResourceTransformerRegistry::register(fn (array $data) => $data + ['by_resource' => true], ['target' => FrtResource::class]);
    ResourceTransformerRegistry::register(fn (array $data) => $data + ['by_model' => true], ['target' => FrtModel::class]);
    ResourceTransformerRegistry::register(fn (array $data) => $data + ['by_wildcard' => true], ['target' => '*']);
    ResourceTransformerRegistry::register(fn (array $data) => $data + ['by_other' => true], ['target' => FrtWebhookResource::class]);

    $resource = new FrtResource($model);
    $resolved = $resource->resolve($request);
    $child    = (new FrtChildResource($model))->resolve($request);

    expect($resource->toArray($request))->not->toHaveKeys(['by_resource', 'by_model', 'by_wildcard'])
        ->and($resolved)->toMatchArray(['id' => 'w1', 'by_resource' => true, 'by_model' => true, 'by_wildcard' => true])
        ->and($resolved)->not->toHaveKey('by_other')
        ->and($child)->toMatchArray(['by_resource' => true, 'by_model' => true, 'by_wildcard' => true]);
});

test('transformers receive the resource, request and context', function () {
    $request = frt_request('v1/widgets');
    $model   = frt_model();

    ResourceTransformerRegistry::register(function (array $data, JsonResource $resource, Request $request, ResourceTransformerContext $context) {
        return $data + [
            'resource_class' => get_class($resource),
            'model_id'       => $resource->resource->id,
            'path'           => $request->path(),
            'channel'        => $context->channel,
            'internal'       => $context->internal,
        ];
    }, ['target' => FrtResource::class]);

    expect((new FrtResource($model))->resolve($request))->toMatchArray([
        'resource_class' => FrtResource::class,
        'model_id'       => 'w1',
        'path'           => 'v1/widgets',
        'channel'        => 'http',
        'internal'       => false,
    ]);
});

test('transformer output is filtered for missing and merge values and respects without()', function () {
    $request = frt_request();
    $model   = frt_model();

    ResourceTransformerRegistry::register(fn (array $data) => array_merge($data, [
        'maybe'  => new MissingValue(),
        'extra'  => 'yes',
        'secret' => 'overwritten',
        new MergeValue(['merged' => true]),
    ]), ['target' => FrtResource::class]);

    $resolved = (new FrtResource($model))->resolve($request);
    $without  = (new FrtResource($model))->without(['secret', 'extra'])->resolve($request);

    expect($resolved)->toMatchArray(['extra' => 'yes', 'secret' => 'overwritten', 'merged' => true])
        ->and($resolved)->not->toHaveKey('maybe')
        ->and($without)->toMatchArray(['merged' => true])
        ->and($without)->not->toHaveKeys(['secret', 'extra', 'maybe']);
});

test('nested resources are transformed when the parent is json encoded', function () {
    $request = frt_request();
    $model   = frt_model();

    ResourceTransformerRegistry::register(fn (array $data) => $data + ['owner_flag' => true], ['target' => FrtOwnerResource::class]);

    $encoded = json_decode(json_encode((new FrtResource($model))->resolve($request)), true);

    expect($encoded['owner'])->toBe(['name' => 'Ann', 'owner_flag' => true])
        ->and($encoded)->not->toHaveKey('owner_flag');
});

test('collections transform every item and prepare once', function () {
    $request = frt_request();
    $models  = [frt_model('a'), frt_model('b'), frt_model('c')];

    ResourceTransformerRegistry::register(FrtBatchTransformer::class);

    $items = FrtResource::collection($models)->without('secret')->toArray($request);

    expect(FrtBatchTransformer::$prepared)->toBe(1)
        ->and(FrtBatchTransformer::$preparedWith)->toBe([3])
        ->and(array_column($items, 'code'))->toBe(['A', 'B', 'C'])
        ->and($items[0])->not->toHaveKey('secret');

    // already-wrapped resources share the same context too
    $wrapped = (new Fleetbase\Http\Resources\FleetbaseResourceCollection([new FrtResource($models[0]), new FrtResource($models[1])]))->toArray($request);

    expect(FrtBatchTransformer::$prepared)->toBe(2)
        ->and(FrtBatchTransformer::$preparedWith)->toBe([3, 2])
        ->and(array_column($wrapped, 'code'))->toBe(['A', 'B']);

    // a singular resolve prepares with just its own model
    expect((new FrtResource($models[2]))->resolve($request)['code'])->toBe('C')
        ->and(FrtBatchTransformer::$preparedWith)->toBe([3, 2, 1]);
});

test('collections skip the transformer pass entirely when nothing matches', function () {
    $request = frt_request();

    ResourceTransformerRegistry::register(fn (array $data) => $data + ['x' => 1], ['target' => FrtWebhookResource::class]);

    $items = FrtResource::collection([frt_model('a')])->toArray($request);

    expect($items[0])->toBe(['id' => 'a', 'secret' => 'shh', 'owner' => $items[0]['owner']])
        ->and($items[0])->not->toHaveKey('x')
        ->and($items[0]['owner'])->toBeInstanceOf(FrtOwnerResource::class);
});

test('resolveFor and transformPayload apply channel specific transformers', function () {
    $request = frt_request();
    $model   = frt_model();

    ResourceTransformerRegistry::register(fn (array $data) => $data + ['webhook_only' => true], ['target' => FrtWebhookResource::class, 'contexts' => ['webhook']]);
    ResourceTransformerRegistry::register(fn (array $data) => $data + ['http_only' => true], ['target' => FrtWebhookResource::class, 'contexts' => ['http']]);
    ResourceTransformerRegistry::register(fn (array $data) => $data + ['everywhere' => true], ['target' => FrtWebhookResource::class]);

    $resource = new FrtWebhookResource($model);

    expect($resource->resolve($request))->toBe(['id' => 'w1', 'from' => 'toArray', 'http_only' => true, 'everywhere' => true])
        ->and($resource->resolveFor(ResourceTransformerContext::WEBHOOK, $request))->toBe(['id' => 'w1', 'from' => 'toArray', 'webhook_only' => true, 'everywhere' => true])
        ->and($resource->resolveFor(ResourceTransformerContext::BROADCAST))->toBe(['id' => 'w1', 'from' => 'toArray', 'everywhere' => true])
        ->and($resource->transformPayload($resource->toWebhookPayload(), ResourceTransformerContext::WEBHOOK))->toBe(['id' => 'w1', 'from' => 'webhook', 'webhook_only' => true, 'everywhere' => true])
        ->and($resource->transformPayload(['raw' => true]))->toBe(['raw' => true, 'http_only' => true, 'everywhere' => true]);
});

test('transformPayload returns the payload untouched when nothing applies', function () {
    frt_request();

    $resource = new FrtWebhookResource(frt_model());
    $payload  = ['id' => 'w1', 'keep' => new MissingValue()];

    // no filtering side effects when no transformer ran
    expect($resource->transformPayload($payload, ResourceTransformerContext::WEBHOOK))->toBe($payload);
});
