<?php

use Fleetbase\Http\Resources\Category as CategoryResource;
use Fleetbase\Http\Resources\Role as RoleResource;
use Fleetbase\Models\Category;
use Fleetbase\Models\Permission;
use Fleetbase\Models\Policy;
use Fleetbase\Models\Role;
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

function resource_contract_container(): void
{
    bind_test_container([
        'auth.defaults.guard' => 'sanctum',
        'auth.guards.sanctum' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ]);
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

function permission_resource_model(array $attributes): Permission
{
    $permission = new Permission();
    $permission->setRawAttributes(array_merge([
        'id' => 'permission-1',
        'name' => 'iam view role',
        'guard_name' => 'sanctum',
        'description' => 'Can view role',
        'service' => 'iam',
        'updated_at' => Carbon::parse('2026-07-18 00:00:00'),
        'created_at' => Carbon::parse('2026-07-17 00:00:00'),
    ], $attributes), true);
    $permission->setRelation('pivot', (object) ['permission_id' => $permission->id]);

    return $permission;
}

function policy_resource_model(array $attributes = []): Policy
{
    $policy = new Policy();
    $policy->setRawAttributes(array_merge([
        'id' => 'policy-1',
        'company_uuid' => 'company-1',
        'name' => 'DispatchPolicy',
        'guard_name' => 'sanctum',
        'description' => 'Dispatch policy',
        'service' => 'iam',
        'updated_at' => Carbon::parse('2026-07-18 00:00:00'),
        'created_at' => Carbon::parse('2026-07-17 00:00:00'),
    ], $attributes), true);
    $policy->setRelation('permissions', collect([
        permission_resource_model([
            'id' => 'permission-policy',
            'name' => 'iam policy permission',
        ]),
    ]));

    return $policy;
}

function role_resource_model(array $attributes = []): Role
{
    $role = new Role();
    $role->setRawAttributes(array_merge([
        'id' => 'role-1',
        'company_uuid' => 'company-1',
        'name' => 'Dispatcher',
        'guard_name' => 'sanctum',
        'description' => 'Dispatch role',
        'service' => 'iam',
        'updated_at' => Carbon::parse('2026-07-18 00:00:00'),
        'created_at' => Carbon::parse('2026-07-17 00:00:00'),
    ], $attributes), true);
    $role->setRelation('permissions', collect([
        permission_resource_model([]),
        permission_resource_model([
            'id' => 'permission-2',
            'name' => 'iam edit role',
            'description' => 'Can edit role',
        ]),
    ]));
    $role->setRelation('policies', collect([
        policy_resource_model(),
    ]));

    return $role;
}

afterEach(function () {
    Container::setInstance(new FleetbaseTestContainer());
    Facade::clearResolvedInstances();
    EloquentModel::clearBootedModels();
});

test('category resource exposes public response shape with nested parent and subcategories', function () {
    resource_contract_container();

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
    resource_contract_container();

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

test('role resource serializes policies permissions and organization managed metadata', function () {
    resource_contract_container();

    $payload = (new RoleResource(role_resource_model()))->resolve(resource_contract_request('/int/v1/roles/role-1'));

    expect($payload['id'])->toBe('role-1')
        ->and($payload['company_uuid'])->toBe('company-1')
        ->and($payload['name'])->toBe('Dispatcher')
        ->and($payload['guard_name'])->toBe('sanctum')
        ->and($payload['type'])->toBe('Organization Managed')
        ->and($payload['is_mutable'])->toBeTrue()
        ->and($payload['is_deletable'])->toBeTrue()
        ->and($payload['permissions'])->toHaveCount(2)
        ->and($payload['permissions'][0])->toMatchArray([
            'id' => 'permission-1',
            'name' => 'iam view role',
            'guard_name' => 'sanctum',
            'description' => 'Can view role',
            'service' => 'iam',
        ])
        ->and($payload['policies'][0]['id'])->toBe('policy-1')
        ->and($payload['policies'][0]['permissions'][0]['id'])->toBe('permission-policy');
});

test('role resource identifies fleetbase managed roles as immutable and non deletable', function () {
    resource_contract_container();

    $payload = (new RoleResource(role_resource_model([
        'id' => 'role-managed',
        'company_uuid' => null,
        'name' => 'Administrator',
    ])))->resolve(resource_contract_request('/int/v1/roles/role-managed'));

    expect($payload['id'])->toBe('role-managed')
        ->and($payload['company_uuid'])->toBeNull()
        ->and($payload['type'])->toBe('FLB Managed')
        ->and($payload['is_mutable'])->toBeFalse()
        ->and($payload['is_deletable'])->toBeFalse();
});
