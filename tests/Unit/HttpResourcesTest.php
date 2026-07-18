<?php

use Fleetbase\Http\Requests\Internal\UserForgotPasswordRequest;
use Fleetbase\Http\Requests\Internal\WebhookEndpointRequest;
use Fleetbase\Http\Requests\LoginRequest;
use Fleetbase\Http\Resources\File as FileResource;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Http\Resources\Report as ReportResource;
use Fleetbase\Http\Resources\TemplateQuery as TemplateQueryResource;
use Fleetbase\Http\Resources\User as UserResource;
use Fleetbase\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ResourceRouteFixture
{
    public function __construct(private string $uri, public array $action = [])
    {
    }

    public function uri(): string
    {
        return $this->uri;
    }
}

class UserResourceFixtureModel extends Model
{
    public function getLocale(): string
    {
        return 'en';
    }

    public function getResource(): ?string
    {
        return null;
    }
}

class ReportResourceFixtureModel extends Model
{
    public function getResource(): ?string
    {
        return null;
    }
}

class FileResourceFixtureModel extends Model
{
    public function getResource(): ?string
    {
        return null;
    }
}

function resource_request(string $uri): Request
{
    $request = Request::create('/' . ltrim($uri, '/'));
    $request->setRouteResolver(fn () => new ResourceRouteFixture($uri));
    Illuminate\Container\Container::getInstance()->instance('request', $request);

    return $request;
}

beforeEach(function () {
    bind_test_container();
});

test('user resource exposes internal identity and permission response shape', function () {
    $user = new UserResourceFixtureModel();
    $user->setRawAttributes([
        'uuid'         => 'user_uuid',
        'public_id'    => 'user_public',
        'company_uuid' => 'company_uuid',
        'name'         => 'Ron',
        'username'     => 'ron',
        'email'        => 'ron@example.com',
        'phone'        => '+15555555555',
        'timezone'     => 'Asia/Ulaanbaatar',
        'type'         => 'admin',
        'status'       => 'active',
        'meta'         => ['theme' => 'dark'],
    ], true);
    $user->id = 99;
    $user->setRelation('role', null);
    $user->setRelation('company', null);
    $user->setRelation('policies', new Collection());
    $user->setRelation('permissions', new Collection([
        (object) [
            'pivot'       => (object) ['permission_id' => 7],
            'name'        => 'users.manage',
            'guard_name'  => 'sanctum',
            'description' => 'Manage users',
            'updated_at'  => 'updated',
            'created_at'  => 'created',
        ],
    ]));

    $internal = (new UserResource($user))->resolve(resource_request('int/v1/users/user_public'));
    $public   = (new UserResource($user))->resolve(resource_request('v1/users/user_public'));

    expect($internal['id'])->toBe(99)
        ->and($internal['uuid'])->toBe('user_uuid')
        ->and($internal['company_uuid'])->toBe('company_uuid')
        ->and($internal['permissions'][0]['name'])->toBe('users.manage')
        ->and($public['id'])->toBe('user_public')
        ->and($public)->not->toHaveKey('uuid')
        ->and($public['permissions'])->toBe([]);
});

test('file report and template query resources preserve api response contracts', function () {
    $file = new FileResourceFixtureModel();
    $file->setRawAttributes([
        'id'                => 5,
        'uuid'              => 'file_uuid',
        'public_id'         => 'file_public',
        'company_uuid'      => 'company_uuid',
        'url'               => 'https://cdn.test/file.png',
        'original_filename' => 'file.png',
        'folder'            => 'uploads',
        'content_type'      => 'image/png',
        'file_size'         => 1024,
        'caption'           => 'Logo',
        'type'              => 'image',
        'meta'              => ['width' => 100],
        'updated_at'        => null,
        'created_at'        => null,
    ], true);
    $file->id = 5;

    $report = new ReportResourceFixtureModel();
    $report->setRawAttributes([
        'uuid'                 => 'report_uuid',
        'public_id'            => 'report_public',
        'title'                => 'Daily report',
        'type'                 => 'operations',
        'status'               => 'ready',
        'subject_type'         => User::class,
        'subject_uuid'         => 'user_subject',
        'subject_name'         => 'Subject User',
        'period_start'         => Carbon::parse('2026-07-01'),
        'period_end'           => Carbon::parse('2026-07-17'),
        'period_duration_days' => 16,
        'query_config'         => ['table' => ['name' => 'orders']],
        'result_columns'       => [['key' => 'public_id']],
        'last_executed_at'     => Carbon::parse('2026-07-17 11:30:00'),
        'execution_time'       => 123.45,
        'row_count'            => 10,
        'is_scheduled'         => true,
        'schedule_config'      => ['frequency' => 'daily'],
        'export_formats'       => ['csv', 'xlsx'],
        'is_generated'         => true,
        'tags'                 => ['ops'],
        'meta'                 => ['source' => 'test'],
        'options'              => ['notify' => true],
        'body'                 => 'Report body',
        'data'                 => [['public_id' => 'order_123']],
        'has_valid_query'      => true,
        'updated_at'           => Carbon::parse('2026-07-18 10:00:00'),
        'created_at'           => Carbon::parse('2026-07-17 10:00:00'),
    ], true);
    $report->id = 6;
    $report->setRelation('createdBy', (object) ['uuid' => 'user_created', 'name' => 'Creator', 'email' => 'creator@example.com']);
    $report->setRelation('updatedBy', (object) ['uuid' => 'user_updated', 'name' => 'Updater', 'email' => 'updater@example.com']);
    $report->setRelation('subject', new User(['uuid' => 'user_subject', 'name' => 'Subject User']));

    $templateQuery = (object) [
        'id'            => 7,
        'uuid'          => 'query_uuid',
        'public_id'     => 'query_public',
        'company_uuid'  => 'company_uuid',
        'template_uuid' => 'template_uuid',
        'model_type'    => User::class,
        'variable_name' => 'users',
        'label'         => 'Users',
        'conditions'    => [['field' => 'status', 'operator' => 'eq', 'value' => 'active']],
        'sort'          => ['created_at' => 'desc'],
        'limit'         => 25,
        'with'          => ['role'],
        'updated_at'    => null,
        'created_at'    => null,
    ];
    $templateQuery->id = 7;

    $request = resource_request('int/v1/reports/report_public');

    $fileData   = (new FileResource($file))->resolve($request);
    $reportData = (new ReportResource($report))->resolve($request);
    $queryData  = (new TemplateQueryResource($templateQuery))->resolve($request);

    expect($fileData['id'])->toBe(5)
        ->and($fileData['url'])->toBe('https://cdn.test/file.png')
        ->and($fileData['meta'])->toBe(['width' => 100])
        ->and($reportData['id'])->toBe(6)
        ->and($reportData['title'])->toBe('Daily report')
        ->and($reportData['subject_type'])->toBe(User::class)
        ->and($reportData['subject_uuid'])->toBe('user_subject')
        ->and($reportData['period_duration_days'])->toBe(16)
        ->and($reportData['schedule_config'])->toBe(['frequency' => 'daily'])
        ->and($reportData['export_formats'])->toBe(['csv', 'xlsx'])
        ->and($reportData['tags'])->toBe(['ops'])
        ->and($reportData['meta'])->toBe(['source' => 'test'])
        ->and($reportData['options'])->toBe(['notify' => true])
        ->and($reportData['body'])->toBe('Report body')
        ->and($reportData['data'])->toBe([['public_id' => 'order_123']])
        ->and($reportData['created_by'])->toBe(['uuid' => 'user_created', 'name' => 'Creator', 'email' => 'creator@example.com'])
        ->and($reportData['updated_by'])->toBe(['uuid' => 'user_updated', 'name' => 'Updater', 'email' => 'updater@example.com'])
        ->and($reportData['subject'])->toBe(['type' => User::class, 'uuid' => 'user_subject', 'name' => 'Subject User'])
        ->and($reportData['period_start'])->toBe('2026-07-01T00:00:00.000000Z')
        ->and($reportData['last_executed_at'])->toBe('2026-07-17T11:30:00.000000Z')
        ->and((new ReportResource($report))->with($request))->toBe([
            'meta' => [
                'can_execute'   => true,
                'can_export'    => true,
                'can_schedule'  => true,
                'last_activity' => '2026-07-18T10:00:00.000000Z',
            ],
        ])
        ->and($queryData['id'])->toBe(7)
        ->and($queryData['model_type'])->toBe(User::class)
        ->and($queryData['conditions'])->toHaveCount(1)
        ->and($queryData['with'])->toBe(['role']);
});

test('fleetbase resource can omit nested keys without mutating the original resource', function () {
    $resource = new FleetbaseResource([
        'id'   => 'one',
        'meta' => [
            'secret' => 'hidden',
            'safe'   => 'visible',
        ],
    ]);

    $filtered = $resource->without('meta.secret')->resolve(resource_request('int/v1/test'));
    $original = $resource->resolve(resource_request('int/v1/test'));

    expect($filtered)->toBe(['id' => 'one', 'meta' => ['safe' => 'visible']])
        ->and($original)->toBe(['id' => 'one', 'meta' => ['secret' => 'hidden', 'safe' => 'visible']]);
});

test('auth and webhook request classes keep validation and authorization contracts', function () {
    session(['company' => 'company_uuid']);

    $loginRules    = (new LoginRequest())->rules();
    $loginMessages = (new LoginRequest())->messages();

    $createWebhook = WebhookEndpointRequest::create('/int/v1/webhook-endpoints', 'POST');
    $updateWebhook = WebhookEndpointRequest::create('/int/v1/webhook-endpoints/webhook_123', 'PUT');

    expect($loginRules['identity'])->toBe(['required'])
        ->and($loginRules['password'])->toBe(['required'])
        ->and($loginMessages['identity.required'])->toBe('An email address or phone number is required.')
        ->and((new UserForgotPasswordRequest())->authorize())->toBeTrue()
        ->and((new UserForgotPasswordRequest())->rules()['email'])->toBe(['required', 'email'])
        ->and($createWebhook->authorize())->toBe('company_uuid')
        ->and($createWebhook->rules()['url'][0])->toBe('required')
        ->and($updateWebhook->rules()['url'][0])->toBe('sometimes')
        ->and($createWebhook->messages()['url.required'])->toBe('A webhook URL is required.');
});
