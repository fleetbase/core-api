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
