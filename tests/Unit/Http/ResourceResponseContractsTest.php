<?php

use Fleetbase\Http\Resources\Category as CategoryResource;
use Fleetbase\Models\Category;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;

function resource_contract_request(string $uri, array $query = []): Request
{
    $request = Request::create($uri, 'GET', $query);
    $route   = new Route('GET', ltrim($uri, '/'), []);
    $request->setRouteResolver(fn () => $route);
    app()->instance('request', $request);

    return $request;
}

function category_resource_model(array $attributes): Category
{
    $category = new Category();
    $category->setRawAttributes(array_merge([
        'id' => 1,
        'uuid' => 'category-1',
        'public_id' => 'cat_1',
        'company_uuid' => 'company-1',
        'owner_uuid' => 'owner-1',
        'owner_type' => 'company',
        'icon' => 'box',
        'name' => 'Operations',
        'description' => 'Operational categories',
        'tags' => '["ops","dispatch"]',
        'translations' => '{"es":{"name":"Operaciones"}}',
        'meta' => '{"priority":"high"}',
        'for' => 'orders',
        'order' => 5,
        'slug' => 'operations',
        'updated_at' => Carbon::parse('2026-07-18 00:00:00'),
        'created_at' => Carbon::parse('2026-07-17 00:00:00'),
    ], $attributes), true);
    $category->setRelation('iconFile', null);
    $category->setRelation('parentCategory', null);
    $category->setRelation('subCategories', collect());

    return $category;
}

afterEach(function () {
    Container::setInstance(new FleetbaseTestContainer());
    Facade::clearResolvedInstances();
    EloquentModel::clearBootedModels();
});

test('category resource exposes public response shape with nested parent and subcategories', function () {
    bind_test_container();

    $parent = category_resource_model([
        'id' => 10,
        'uuid' => 'category-parent',
        'public_id' => 'cat_parent',
        'name' => 'Parent Category',
        'tags' => '[]',
        'translations' => '[]',
        'meta' => null,
    ]);
    $child = category_resource_model([
        'id' => 11,
        'uuid' => 'category-child',
        'public_id' => 'cat_child',
        'name' => 'Child Category',
        'tags' => '[]',
        'translations' => '[]',
        'meta' => null,
    ]);
    $category = category_resource_model([]);
    $category->setRelation('parentCategory', $parent);
    $category->setRelation('subCategories', collect([$child]));

    $payload = (new CategoryResource($category))->resolve(resource_contract_request('/v1/categories/cat_1', [
        'with_parent' => true,
        'with_subcategories' => true,
    ]));

    expect($payload['id'])->toBe('cat_1')
        ->and($payload)->not->toHaveKeys(['uuid', 'company_uuid', 'owner_uuid', 'owner_type', 'public_id'])
        ->and($payload['name'])->toBe('Operations')
        ->and($payload['icon_url'])->toBe('https://flb-assets.s3.ap-southeast-1.amazonaws.com/images/fallback-placeholder-1.png')
        ->and($payload['tags'])->toBe(['ops', 'dispatch'])
        ->and($payload['translations'])->toBe(['es' => ['name' => 'Operaciones']])
        ->and($payload['meta'])->toBe(['priority' => 'high'])
        ->and($payload['parent'])->toBe('cat_parent')
        ->and($payload['subcategories'][0]['id'])->toBe('cat_child');
});

test('category resource exposes internal identifiers and can suppress nested relationships', function () {
    bind_test_container();

    $parent = category_resource_model([
        'id' => 10,
        'uuid' => 'category-parent',
        'public_id' => 'cat_parent',
        'name' => 'Parent Category',
    ]);
    $child = category_resource_model([
        'id' => 11,
        'uuid' => 'category-child',
        'public_id' => 'cat_child',
        'name' => 'Child Category',
    ]);
    $category = category_resource_model([]);
    $category->setRelation('parentCategory', $parent);
    $category->setRelation('subCategories', collect([$child]));

    $payload = (new CategoryResource($category, [
        'without_parent' => true,
        'without_subcategories' => true,
    ]))->resolve(resource_contract_request('/int/v1/categories/category-1', [
        'with_parent' => true,
        'with_subcategories' => true,
    ]));

    expect($payload['id'])->toBe(1)
        ->and($payload['uuid'])->toBe('category-1')
        ->and($payload['public_id'])->toBe('cat_1')
        ->and($payload['company_uuid'])->toBe('company-1')
        ->and($payload['owner_uuid'])->toBe('owner-1')
        ->and($payload['owner_type'])->toBe('company')
        ->and($payload)->not->toHaveKeys(['parent', 'subcategories']);
});
