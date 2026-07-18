<?php

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Http\Resources\FleetbaseResourceCollection;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Facade;

class FleetbaseResourceCollectionTestResource extends FleetbaseResource
{
}

class FleetbaseResourceCollectionTestPlainItems extends FleetbaseResourceCollection
{
}

class FleetbaseResourceCollectionPlainResource extends JsonResource
{
    public function toArray($request = null): array
    {
        return [
            'id'      => $this->resource['id'],
            'visible' => $this->resource['visible'],
            'secret'  => $this->resource['secret'],
        ];
    }
}

class FleetbaseResourceCollectionMutableCollects extends FleetbaseResourceCollection
{
    public function forceCollects(?string $collects): static
    {
        $this->collects = $collects;

        return $this;
    }

    public function exposePaginatedResponse(Request $request)
    {
        return $this->preparePaginatedResponse($request);
    }

    public function forceResource(object $resource): static
    {
        $this->resource = $resource;

        return $this;
    }
}

class FleetbaseResourceCollectionArrayableItem
{
    public function __construct(private array $attributes)
    {
    }

    public function toArray(): array
    {
        return $this->attributes;
    }
}

class FleetbaseResourceCollectionPaginatorFake
{
    public array $appends = [];

    public function __construct(private array $items = [['resource' => ['id' => 'page-item-1']]])
    {
    }

    public function appends(array|string|null $key, ?string $value = null): static
    {
        if (is_array($key)) {
            $this->appends = array_merge($this->appends, $key);
        } elseif ($key !== null) {
            $this->appends[$key] = $value;
        }

        return $this;
    }

    public function toArray(): array
    {
        return [
            'current_page'   => 1,
            'data'           => [['id' => 'page-item-1']],
            'first_page_url' => 'https://fleetbase.test/resources?page=1',
            'from'           => 1,
            'last_page'      => 2,
            'last_page_url'  => 'https://fleetbase.test/resources?page=2',
            'next_page_url'  => 'https://fleetbase.test/resources?page=2',
            'path'           => 'https://fleetbase.test/resources',
            'per_page'       => 1,
            'prev_page_url'  => null,
            'to'             => 1,
            'total'          => 2,
        ];
    }

    public function map(callable $callback)
    {
        return collect(array_map($callback, $this->items));
    }
}

function fleetbase_resource_collection_request(): Request
{
    bind_test_container();

    return Request::create('/int/v1/resources', 'GET');
}

afterEach(function () {
    Container::setInstance(new FleetbaseTestContainer());
    Facade::clearResolvedInstances();
});

test('resource collection applies exclusions without mutating the original instance', function () {
    $request = fleetbase_resource_collection_request();

    $collection = new FleetbaseResourceCollection([
        [
            'id'      => 'item-1',
            'name'    => 'Visible',
            'secret'  => 'hidden',
            'payload' => [
                'keep'   => true,
                'secret' => 'nested-hidden',
            ],
        ],
    ]);

    $filtered = $collection->without(['secret', 'payload.secret']);

    expect($collection->toArray($request))->toBe([
        [
            'id'      => 'item-1',
            'name'    => 'Visible',
            'secret'  => 'hidden',
            'payload' => [
                'keep'   => true,
                'secret' => 'nested-hidden',
            ],
        ],
    ])
        ->and($filtered)->not->toBe($collection)
        ->and($filtered->toArray($request))->toBe([
            [
                'id'      => 'item-1',
                'name'    => 'Visible',
                'payload' => ['keep' => true],
            ],
        ]);
});

test('resource collection wraps raw items with fleetbase resources and applies recursive exclusions', function () {
    $request = fleetbase_resource_collection_request();

    $collection = (new FleetbaseResourceCollection([
        [
            'id'      => 'wrapped-1',
            'secret'  => 'top-secret',
            'payload' => [
                'secret' => 'nested-secret',
                'keep'   => [
                    'secret' => 'deep-secret',
                    'value'  => 'safe',
                ],
            ],
        ],
    ], FleetbaseResourceCollectionTestResource::class))->without('secret');

    expect($collection->toArray($request))->toBe([
        [
            'id'      => 'wrapped-1',
            'payload' => [
                'keep' => [
                    'value' => 'safe',
                ],
            ],
        ],
    ]);
});

test('resource collection wraps raw items after construction when a valid resource class is configured', function () {
    $request = fleetbase_resource_collection_request();

    $collection = (new FleetbaseResourceCollectionMutableCollects([
        [
            'id'      => 'late-wrapped-1',
            'visible' => 'yes',
            'secret'  => 'no',
            'payload' => [
                'secret' => 'nested-no',
                'keep'   => true,
            ],
        ],
    ]))->forceCollects(FleetbaseResourceCollectionPlainResource::class)->without('secret');

    expect($collection->toArray($request))->toBe([
        [
            'id'      => 'late-wrapped-1',
            'visible' => 'yes',
        ],
    ]);
});

test('resource collection filters plain json resources and arrayable objects', function () {
    $request = fleetbase_resource_collection_request();

    $jsonResourceCollection = (new FleetbaseResourceCollectionTestPlainItems([
        new FleetbaseResourceCollectionPlainResource([
            'id'      => 'json-1',
            'visible' => 'yes',
            'secret'  => 'no',
        ]),
    ]))->without('secret');

    $arrayableCollection = (new FleetbaseResourceCollectionTestPlainItems([
        new FleetbaseResourceCollectionArrayableItem([
            'id'      => 'arrayable-1',
            'visible' => 'yes',
            'secret'  => 'no',
        ]),
    ]))->without('secret');

    expect($jsonResourceCollection->toArray($request))->toBe([
        [
            'id'      => 'json-1',
            'visible' => 'yes',
        ],
    ])
        ->and($arrayableCollection->toArray($request))->toBe([
            [
                'id'      => 'arrayable-1',
                'visible' => 'yes',
            ],
        ]);
});

test('resource collection falls back to array filtering when the configured resource class is unavailable', function () {
    $request = fleetbase_resource_collection_request();

    $collection = (new FleetbaseResourceCollectionMutableCollects([
        [
            'id'      => 'invalid-collects-1',
            'visible' => 'yes',
            'secret'  => 'no',
        ],
    ]))->forceCollects('Fleetbase\\Http\\Resources\\MissingResource')->without('secret');

    expect($collection->toArray($request))->toBe([
        [
            'id'      => 'invalid-collects-1',
            'visible' => 'yes',
        ],
    ]);
});

test('resource collection handles object fallback serialization when no resource class is provided', function () {
    $request = fleetbase_resource_collection_request();

    $item          = new stdClass();
    $item->id      = 'object-1';
    $item->visible = 'yes';
    $item->secret  = 'no';

    $collection = (new FleetbaseResourceCollectionTestPlainItems([$item]))->without('secret');

    expect($collection->toArray($request))->toBe([
        [
            'id'      => 'object-1',
            'visible' => 'yes',
        ],
    ]);
});

test('resource collection paginated responses preserve all request query parameters when requested', function () {
    $request = fleetbase_resource_collection_request();
    $request->query->add([
        'sort'   => '-created_at',
        'filter' => 'active',
    ]);
    $request->attributes->set('request_start_time', microtime(true) - 0.01);

    $paginator = new FleetbaseResourceCollectionPaginatorFake();

    $collection = (new FleetbaseResourceCollectionMutableCollects([]))->forceResource($paginator)->preserveQuery();

    $response = $collection->exposePaginatedResponse($request);
    $payload  = $response->getData(true);

    expect($payload['meta']['total'])->toBe(2)
        ->and($payload['meta']['per_page'])->toBe(1)
        ->and($payload['meta']['current_page'])->toBe(1)
        ->and($paginator->appends)->toBe([
            'sort'   => '-created_at',
            'filter' => 'active',
        ]);
});

test('resource collection paginated responses append explicit query parameters without preserving the full request query', function () {
    $request = fleetbase_resource_collection_request();
    $request->query->add([
        'sort'   => '-created_at',
        'filter' => 'active',
    ]);
    $request->attributes->set('request_start_time', microtime(true) - 0.01);

    $paginator = new FleetbaseResourceCollectionPaginatorFake();

    $collection = (new FleetbaseResourceCollectionMutableCollects([]))->forceResource($paginator)->withQuery([
        'filter' => 'explicit',
    ]);

    $response = $collection->exposePaginatedResponse($request);
    $payload  = $response->getData(true);

    expect($payload['meta']['last_page'])->toBe(2)
        ->and($paginator->appends)->toBe([
            'filter' => 'explicit',
        ]);
});
