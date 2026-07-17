<?php

use Fleetbase\Support\Http;
use Fleetbase\Support\TemplateString;
use Illuminate\Database\Eloquent\Model;
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
        ->and(Http::useSort('-created_at'))->toBe(['created_at', 'desc'])
        ->and(Http::useSort('name:desc'))->toBe(['name', 'desc'])
        ->and(Http::useSort(['status', 'asc']))->toBe(['status', 'asc'])
        ->and(Http::isPublicIp('8.8.8.8'))->toBeTrue()
        ->and(Http::isPrivateIp('10.0.0.1'))->toBeTrue()
        ->and(Http::action('POST'))->toBe('create')
        ->and(Http::action('GET'))->toBe('query')
        ->and(Http::action('PATCH'))->toBe('update')
        ->and(Http::action('DELETE'))->toBe('delete');
});
