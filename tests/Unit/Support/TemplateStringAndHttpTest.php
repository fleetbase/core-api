<?php

use Fleetbase\Support\Http;
use Fleetbase\Support\TemplateString;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;

class TemplateStringFixtureModel extends Model
{
    public function resolveDynamicProperty(string $key): mixed
    {
        return [
            'order.number'  => 'Order ABC',
            'waypoint.type' => 'pickup',
            'item'          => 'box',
            'empty'         => null,
        ][$key] ?? null;
    }
}

class RouteFixture
{
    public function __construct(private string $uri, public array $action = [])
    {
    }

    public function uri(): string
    {
        return $this->uri;
    }
}

function routed_request(string $uri, array $action = []): Request
{
    $request = Request::create('/' . ltrim($uri, '/'));
    $request->setRouteResolver(fn () => new RouteFixture($uri, $action));

    return $request;
}

test('template string resolves placeholders with modifier syntaxes and escaping', function () {
    $model = new TemplateStringFixtureModel();

    $resolved = TemplateString::resolve(
        'Ref {order.number | snake | upper}; next {capitalize waypoint.type}; plural {item plural}; literal \{empty}',
        $model
    );

    expect($resolved)->toBe('Ref ORDER_A_B_C; next Pickup; plural boxes; literal {empty}')
        ->and(TemplateString::resolve('Missing [{empty}]', $model))->toBe('Missing []')
        ->and(TemplateString::resolve('{waypoint.type title}', $model))->toBe('Pickup')
        ->and(TemplateString::resolve('{lowercase order.number}', $model))->toBe('order abc')
        ->and(TemplateString::resolve('{order.number | camel}', $model))->toBe('orderABC')
        ->and(TemplateString::resolve('{order.number | studly}', $model))->toBe('OrderABC')
        ->and(TemplateString::resolve('{order.number | slug}', $model))->toBe('order-a-b-c')
        ->and(TemplateString::resolve('{item | singular}', $model))->toBe('box')
        ->and(TemplateString::resolve('Blank [{   }]', $model))->toBe('Blank []')
        ->and(TemplateString::resolve('{order.number unknown_modifier}', $model))->toBe('Order ABC');
});

test('template string rejects models without a callable resolver', function () {
    TemplateString::resolve('Hello {name}', new class extends Model {
    });
})->throws(InvalidArgumentException::class, 'Resolver method "resolveDynamicProperty" is not callable');

test('http helper classifies internal and public routes and parses request utilities', function () {
    expect(Http::isInternalRequest(routed_request('int/v1/users')))->toBeTrue()
        ->and(Http::isInternalRequest(routed_request('storefront/int/v1/orders')))->toBeTrue()
        ->and(Http::isInternalRequest(routed_request('legacy/users', ['namespace' => 'Fleetbase\Http\Controllers\Internal\v1'])))->toBeTrue()
        ->and(Http::isInternalRequest(routed_request('v1/orders')))->toBeFalse()
        ->and(Http::isPublicRequest(routed_request('v1/orders')))->toBeTrue()
        ->and(Http::isPublicRequest(routed_request('int/v1/orders')))->toBeFalse()
        ->and(Http::isInternalRequest(Request::create('/v1/orders')))->toBeFalse()
        ->and(Http::isPublicRequest(Request::create('/v1/orders')))->toBeFalse()
        ->and(Http::useSort('-created_at'))->toBe(['created_at', 'desc'])
        ->and(Http::useSort('name:desc'))->toBe(['name', 'desc'])
        ->and(Http::useSort(Request::create('/v1/orders', 'GET', ['sort' => 'updated_at'])))->toBe(['updated_at', 'asc'])
        ->and(Http::useSort(['status', 'asc']))->toBe(['status', 'asc'])
        ->and(Http::isPublicIp('8.8.8.8'))->toBeTrue()
        ->and(Http::isPrivateIp('10.0.0.1'))->toBeTrue()
        ->and(Http::action('POST'))->toBe('create')
        ->and(Http::action('GET'))->toBe('query')
        ->and(Http::action('PATCH'))->toBe('update')
        ->and(Http::action('DELETE'))->toBe('delete');
});

test('http helper traces client metadata and lookup fallbacks without network access', function () {
    $container = bind_test_container([
        'fleetbase.services.ipinfo.api_key' => null,
    ]);
    $container->instance(Factory::class, new Factory());

    Http::fake([
        'www.cloudflare.com/cdn-cgi/trace'  => Http::response("ip=203.0.113.10\nloc=US\nmalformed-line\n", 200),
        'json.geoiplookup.io/203.0.113.10'  => Http::response(['ip' => '203.0.113.10', 'country_code' => 'US'], 200),
        'json.geoiplookup.io/198.51.100.20' => Http::response(['ip' => '198.51.100.20', 'country_code' => 'SG'], 200),
        'api.ipdata.co/8.8.4.4*'            => Http::response(['ip' => '8.8.4.4', 'country_code' => 'US'], 200),
    ]);

    $request = Request::create('/v1/lookup', 'GET', [], [], [], ['REMOTE_ADDR' => '198.51.100.20']);

    expect(Http::trace())->toBe(['ip' => '203.0.113.10', 'loc' => 'US'])
        ->and(Http::trace('ip'))->toBe('203.0.113.10')
        ->and(Http::lookupIp())->toBe(['ip' => '203.0.113.10', 'country_code' => 'US'])
        ->and(Http::lookupIp($request))->toBe(['ip' => '198.51.100.20', 'country_code' => 'SG']);

    config([
        'fleetbase.services.ipinfo.api_key' => 'ipdata-key',
    ]);

    expect(Http::lookupIp('8.8.4.4'))->toBe(['ip' => '8.8.4.4', 'country_code' => 'US']);
});

test('http helper action falls back to the current server request method', function () {
    $previous = $_SERVER['REQUEST_METHOD'] ?? null;

    try {
        $_SERVER['REQUEST_METHOD'] = 'PATCH';

        expect(Http::action())->toBe('update');
    } finally {
        if ($previous === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $previous;
        }
    }
});
