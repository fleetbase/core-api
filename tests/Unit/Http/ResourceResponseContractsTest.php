<?php

use Fleetbase\Facades\FileResolver as FileResolverFacade;
use Fleetbase\Http\Resources\Author as AuthorResource;
use Fleetbase\Http\Resources\Category as CategoryResource;
use Fleetbase\Http\Resources\ChatAttachment as ChatAttachmentResource;
use Fleetbase\Http\Resources\CompressedJsonResource;
use Fleetbase\Http\Resources\DeletedResource;
use Fleetbase\Http\Resources\Json\FleetbasePaginatedResourceResponse;
use Fleetbase\Http\Resources\Policy as PolicyResource;
use Fleetbase\Http\Resources\Role as RoleResource;
use Fleetbase\Http\Resources\ScheduleTemplate as ScheduleTemplateResource;
use Fleetbase\Http\Resources\Template as TemplateResource;
use Fleetbase\Models\Category;
use Fleetbase\Models\Permission;
use Fleetbase\Models\Policy;
use Fleetbase\Models\Role;
use Fleetbase\Models\ScheduleTemplate as ScheduleTemplateModel;
use Fleetbase\Models\Template as TemplateModel;
use Fleetbase\Services\FileResolverService;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Response;

class ResourceContractCompressedResponseFactory
{
    public array $payloads = [];

    public function compressedJson(mixed $data): JsonResponse
    {
        $this->payloads[] = $data;

        return new JsonResponse(['compressed' => $data]);
    }
}

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
            'driver'   => 'session',
            'provider' => 'users',
        ],
    ]);

    if (!class_exists('Fleetbase\\FleetOps\\Support\\Utils')) {
        class_alias(Fleetbase\Support\Utils::class, 'Fleetbase\\FleetOps\\Support\\Utils');
    }
}

function category_resource_model(array $attributes): Category
{
    $category = new Category();
    $category->setRawAttributes(array_merge([
        'id'           => 1,
        'uuid'         => 'category-1',
        'public_id'    => 'cat_1',
        'company_uuid' => 'company-1',
        'owner_uuid'   => 'owner-1',
        'owner_type'   => 'company',
        'icon'         => 'box',
        'name'         => 'Operations',
        'description'  => 'Operational categories',
        'tags'         => '["ops","dispatch"]',
        'translations' => '{"es":{"name":"Operaciones"}}',
        'meta'         => '{"priority":"high"}',
        'for'          => 'orders',
        'order'        => 5,
        'slug'         => 'operations',
        'updated_at'   => Carbon::parse('2026-07-18 00:00:00'),
        'created_at'   => Carbon::parse('2026-07-17 00:00:00'),
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
        'id'          => 'permission-1',
        'name'        => 'iam view role',
        'guard_name'  => 'sanctum',
        'description' => 'Can view role',
        'service'     => 'iam',
        'updated_at'  => Carbon::parse('2026-07-18 00:00:00'),
        'created_at'  => Carbon::parse('2026-07-17 00:00:00'),
    ], $attributes), true);
    $permission->setRelation('pivot', (object) ['permission_id' => $permission->id]);

    return $permission;
}

function policy_resource_model(array $attributes = []): Policy
{
    $policy = new Policy();
    $policy->setRawAttributes(array_merge([
        'id'           => 'policy-1',
        'company_uuid' => 'company-1',
        'name'         => 'DispatchPolicy',
        'guard_name'   => 'sanctum',
        'description'  => 'Dispatch policy',
        'service'      => 'iam',
        'updated_at'   => Carbon::parse('2026-07-18 00:00:00'),
        'created_at'   => Carbon::parse('2026-07-17 00:00:00'),
    ], $attributes), true);
    $policy->setRelation('permissions', collect([
        permission_resource_model([
            'id'   => 'permission-policy',
            'name' => 'iam policy permission',
        ]),
    ]));

    return $policy;
}

function role_resource_model(array $attributes = []): Role
{
    $role = new Role();
    $role->setRawAttributes(array_merge([
        'id'           => 'role-1',
        'company_uuid' => 'company-1',
        'name'         => 'Dispatcher',
        'guard_name'   => 'sanctum',
        'description'  => 'Dispatch role',
        'service'      => 'iam',
        'updated_at'   => Carbon::parse('2026-07-18 00:00:00'),
        'created_at'   => Carbon::parse('2026-07-17 00:00:00'),
    ], $attributes), true);
    $role->setRelation('permissions', collect([
        permission_resource_model([]),
        permission_resource_model([
            'id'          => 'permission-2',
            'name'        => 'iam edit role',
            'description' => 'Can edit role',
        ]),
    ]));
    $role->setRelation('policies', collect([
        policy_resource_model(),
    ]));

    return $role;
}

function template_resource_model(array $attributes = []): TemplateModel
{
    $template = new TemplateModel();
    $template->setRawAttributes(array_merge([
        'id'                    => 77,
        'uuid'                  => 'template-uuid',
        'public_id'             => 'template_public',
        'company_uuid'          => 'company-1',
        'created_by_uuid'       => 'user-1',
        'updated_by_uuid'       => 'user-2',
        'background_image_uuid' => 'file-1',
        'name'                  => 'Invoice Template',
        'description'           => 'Printable invoice template',
        'context_type'          => 'invoice',
        'unit'                  => 'px',
        'width'                 => 800,
        'height'                => 600,
        'orientation'           => 'portrait',
        'margins'               => '{"top":12,"right":16,"bottom":12,"left":16}',
        'background_color'      => '#ffffff',
        'content'               => '[{"type":"text","value":"Invoice"}]',
        'element_schemas'       => '[{"key":"customer.name","type":"string"}]',
        'is_default'            => true,
        'is_system'             => false,
        'is_public'             => true,
        'updated_at'            => Carbon::parse('2026-07-18 00:00:00'),
        'created_at'            => Carbon::parse('2026-07-17 00:00:00'),
    ], $attributes), true);
    $template->id = 77;
    $template->setRelation('queries', collect());

    return $template;
}

function schedule_template_resource_model(array $attributes = []): ScheduleTemplateModel
{
    $template = new ScheduleTemplateModel();
    $template->setRawAttributes(array_merge([
        'uuid'           => 'schedule-template-uuid',
        'public_id'      => 'schedule_template_public',
        'company_uuid'   => 'company-1',
        'schedule_uuid'  => 'schedule-1',
        'subject_uuid'   => 'driver-1',
        'subject_type'   => 'driver',
        'name'           => 'Weekday Route',
        'description'    => 'Morning route pattern',
        'start_time'     => '08:00',
        'end_time'       => '16:00',
        'duration'       => 480,
        'break_duration' => 30,
        'rrule'          => 'FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR',
        'color'          => '#2563eb',
        'meta'           => '{"priority":"standard"}',
        'updated_at'     => Carbon::parse('2026-07-18 00:00:00'),
        'created_at'     => Carbon::parse('2026-07-17 00:00:00'),
    ], $attributes), true);

    return $template;
}

afterEach(function () {
    Container::setInstance(new FleetbaseTestContainer());
    Facade::clearResolvedInstances();
    EloquentModel::clearBootedModels();
});

test('category resource exposes public response shape with nested parent and subcategories', function () {
    resource_contract_container();

    $parent = category_resource_model([
        'id'           => 10,
        'uuid'         => 'category-parent',
        'public_id'    => 'cat_parent',
        'name'         => 'Parent Category',
        'tags'         => '[]',
        'translations' => '[]',
        'meta'         => null,
    ]);
    $child = category_resource_model([
        'id'           => 11,
        'uuid'         => 'category-child',
        'public_id'    => 'cat_child',
        'name'         => 'Child Category',
        'tags'         => '[]',
        'translations' => '[]',
        'meta'         => null,
    ]);
    $category = category_resource_model([]);
    $category->setRelation('parentCategory', $parent);
    $category->setRelation('subCategories', collect([$child]));

    $payload = (new CategoryResource($category))->resolve(resource_contract_request('/v1/categories/cat_1', [
        'with_parent'        => true,
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
        'id'        => 10,
        'uuid'      => 'category-parent',
        'public_id' => 'cat_parent',
        'name'      => 'Parent Category',
    ]);
    $child = category_resource_model([
        'id'        => 11,
        'uuid'      => 'category-child',
        'public_id' => 'cat_child',
        'name'      => 'Child Category',
    ]);
    $category = category_resource_model([]);
    $category->setRelation('parentCategory', $parent);
    $category->setRelation('subCategories', collect([$child]));

    $payload = (new CategoryResource($category, [
        'without_parent'        => true,
        'without_subcategories' => true,
    ]))->resolve(resource_contract_request('/int/v1/categories/category-1', [
        'with_parent'        => true,
        'with_subcategories' => true,
    ]));

    expect($payload['id'])->toBe(1)
        ->and($payload['uuid'])->toBe('category-1')
        ->and($payload['public_id'])->toBe('cat_1')
        ->and($payload['company_uuid'])->toBe('company-1')
        ->and($payload['owner_uuid'])->toBe('owner-1')
        ->and($payload['owner_type'])->toBe('company')
        ->and($payload)->not->toHaveKeys(['parent', 'subcategories']);

    $withParent = (new CategoryResource($category))->resolve(resource_contract_request('/int/v1/categories/category-1', [
        'with_parent' => true,
    ]));

    expect($withParent['parent']['id'])->toBe(10)
        ->and($withParent['parent']['uuid'])->toBe('category-parent')
        ->and($withParent['parent']['public_id'])->toBe('cat_parent');
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
            'id'          => 'permission-1',
            'name'        => 'iam view role',
            'guard_name'  => 'sanctum',
            'description' => 'Can view role',
            'service'     => 'iam',
        ])
        ->and($payload['policies'][0]['id'])->toBe('policy-1')
        ->and($payload['policies'][0]['permissions'][0]['id'])->toBe('permission-policy');
});

test('role resource identifies fleetbase managed roles as immutable and non deletable', function () {
    resource_contract_container();

    $payload = (new RoleResource(role_resource_model([
        'id'           => 'role-managed',
        'company_uuid' => null,
        'name'         => 'Administrator',
    ])))->resolve(resource_contract_request('/int/v1/roles/role-managed'));

    expect($payload['id'])->toBe('role-managed')
        ->and($payload['company_uuid'])->toBeNull()
        ->and($payload['type'])->toBe('FLB Managed')
        ->and($payload['is_mutable'])->toBeFalse()
        ->and($payload['is_deletable'])->toBeFalse();
});

test('policy resource serializes permissions and mutability metadata directly', function () {
    resource_contract_container();

    $payload = (new PolicyResource(policy_resource_model([
        'id'           => 'policy-direct',
        'company_uuid' => null,
    ])))->resolve(resource_contract_request('/int/v1/policies/policy-direct'));

    expect($payload['id'])->toBe('policy-direct')
        ->and($payload['company_uuid'])->toBeNull()
        ->and($payload['name'])->toBe('DispatchPolicy')
        ->and($payload['guard_name'])->toBe('sanctum')
        ->and($payload['type'])->toBe('FLB Managed')
        ->and($payload['is_mutable'])->toBeFalse()
        ->and($payload['is_deletable'])->toBeFalse()
        ->and($payload['permissions'])->toHaveCount(1)
        ->and($payload['permissions'][0])->toMatchArray([
            'id'          => 'permission-policy',
            'name'        => 'iam policy permission',
            'guard_name'  => 'sanctum',
            'description' => 'Can view role',
            'service'     => 'iam',
        ]);
});

test('template resource switches internal identifiers for public response shape', function () {
    resource_contract_container();

    $template = template_resource_model();

    $internal = (new TemplateResource($template))->resolve(resource_contract_request('/int/v1/templates/template_public'));
    $public   = (new TemplateResource($template))->resolve(resource_contract_request('/v1/templates/template_public'));

    expect($internal['id'])->toBe(77)
        ->and($internal['uuid'])->toBe('template-uuid')
        ->and($internal['public_id'])->toBe('template_public')
        ->and($internal['company_uuid'])->toBe('company-1')
        ->and($internal['created_by_uuid'])->toBe('user-1')
        ->and($internal['background_image_uuid'])->toBe('file-1')
        ->and($internal['name'])->toBe('Invoice Template')
        ->and($internal['margins'])->toBe(['top' => 12, 'right' => 16, 'bottom' => 12, 'left' => 16])
        ->and($internal['content'])->toBe([['type' => 'text', 'value' => 'Invoice']])
        ->and($internal['element_schemas'])->toBe([['key' => 'customer.name', 'type' => 'string']])
        ->and($internal['queries'])->toBeInstanceOf(Fleetbase\Http\Resources\FleetbaseResourceCollection::class)
        ->and($internal['queries']->collection->isEmpty())->toBeTrue()
        ->and($internal['is_default'])->toBeTrue()
        ->and($public['id'])->toBe('template_public')
        ->and($public)->not->toHaveKeys(['uuid', 'public_id', 'company_uuid', 'created_by_uuid', 'updated_by_uuid', 'background_image_uuid'])
        ->and($public['name'])->toBe('Invoice Template')
        ->and($public['queries'])->toBeInstanceOf(Fleetbase\Http\Resources\FleetbaseResourceCollection::class)
        ->and($public['queries']->collection->isEmpty())->toBeTrue();
});

test('schedule template resource delegates to fleetbase resource serialization', function () {
    resource_contract_container();

    $payload = (new ScheduleTemplateResource(schedule_template_resource_model()))
        ->resolve(resource_contract_request('/int/v1/schedule-templates/schedule_template_public'));

    expect($payload)->toMatchArray([
        'uuid'           => 'schedule-template-uuid',
        'public_id'      => 'schedule_template_public',
        'company_uuid'   => 'company-1',
        'schedule_uuid'  => 'schedule-1',
        'subject_uuid'   => 'driver-1',
        'subject_type'   => 'driver',
        'name'           => 'Weekday Route',
        'duration'       => 480,
        'break_duration' => 30,
        'rrule'          => 'FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR',
        'meta'           => ['priority' => 'standard'],
    ]);
});

test('compressed json resource uses response factory compression contract', function () {
    resource_contract_container();

    $factory = new ResourceContractCompressedResponseFactory();
    Response::swap($factory);

    $response = (new CompressedJsonResource(['status' => 'ok', 'count' => 2]))
        ->toResponse(resource_contract_request('/int/v1/compressed'));

    expect($response->getData(true))->toBe(['compressed' => ['status' => 'ok', 'count' => 2]])
        ->and($factory->payloads)->toBe([['status' => 'ok', 'count' => 2]]);
});

test('file resolver facade resolves the package file resolver service binding', function () {
    $accessor = new ReflectionMethod(FileResolverFacade::class, 'getFacadeAccessor');
    $accessor->setAccessible(true);

    expect($accessor->invoke(null))->toBe(FileResolverService::class);
});

test('paginated resource response keeps fleetbase pagination metadata compact with timing', function () {
    resource_contract_container();

    $request = resource_contract_request('/int/v1/resources');
    $request->attributes->set('request_start_time', microtime(true) - 0.042);

    $resource = new class {
        public object $resource;

        public function __construct()
        {
            $this->resource = new class {
                public function toArray(): array
                {
                    return [
                        'current_page'   => 2,
                        'data'           => [['id' => 'one']],
                        'first_page_url' => 'https://fleetbase.test/resources?page=1',
                        'from'           => 11,
                        'last_page'      => 4,
                        'last_page_url'  => 'https://fleetbase.test/resources?page=4',
                        'next_page_url'  => 'https://fleetbase.test/resources?page=3',
                        'path'           => 'https://fleetbase.test/resources',
                        'per_page'       => 10,
                        'prev_page_url'  => 'https://fleetbase.test/resources?page=1',
                        'to'             => 20,
                        'total'          => 35,
                    ];
                }
            };
        }
    };

    $response = new FleetbasePaginatedResourceResponse($resource);
    $method   = new ReflectionMethod($response, 'paginationInformation');
    $method->setAccessible(true);

    $pagination = $method->invoke($response, $request);

    expect($pagination)->toHaveKey('meta')
        ->and($pagination)->not->toHaveKey('links')
        ->and($pagination['meta']['total'])->toBe(35)
        ->and($pagination['meta']['per_page'])->toBe(10)
        ->and($pagination['meta']['current_page'])->toBe(2)
        ->and($pagination['meta']['last_page'])->toBe(4)
        ->and($pagination['meta']['from'])->toBe(11)
        ->and($pagination['meta']['to'])->toBe(20)
        ->and($pagination['meta']['time'])->toBeGreaterThanOrEqual(0);

    $customResource = new class {
        public object $resource;

        public function __construct()
        {
            $this->resource = new class {
                public function toArray(): array
                {
                    return [
                        'current_page' => 1,
                        'data'         => [],
                        'from'         => null,
                        'last_page'    => 1,
                        'per_page'     => 10,
                        'to'           => null,
                        'total'        => 0,
                    ];
                }
            };
        }

        public function paginationInformation(Request $request, array $paginated, array $default): array
        {
            return [
                'meta' => $default['meta'] + [
                    'custom' => $request->query('custom'),
                    'empty'  => $paginated['total'] === 0,
                ],
            ];
        }
    };

    $customRequest = resource_contract_request('/int/v1/resources', ['custom' => 'enabled']);
    $customRequest->attributes->set('request_start_time', microtime(true));

    $customResponse = new FleetbasePaginatedResourceResponse($customResource);
    $customMethod   = new ReflectionMethod($customResponse, 'paginationInformation');
    $customMethod->setAccessible(true);

    $customPagination = $customMethod->invoke($customResponse, $customRequest);

    expect($customPagination['meta']['custom'])->toBe('enabled')
        ->and($customPagination['meta']['empty'])->toBeTrue()
        ->and($customPagination['meta']['total'])->toBe(0);
});

test('author resource hides internal identifiers from public responses', function () {
    resource_contract_container();

    $author = new class extends EloquentModel {
        protected $guarded = [];
    };
    $author->setRawAttributes([
        'id'           => 12,
        'uuid'         => 'author-uuid',
        'public_id'    => 'author_public',
        'company_uuid' => 'company-1',
        'avatar_uuid'  => 'file-1',
        'name'         => 'Ada Author',
        'email'        => 'ada@example.test',
        'phone'        => '+15555550123',
        'country'      => 'SG',
        'avatar_url'   => 'https://cdn.test/avatar.png',
        'company_name' => 'Acme Logistics',
        'is_admin'     => false,
        'timezone'     => 'Asia/Singapore',
        'updated_at'   => Carbon::parse('2026-07-18 00:00:00'),
        'created_at'   => Carbon::parse('2026-07-17 00:00:00'),
    ], true);
    $author->id = 12;

    $internal = (new AuthorResource($author))->resolve(resource_contract_request('/int/v1/users/author_public'));
    $public   = (new AuthorResource($author))->resolve(resource_contract_request('/v1/users/author_public'));

    expect($internal['id'])->toBe(12)
        ->and($internal['uuid'])->toBe('author-uuid')
        ->and($internal['public_id'])->toBe('author_public')
        ->and($internal['company_uuid'])->toBe('company-1')
        ->and($internal['avatar_uuid'])->toBe('file-1')
        ->and($internal['name'])->toBe('Ada Author')
        ->and($internal['avatar_url'])->toBe('https://cdn.test/avatar.png')
        ->and($internal['company_name'])->toBe('Acme Logistics')
        ->and($public['id'])->toBe('author_public')
        ->and($public)->not->toHaveKeys(['uuid', 'public_id', 'company_uuid', 'avatar_uuid'])
        ->and($public['email'])->toBe('ada@example.test')
        ->and($public['timezone'])->toBe('Asia/Singapore');
});

test('chat attachment resource maps internal ids and public related ids correctly', function () {
    resource_contract_container();

    $attachment = new class extends EloquentModel {
        protected $guarded = [];
    };
    $attachment->setRawAttributes([
        'id'                => 91,
        'uuid'              => 'attachment-uuid',
        'public_id'         => 'attachment_public',
        'chat_channel_uuid' => 'channel-uuid',
        'chat_message_uuid' => 'message-uuid',
        'file_uuid'         => 'file-uuid',
        'updated_at'        => Carbon::parse('2026-07-18 00:00:00'),
        'created_at'        => Carbon::parse('2026-07-17 00:00:00'),
        'deleted_at'        => null,
    ], true);
    $attachment->id = 91;
    $attachment->setRelation('chatChannel', (object) ['public_id' => 'channel_public']);
    $attachment->setRelation('message', (object) ['public_id' => 'message_public']);
    $attachment->setRelation('file', (object) [
        'public_id'         => 'file_public',
        'url'               => 'https://cdn.test/file.pdf',
        'original_filename' => 'file.pdf',
        'content_type'      => 'application/pdf',
    ]);

    $internal = (new ChatAttachmentResource($attachment))->resolve(resource_contract_request('/int/v1/chat-attachments/attachment_public'));
    $public   = (new ChatAttachmentResource($attachment))->resolve(resource_contract_request('/v1/chat-attachments/attachment_public'));

    expect($internal['id'])->toBe(91)
        ->and($internal['uuid'])->toBe('attachment-uuid')
        ->and($internal['chat_channel_uuid'])->toBe('channel-uuid')
        ->and($internal['chat_message_uuid'])->toBe('message-uuid')
        ->and($internal['file_uuid'])->toBe('file-uuid')
        ->and($internal['url'])->toBe('https://cdn.test/file.pdf')
        ->and($internal['filename'])->toBe('file.pdf')
        ->and($public['id'])->toBe('attachment_public')
        ->and($public['chat_channel'])->toBe('channel_public')
        ->and($public['chat_message'])->toBe('message_public')
        ->and($public['file'])->toBe('file_public')
        ->and($public)->not->toHaveKeys(['uuid', 'chat_channel_uuid', 'chat_message_uuid', 'file_uuid']);
});

test('deleted resource keeps internal deletion shape and compact webhook payload', function () {
    resource_contract_container();

    $deleted = new Category();
    $deleted->setRawAttributes([
        'id'         => 44,
        'uuid'       => 'category-uuid',
        'public_id'  => 'category_public',
        'deleted_at' => Carbon::parse('2026-07-18 08:30:00'),
    ], true);
    $deleted->id = 44;

    $internalResource = new DeletedResource($deleted);
    $internal         = $internalResource->resolve(resource_contract_request('/int/v1/categories/category_public'));
    $public           = (new DeletedResource($deleted))->resolve(resource_contract_request('/v1/categories/category_public'));
    $webhook          = $internalResource->toWebhookPayload();

    expect($internal['id'])->toBe(44)
        ->and($internal['uuid'])->toBe('category-uuid')
        ->and($internal['public_id'])->toBe('category_public')
        ->and($internal['object'])->toBe('category')
        ->and($internal['deleted'])->toBeTrue()
        ->and($public['id'])->toBe('category_public')
        ->and($public)->not->toHaveKeys(['uuid', 'public_id'])
        ->and($webhook)->toMatchArray([
            'id'      => 'category_public',
            'object'  => 'category',
            'deleted' => true,
        ]);
});
