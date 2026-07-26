<?php

use Fleetbase\Models\CustomField;
use Fleetbase\Models\CustomFieldValue;
use Fleetbase\Traits\HasCustomFields;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

class HasCustomFieldsSubject extends Model
{
    use HasCustomFields;

    protected $connection = 'testing';
    protected $table      = 'custom_field_subjects';
    protected $primaryKey = 'uuid';
    protected $keyType    = 'string';
    public $incrementing  = false;
    public $timestamps    = false;
    protected $guarded    = [];
}

class HasCustomFieldsRoute
{
    public array $action = [];

    public function __construct(private string $uri, string $namespace = '')
    {
        $this->action = ['namespace' => $namespace];
    }

    public function uri(): string
    {
        return $this->uri;
    }
}

class HasCustomFieldsCacheFake
{
    public function tags(array $tags): self
    {
        return $this;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function put(string $key, mixed $value, mixed $ttl = null): bool
    {
        return true;
    }

    public function forget(string $key): bool
    {
        return true;
    }

    public function increment(string $key): int
    {
        return 1;
    }

    public function flush(): bool
    {
        return true;
    }
}

class HasCustomFieldsResponseCacheFake
{
    public function clear(): bool
    {
        return true;
    }
}

function has_custom_fields_database(string $routeUri = 'int/v1/subjects'): HasCustomFieldsSubject
{
    $connectionConfig = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'database.default'             => 'testing',
        'database.connections.testing' => $connectionConfig,
        'fleetbase.connection.db'      => 'testing',
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connectionConfig, 'testing');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('testing');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');
    $container->instance('cache', new HasCustomFieldsCacheFake());
    Facade::clearResolvedInstance('cache');
    $container->instance('responsecache', new HasCustomFieldsResponseCacheFake());
    Facade::clearResolvedInstance('ResponseCache');

    $request = Request::create('/' . $routeUri, 'GET');
    $request->setRouteResolver(fn () => new HasCustomFieldsRoute($routeUri, str_contains($routeUri, 'int/') ? 'Fleetbase\Http\Controllers\Internal\v1' : ''));
    $container->instance('request', $request);

    $schema = $capsule->getConnection('testing')->getSchemaBuilder();
    $schema->create('custom_field_subjects', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
    });
    $schema->create('custom_fields', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('category_uuid')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('name')->nullable();
        $table->string('label')->nullable();
        $table->string('type')->nullable();
        $table->string('for')->nullable();
        $table->string('component')->nullable();
        $table->text('options')->nullable();
        $table->boolean('required')->default(false);
        $table->boolean('editable')->default(true);
        $table->text('default_value')->nullable();
        $table->text('validation_rules')->nullable();
        $table->text('meta')->nullable();
        $table->text('description')->nullable();
        $table->text('help_text')->nullable();
        $table->integer('order')->default(0);
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('custom_field_values', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('custom_field_uuid')->nullable();
        $table->string('custom_field_id')->nullable();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->text('value')->nullable();
        $table->string('value_type')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    $subject = new HasCustomFieldsSubject([
        'uuid'         => 'subject-1',
        'company_uuid' => 'company-1',
        'name'         => 'Subject One',
    ]);
    $subject->save();

    return $subject;
}

function has_custom_fields_field(HasCustomFieldsSubject $subject, string $uuid, string $name, string $label, int $order = 0): CustomField
{
    $field = new CustomField();
    $field->forceFill([
        'uuid'         => $uuid,
        'company_uuid' => $subject->company_uuid,
        'subject_uuid' => $subject->uuid,
        'subject_type' => $subject->getMorphClass(),
        'name'         => $name,
        'label'        => $label,
        'type'         => 'text',
        'component'    => 'text',
        'required'     => false,
        'editable'     => true,
        'order'        => $order,
    ]);
    $field->save();

    return $field;
}

function has_custom_fields_value(HasCustomFieldsSubject $subject, CustomField $field, mixed $value, string $uuid, string $valueType = 'text'): CustomFieldValue
{
    $fieldValue = new CustomFieldValue();
    $fieldValue->forceFill([
        'uuid'              => $uuid,
        'company_uuid'      => $subject->company_uuid,
        'custom_field_uuid' => $field->uuid,
        'custom_field_id'   => $field->uuid,
        'subject_uuid'      => $subject->uuid,
        'subject_type'      => $subject->getMorphClass(),
        'value'             => $value,
        'value_type'        => $valueType,
    ]);
    $fieldValue->save();

    return $fieldValue;
}

afterEach(function () {
    Facade::clearResolvedInstances();
});

test('has custom fields resolves definitions by normalized names labels and boolean helpers', function () {
    $subject = has_custom_fields_database();
    has_custom_fields_field($subject, 'field-1', 'order-number', 'Order Number');
    has_custom_fields_field($subject, 'field-2', 'delivery-window', 'Delivery Window');

    expect($subject->getCustomField(' order_number ')?->uuid)->toBe('field-1')
        ->and($subject->getCustomField('Delivery Window')?->uuid)->toBe('field-2')
        ->and($subject->hasCustomField('order number'))->toBeTrue()
        ->and($subject->isCustomField('delivery-window'))->toBeTrue()
        ->and($subject->hasAnyCustomFields(['missing', 'delivery window']))->toBeTrue()
        ->and($subject->hasAllCustomFields(['order number', 'delivery window']))->toBeTrue()
        ->and($subject->hasAllCustomFields(['order number', 'missing']))->toBeFalse()
        ->and($subject->getCustomFieldValueByKey('missing', 'fallback'))->toBe('fallback');
});

test('has custom fields exposes values with snake or label keys and inserts response extras by request context', function () {
    $subject     = has_custom_fields_database('int/v1/subjects');
    $orderNumber = has_custom_fields_field($subject, 'field-1', 'order-number', 'Order Number', 1);
    $reserved    = has_custom_fields_field($subject, 'field-2', 'custom-fields', 'Custom Fields', 2);
    has_custom_fields_value($subject, $orderNumber, 'SO-1001', 'value-1');
    has_custom_fields_value($subject, $reserved, 'reserved-value', 'value-2');

    expect($subject->getCustomFieldValueByKey('Order Number'))->toBe('SO-1001')
        ->and($subject->getRawCustomFieldValueByKey('order-number'))->toBe('SO-1001')
        ->and($subject->getCustomFieldValues())->toBe([
            'order_number'  => 'SO-1001',
            'custom_fields' => 'reserved-value',
        ])
        ->and($subject->getCustomFieldValues(false))->toBe([
            'Order Number'  => 'SO-1001',
            'Custom Fields' => 'reserved-value',
        ])
        ->and($subject->getCustomFieldKeys())->toBe(['order_number', 'custom_fields']);

    $internal = $subject->withCustomFields(['uuid' => 'subject-1', 'name' => 'Subject One'], 'end');

    expect($internal['uuid'])->toBe('subject-1')
        ->and($internal['order_number'])->toBe('SO-1001')
        ->and($internal['_custom_fields'])->toBe('reserved-value')
        ->and($internal['custom_field_values'])->toHaveCount(2)
        ->and($internal)->not->toHaveKey('custom_fields');

    $publicSubject = has_custom_fields_database('v1/subjects');
    $publicField   = has_custom_fields_field($publicSubject, 'field-public', 'tracking-code', 'Tracking Code');
    has_custom_fields_value($publicSubject, $publicField, 'TRK-1', 'value-public');

    expect($publicSubject->withCustomFields(['uuid' => 'subject-1'], 'start'))->toBe([
        'tracking_code' => 'TRK-1',
        'custom_fields' => ['tracking_code'],
        'uuid'          => 'subject-1',
    ]);
});

test('has custom fields handles empty lookups cached values and positional response insertion', function () {
    $subject = has_custom_fields_database('int/v1/subjects');
    $field   = has_custom_fields_field($subject, 'field-1', 'order-number', 'Order Number');
    has_custom_fields_value($subject, $field, 'SO-1001', 'value-1');

    expect($subject->hasAnyCustomFields(['missing', 'absent']))->toBeFalse()
        ->and($subject->getRawCustomFieldValueByKey('missing', 'raw-default'))->toBe('raw-default');

    $emptyField = has_custom_fields_field($subject, 'field-empty', 'empty-value', 'Empty Value');

    expect($subject->getRawCustomFieldValueByKey('empty-value', 'empty-default'))->toBe('empty-default')
        ->and($subject->getCustomFieldValueByKey('order-number'))->toBe('SO-1001');

    CustomFieldValue::where('uuid', 'value-1')->update(['value' => 'SO-CHANGED']);

    expect($subject->getCustomFieldValueByKey('order-number'))->toBe('SO-1001')
        ->and($subject->getCustomFieldValue($emptyField))->toBeNull()
        ->and($subject->withCustomFields(['uuid' => 'subject-1', 'name' => 'Subject One', 'status' => 'active'], 1))->toMatchArray([
            'uuid'          => 'subject-1',
            'order_number'  => 'SO-1001',
            'name'          => 'Subject One',
            'status'        => 'active',
        ])
        ->and($subject->withCustomFields(['uuid' => 'subject-1', 'name' => 'Subject One', 'status' => 'active']))->toMatchArray([
            'uuid'          => 'subject-1',
            'order_number'  => 'SO-1001',
            'name'          => 'Subject One',
            'status'        => 'active',
        ])
        ->and($subject->forgetCustomField('does-not-exist'))->toBeFalse();
});

test('has custom fields writes creates syncs forgets and clears field values', function () {
    $subject     = has_custom_fields_database();
    $orderNumber = has_custom_fields_field($subject, 'field-1', 'order-number', 'Order Number');
    $legacy      = has_custom_fields_field($subject, 'field-2', 'legacy-code', 'Legacy Code');
    has_custom_fields_value($subject, $legacy, 'OLD', 'value-legacy');

    expect($subject->setCustomFieldValue($orderNumber, 'SO-1002')?->value)->toBe('SO-1002')
        ->and($subject->getCustomFieldValueByKey('order number'))->toBe('SO-1002')
        ->and($subject->setCustomFields(['legacy code' => 'NEW', 'missing' => 'ignored']))->toBe(1);

    $subject->unsetRelation('customFieldValues');
    expect($subject->getCustomFieldValueByKey('legacy-code'))->toBe('NEW');

    $sync = $subject->syncCustomFields(['order number' => 'SO-1003'], false);
    $subject->unsetRelation('customFieldValues');

    expect($sync)->toBe(['written' => 1, 'deleted' => 1])
        ->and($subject->getCustomFieldValueByKey('order number'))->toBe('SO-1003')
        ->and($subject->getCustomFieldValueByKey('legacy code', 'deleted'))->toBe('deleted')
        ->and($subject->forgetCustomField('order-number'))->toBeTrue()
        ->and($subject->forgetCustomField('order-number'))->toBeFalse()
        ->and($subject->setCustomFieldValue('Created Later', 'yes', true)?->value)->toBe('yes')
        ->and($subject->hasCustomField('created-later'))->toBeTrue()
        ->and($subject->clearCustomFields())->toBe(1)
        ->and($subject->getCustomFieldValues())->toBe([]);
});

test('has custom fields syncs value payloads with dry run update delete and delete missing semantics', function () {
    $subject     = has_custom_fields_database();
    $status      = has_custom_fields_field($subject, 'field-status', 'status', 'Status');
    $priority    = has_custom_fields_field($subject, 'field-priority', 'priority', 'Priority');
    $legacy      = has_custom_fields_field($subject, 'field-legacy', 'legacy', 'Legacy');
    $statusValue = has_custom_fields_value($subject, $status, 'pending', 'value-status');
    has_custom_fields_value($subject, $legacy, 'old', 'value-legacy');

    expect($subject->syncCustomFieldValues([
        ['custom_field_uuid' => ''],
        ['value' => 'missing field'],
    ], ['persist' => false]))->toBe([
        'created' => 0,
        'updated' => 0,
        'deleted' => 0,
        'skipped' => 2,
    ]);

    $result = $subject->syncCustomFieldValues([
        [
            'uuid'              => $statusValue->uuid,
            'custom_field_uuid' => $status->uuid,
            'value'             => 'complete',
            'value_type'        => 'text',
        ],
        [
            'custom_field_uuid' => $priority->uuid,
            'value'             => ['level' => 'high'],
            'value_type'        => 'object',
        ],
        [
            'custom_field_uuid' => $legacy->uuid,
            'value'             => null,
            'value_type'        => 'text',
        ],
        [
            'custom_field_uuid' => '',
            'value'             => 'skip me',
        ],
    ], [
        'treat_null_as_delete' => true,
        'delete_missing'       => true,
    ]);

    $subject->unsetRelation('customFieldValues');

    expect($result)->toBe([
        'created' => 1,
        'updated' => 1,
        'deleted' => 1,
        'skipped' => 1,
    ])
        ->and($subject->getCustomFieldValueByKey('status'))->toBe('complete')
        ->and($subject->getCustomFieldValueByKey('priority'))->toBe(['level' => 'high'])
        ->and($subject->getCustomFieldValueByKey('legacy', 'deleted'))->toBe('deleted');
});

test('has custom fields syncs unchanged and missing-value payloads without unnecessary writes', function () {
    $subject  = has_custom_fields_database();
    $status   = has_custom_fields_field($subject, 'field-status', 'status', 'Status');
    $priority = has_custom_fields_field($subject, 'field-priority', 'priority', 'Priority');
    $legacy   = has_custom_fields_field($subject, 'field-legacy', 'legacy', 'Legacy');
    $stale    = has_custom_fields_field($subject, 'field-stale', 'stale', 'Stale');

    $statusValue = has_custom_fields_value($subject, $status, 'pending', 'value-status');
    has_custom_fields_value($subject, $priority, 'normal', 'value-priority');
    has_custom_fields_value($subject, $legacy, 'old', 'value-legacy');
    has_custom_fields_value($subject, $stale, 'remove-me', 'value-stale');

    $result = $subject->syncCustomFieldValues([
        [
            'uuid'              => 'missing-value',
            'custom_field_uuid' => $priority->uuid,
            'value'             => null,
            'value_type'        => 'text',
        ],
        [
            'uuid'              => $statusValue->uuid,
            'custom_field_uuid' => $status->uuid,
            'value'             => 'pending',
            'value_type'        => 'text',
        ],
        [
            'custom_field_uuid' => $legacy->uuid,
            'value'             => 'old',
            'value_type'        => 'text',
        ],
    ], [
        'treat_null_as_delete' => true,
        'delete_missing'       => true,
    ]);

    $subject->unsetRelation('customFieldValues');

    expect($result)->toBe([
        'created' => 0,
        'updated' => 0,
        'deleted' => 1,
        'skipped' => 3,
    ])
        ->and($subject->getCustomFieldValueByKey('status'))->toBe('pending')
        ->and($subject->getCustomFieldValueByKey('legacy'))->toBe('old')
        ->and($subject->getCustomFieldValueByKey('priority'))->toBe('normal')
        ->and($subject->getCustomFieldValueByKey('stale', 'deleted'))->toBe('deleted');
});

test('has custom fields updates existing values by custom field uuid when value uuid is absent', function () {
    $subject = has_custom_fields_database();
    $status  = has_custom_fields_field($subject, 'field-status', 'status', 'Status');
    has_custom_fields_value($subject, $status, 'pending', 'value-status');

    $result = $subject->syncCustomFieldValues([
        [
            'custom_field_uuid' => $status->uuid,
            'value'             => 'complete',
            'value_type'        => 'text',
        ],
    ]);

    $subject->unsetRelation('customFieldValues');

    expect($result)->toBe([
        'created' => 0,
        'updated' => 1,
        'deleted' => 0,
        'skipped' => 0,
    ])
        ->and($subject->getCustomFieldValueByKey('status'))->toBe('complete')
        ->and(CustomFieldValue::where('uuid', 'value-status')->first()?->value_type)->toBe('text');
});
