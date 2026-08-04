<?php

use Fleetbase\Models\Category;
use Fleetbase\Models\Company;
use Fleetbase\Models\CustomField;
use Fleetbase\Models\CustomFieldValue;
use Fleetbase\Models\Report;
use Fleetbase\Models\ReportAuditLog;
use Fleetbase\Models\ReportExecution;
use Fleetbase\Models\User;
use Fleetbase\Models\UserDevice;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;

class RecordModelsCacheFake
{
    private array $values = [];

    public function tags(array|string $tags): self
    {
        return $this;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function put(string $key, mixed $value, mixed $ttl = null): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function forget(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function increment(string $key, int $value = 1): int
    {
        $this->values[$key] = ($this->values[$key] ?? 0) + $value;

        return $this->values[$key];
    }

    public function flush(): bool
    {
        $this->values = [];

        return true;
    }
}

function record_models_database(): Capsule
{
    EloquentModel::clearBootedModels();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'api.cache.enabled'          => false,
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
        'fleetbase.connection.db'    => 'mysql',
    ]);
    $container->instance('cache', new RecordModelsCacheFake());
    $container->instance('responsecache', new class {
        public function clear(): bool
        {
            return true;
        }
    });
    Facade::clearResolvedInstance('cache');
    Facade::clearResolvedInstance('responsecache');
    session()->flush();

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('report_audit_logs', function ($table) {
        $table->string('uuid')->primary();
        $table->string('report_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->string('action')->nullable();
        $table->float('execution_time')->nullable();
        $table->integer('result_count')->nullable();
        $table->text('error_message')->nullable();
        $table->text('query_config')->nullable();
        $table->string('ip_address')->nullable();
        $table->text('user_agent')->nullable();
        $table->text('metadata')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('report_executions', function ($table) {
        $table->string('uuid')->primary();
        $table->string('report_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->float('execution_time')->nullable();
        $table->integer('result_count')->nullable();
        $table->text('query_config')->nullable();
        $table->string('status')->nullable();
        $table->text('error_message')->nullable();
        $table->timestamp('started_at')->nullable();
        $table->timestamp('completed_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('user_devices', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->unique();
        $table->string('user_uuid')->nullable();
        $table->string('platform')->nullable();
        $table->text('token')->nullable();
        $table->string('status')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    return $capsule;
}

it('casts report audit logs and filters execution and export actions', function () {
    $capsule = record_models_database();

    $audit = new ReportAuditLog([
        'report_uuid'    => 'report-1',
        'user_uuid'      => 'user-1',
        'action'         => 'execute',
        'execution_time' => '12.75',
        'result_count'   => '42',
        'query_config'   => ['table' => ['name' => 'orders']],
        'metadata'       => ['ip_source' => 'proxy'],
        'ip_address'     => '203.0.113.5',
        'user_agent'     => 'Fleetbase Console',
    ]);

    $capsule->getConnection('mysql')->table('report_audit_logs')->insert([
        [
            'uuid'           => 'audit-1',
            'action'         => 'execute',
            'execution_time' => 10.5,
            'result_count'   => 3,
            'query_config'   => json_encode(['table' => 'orders']),
            'metadata'       => json_encode(['format' => 'json']),
            'created_at'     => '2026-07-17 10:00:00',
            'updated_at'     => '2026-07-17 10:00:00',
        ],
        [
            'uuid'           => 'audit-2',
            'action'         => 'export',
            'execution_time' => 20.25,
            'result_count'   => 8,
            'query_config'   => json_encode(['table' => 'reports']),
            'metadata'       => json_encode(['format' => 'csv']),
            'created_at'     => '2026-07-17 10:00:00',
            'updated_at'     => '2026-07-17 10:00:00',
        ],
        [
            'uuid'           => 'audit-3',
            'action'         => 'view',
            'execution_time' => 1.5,
            'result_count'   => 1,
            'query_config'   => json_encode(['table' => 'templates']),
            'metadata'       => json_encode(['format' => 'html']),
            'created_at'     => '2026-07-17 10:00:00',
            'updated_at'     => '2026-07-17 10:00:00',
        ],
    ]);

    expect($audit->execution_time)->toBe(12.75)
        ->and($audit->result_count)->toBe(42)
        ->and($audit->query_config)->toBe(['table' => ['name' => 'orders']])
        ->and($audit->metadata)->toBe(['ip_source' => 'proxy'])
        ->and(ReportAuditLog::query()->executions()->pluck('uuid')->all())->toBe(['audit-1'])
        ->and(ReportAuditLog::query()->exports()->pluck('uuid')->all())->toBe(['audit-2'])
        ->and(ReportAuditLog::query()->action('view')->pluck('uuid')->all())->toBe(['audit-3']);
});

it('casts report execution timings result metadata and relationship keys', function () {
    record_models_database();
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00', 'UTC'));

    $execution = new ReportExecution([
        'report_uuid'    => 'report-1',
        'user_uuid'      => 'user-1',
        'execution_time' => '33.25',
        'result_count'   => '900',
        'query_config'   => ['filters' => [['field' => 'status', 'value' => 'active']]],
        'status'         => 'completed',
        'started_at'     => '2026-07-17 11:59:00',
        'completed_at'   => '2026-07-17 12:00:00',
    ]);

    expect($execution->execution_time)->toBe(33.25)
        ->and($execution->result_count)->toBe(900)
        ->and($execution->query_config)->toBe(['filters' => [['field' => 'status', 'value' => 'active']]])
        ->and($execution->started_at->toISOString())->toBe('2026-07-17T11:59:00.000000Z')
        ->and($execution->completed_at->toISOString())->toBe('2026-07-17T12:00:00.000000Z')
        ->and($execution->report()->getForeignKeyName())->toBe('report_uuid')
        ->and($execution->report()->getOwnerKeyName())->toBe('uuid')
        ->and($execution->report()->getRelated())->toBeInstanceOf(Report::class)
        ->and($execution->user()->getForeignKeyName())->toBe('user_uuid')
        ->and($execution->user()->getRelated())->toBeInstanceOf(User::class);

    Carbon::setTestNow();
});

it('generates user device public ids and preserves response-visible token metadata', function () {
    record_models_database();

    $device = UserDevice::query()->create([
        'uuid'      => 'device-1',
        'user_uuid' => 'user-1',
        'platform'  => 'ios',
        'token'     => 'apns-token',
        'status'    => 'active',
    ]);

    expect($device->public_id)->toStartWith('user_device_')
        ->and($device->public_id)->toHaveLength(strlen('user_device_') + 10)
        ->and($device->token)->toBe('apns-token')
        ->and($device->uuid)->not->toBe('device-1')
        ->and($device->toArray())->toMatchArray([
            'user_uuid' => 'user-1',
            'platform'  => 'ios',
            'token'     => 'apns-token',
            'status'    => 'active',
        ]);
});

it('casts custom field configuration values and keeps relationship keys stable', function () {
    record_models_database();

    $field = new CustomField([
        'company_uuid'     => 'company-1',
        'category_uuid'    => 'category-1',
        'subject_uuid'     => 'user-1',
        'subject_type'     => User::class,
        'name'             => 'delivery-window',
        'label'            => 'Delivery Window',
        'type'             => 'select',
        'options'          => ['morning', 'afternoon'],
        'required'         => 1,
        'editable'         => 0,
        'validation_rules' => ['required', 'string'],
        'meta'             => ['ui' => ['width' => 'half']],
    ]);

    expect($field->options)->toBe(['morning', 'afternoon'])
        ->and($field->required)->toBeTrue()
        ->and($field->editable)->toBeFalse()
        ->and($field->validation_rules)->toBe(['required', 'string'])
        ->and($field->meta)->toBe(['ui' => ['width' => 'half']])
        ->and($field->subject_type)->toBe(User::class)
        ->and($field->subject()->getMorphType())->toBe('subject_type')
        ->and($field->subject()->getForeignKeyName())->toBe('subject_uuid')
        ->and($field->company()->getForeignKeyName())->toBe('company_uuid')
        ->and($field->company()->getRelated())->toBeInstanceOf(Company::class)
        ->and($field->category()->getForeignKeyName())->toBe('category_uuid')
        ->and($field->category()->getRelated())->toBeInstanceOf(Category::class);
});

it('casts custom field values by type and exposes loaded custom field labels', function () {
    record_models_database();

    $field = new CustomField();
    $field->setRawAttributes([
        'uuid'  => 'field-1',
        'label' => 'Delivery Metadata',
    ], true);

    $objectValue = new CustomFieldValue([
        'company_uuid'      => 'company-1',
        'custom_field_uuid' => 'field-1',
        'subject_uuid'      => 'user-1',
        'subject_type'      => User::class,
        'value_type'        => 'object',
        'value'             => ['window' => 'morning', 'priority' => true],
    ]);
    $objectValue->setRelation('customField', $field);

    $textValue = new CustomFieldValue([
        'value_type' => 'text',
        'value'      => 'plain text',
    ]);

    expect($objectValue->getAttributes()['value'])->toBe(json_encode(['window' => 'morning', 'priority' => true]))
        ->and($objectValue->value)->toBe(['window' => 'morning', 'priority' => true])
        ->and($objectValue->subject_type)->toBe(User::class)
        ->and($objectValue->custom_field_label)->toBe('Delivery Metadata')
        ->and($objectValue->subject()->getMorphType())->toBe('subject_type')
        ->and($objectValue->subject()->getForeignKeyName())->toBe('subject_uuid')
        ->and($objectValue->company()->getForeignKeyName())->toBe('company_uuid')
        ->and($objectValue->customField()->getForeignKeyName())->toBe('custom_field_uuid')
        ->and($objectValue->customField()->getOwnerKeyName())->toBe('uuid')
        ->and($textValue->value)->toBe('plain text')
        ->and($textValue->custom_field_label)->toBeNull();
});
