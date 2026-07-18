<?php

use Fleetbase\Models\Template;
use Fleetbase\Models\TemplateQuery;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

class TemplateQueryTenantFixture extends Model
{
    protected $connection = 'mysql';
    protected $table      = 'template_query_tenant_fixtures';
    protected $guarded    = [];
    public $timestamps    = false;
}

class TemplateQueryGlobalFixture extends Model
{
    protected $connection = 'mysql';
    protected $table      = 'template_query_global_fixtures';
    protected $guarded    = [];
    public $timestamps    = false;
}

function template_models_database(): Capsule
{
    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'database.default'                => 'mysql',
        'database.connections.mysql'      => $connection,
        'fleetbase.connection.db'         => 'mysql',
        'fleetbase.template_query_models' => [
            TemplateQueryTenantFixture::class,
            TemplateQueryGlobalFixture::class,
        ],
        'fleetbase.template_global_query_models' => [
            TemplateQueryGlobalFixture::class,
        ],
    ]);

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
    $schema->create('templates', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('context_type')->nullable();
        $table->boolean('is_system')->default(false);
        $table->boolean('is_public')->default(false);
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('template_query_tenant_fixtures', function ($table) {
        $table->increments('id');
        $table->string('company_uuid')->nullable();
        $table->string('status')->nullable();
        $table->string('name')->nullable();
        $table->integer('score')->default(0);
        $table->timestamp('archived_at')->nullable();
    });
    $schema->create('template_query_global_fixtures', function ($table) {
        $table->increments('id');
        $table->string('category')->nullable();
        $table->string('name')->nullable();
        $table->integer('rank')->default(0);
    });

    $capsule->getConnection('mysql')->table('template_query_tenant_fixtures')->insert([
        ['company_uuid' => 'company-1', 'status' => 'active', 'name' => 'Alpha order', 'score' => 95, 'archived_at' => null],
        ['company_uuid' => 'company-1', 'status' => 'active', 'name' => 'Beta order', 'score' => 70, 'archived_at' => '2026-07-10 10:00:00'],
        ['company_uuid' => 'company-1', 'status' => 'draft', 'name' => 'Gamma order', 'score' => 60, 'archived_at' => null],
        ['company_uuid' => 'company-2', 'status' => 'active', 'name' => 'Other tenant order', 'score' => 99, 'archived_at' => null],
    ]);
    $capsule->getConnection('mysql')->table('template_query_global_fixtures')->insert([
        ['category' => 'public', 'name' => 'Global Alpha', 'rank' => 2],
        ['category' => 'public', 'name' => 'Global Beta', 'rank' => 1],
        ['category' => 'private', 'name' => 'Global Private', 'rank' => 3],
    ]);
    $capsule->getConnection('mysql')->table('templates')->insert([
        ['uuid' => 'template-company', 'company_uuid' => 'company-1', 'context_type' => 'invoice', 'is_system' => false, 'is_public' => false, 'deleted_at' => null],
        ['uuid' => 'template-system', 'company_uuid' => null, 'context_type' => 'invoice', 'is_system' => true, 'is_public' => false, 'deleted_at' => null],
        ['uuid' => 'template-public', 'company_uuid' => null, 'context_type' => 'invoice', 'is_system' => false, 'is_public' => true, 'deleted_at' => null],
        ['uuid' => 'template-other-company', 'company_uuid' => 'company-2', 'context_type' => 'invoice', 'is_system' => false, 'is_public' => false, 'deleted_at' => null],
        ['uuid' => 'template-other-context', 'company_uuid' => 'company-1', 'context_type' => 'receipt', 'is_system' => false, 'is_public' => false, 'deleted_at' => null],
    ]);

    return $capsule;
}

it('filters templates by context and availability for company system and public templates', function () {
    template_models_database();

    $templates = Template::query()
        ->forContext('invoice')
        ->availableFor('company-1')
        ->orderBy('uuid')
        ->pluck('uuid')
        ->all();

    expect($templates)->toBe([
        'template-company',
        'template-public',
        'template-system',
    ]);
});

it('executes tenant scoped template queries with conditions sorting and limits', function () {
    template_models_database();

    $query = new TemplateQuery([
        'company_uuid' => 'company-1',
        'model_type'   => TemplateQueryTenantFixture::class,
        'conditions'   => [
            ['field' => 'status', 'operator' => 'in', 'value' => ['active', 'queued']],
            ['field' => 'name', 'operator' => 'not like', 'value' => 'Beta'],
            ['field' => 'score', 'operator' => '>=', 'value' => 90],
            ['field' => 'archived_at', 'operator' => 'null'],
            ['field' => null, 'operator' => '=', 'value' => 'ignored'],
        ],
        'sort' => [
            ['field' => 'score', 'direction' => 'desc'],
        ],
        'limit' => 1,
    ]);

    $results = $query->execute();

    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('Alpha order')
        ->and($results->first()->company_uuid)->toBe('company-1');
});

it('falls back to session company for tenant scoped template queries', function () {
    template_models_database();
    session(['company' => 'company-2']);

    $query = new TemplateQuery([
        'model_type' => TemplateQueryTenantFixture::class,
        'conditions' => [
            ['field' => 'status', 'operator' => '=', 'value' => 'active'],
        ],
    ]);

    expect($query->execute()->pluck('name')->all())->toBe(['Other tenant order']);
});

it('returns empty results for disallowed missing or unscoped tenant query models', function () {
    template_models_database();

    $disallowed = new TemplateQuery([
        'company_uuid' => 'company-1',
        'model_type'   => Template::class,
    ]);
    $missingClass = new TemplateQuery([
        'company_uuid' => 'company-1',
        'model_type'   => 'Fleetbase\\Missing\\TemplateModel',
    ]);
    $missingTenant = new TemplateQuery([
        'model_type' => TemplateQueryTenantFixture::class,
    ]);

    expect($disallowed->execute())->toBeEmpty()
        ->and($missingClass->execute())->toBeEmpty()
        ->and($missingTenant->execute())->toBeEmpty();
});

it('allows explicitly global template query models without tenant columns', function () {
    template_models_database();

    $query = new TemplateQuery([
        'model_type' => TemplateQueryGlobalFixture::class,
        'conditions' => [
            ['field' => 'category', 'operator' => '=', 'value' => 'public'],
            ['field' => 'name', 'operator' => 'like', 'value' => 'Global'],
            ['field' => 'rank', 'operator' => 'not in', 'value' => [3]],
        ],
        'sort' => [
            ['field' => 'rank', 'direction' => 'asc'],
        ],
    ]);

    expect($query->execute()->pluck('name')->all())->toBe([
        'Global Beta',
        'Global Alpha',
    ]);
});
