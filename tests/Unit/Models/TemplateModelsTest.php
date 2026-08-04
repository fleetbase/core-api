<?php

use Fleetbase\Models\Template;
use Fleetbase\Models\TemplateQuery;
use Fleetbase\Traits\Searchable;
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

    public function notes()
    {
        return $this->hasMany(TemplateQueryTenantNoteFixture::class, 'fixture_id');
    }
}

class TemplateQueryTenantNoteFixture extends Model
{
    protected $connection = 'mysql';
    protected $table      = 'template_query_tenant_note_fixtures';
    protected $guarded    = [];
    public $timestamps    = false;
}

class TemplateQueryUnscopedFixture extends Model
{
    protected $connection = 'mysql';
    protected $table      = 'template_query_unscoped_fixtures';
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

class TemplateSearchFixture extends Model
{
    use Searchable;

    protected $connection = 'mysql';
    protected $table      = 'template_search_fixtures';
    protected $guarded    = [];
    public $timestamps    = false;

    protected $searchableColumns = [
        'name',
        'meta->code',
        'meta->ignored->path',
        'searchRelation.label',
    ];

    public function searchRelation()
    {
        return $this->hasMany(TemplateSearchRelationFixture::class, 'fixture_id');
    }
}

class TemplateCustomSearchFixture extends Model
{
    use Searchable;

    protected $connection = 'mysql';
    protected $table      = 'template_search_fixtures';
    protected $guarded    = [];
    public $timestamps    = false;

    public string $lastSearch = '';

    public function search($search): string
    {
        $this->lastSearch = $search;

        return 'custom-search:' . $search;
    }
}

class TemplateSearchRelationFixture extends Model
{
    protected $connection = 'mysql';
    protected $table      = 'template_search_relation_fixtures';
    protected $guarded    = [];
    public $timestamps    = false;
}

function template_models_database(): Capsule
{
    Model::clearBootedModels();

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
            TemplateQueryUnscopedFixture::class,
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
    $schema->create('companies', function ($table) {
        $table->string('uuid')->primary();
        $table->string('name')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('files', function ($table) {
        $table->string('uuid')->primary();
        $table->string('path')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('templates', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('created_by_uuid')->nullable();
        $table->string('updated_by_uuid')->nullable();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->string('context_type')->nullable();
        $table->string('unit')->nullable();
        $table->float('width')->nullable();
        $table->float('height')->nullable();
        $table->string('orientation')->nullable();
        $table->json('margins')->nullable();
        $table->string('background_color')->nullable();
        $table->string('background_image_uuid')->nullable();
        $table->json('content')->nullable();
        $table->json('element_schemas')->nullable();
        $table->boolean('is_default')->default(false);
        $table->boolean('is_system')->default(false);
        $table->boolean('is_public')->default(false);
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });
    $schema->create('template_queries', function ($table) {
        $table->string('uuid')->primary();
        $table->string('template_uuid')->nullable();
        $table->string('variable_name')->nullable();
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
    $schema->create('template_query_tenant_note_fixtures', function ($table) {
        $table->increments('id');
        $table->integer('fixture_id');
        $table->string('body')->nullable();
    });
    $schema->create('template_query_unscoped_fixtures', function ($table) {
        $table->increments('id');
        $table->string('name')->nullable();
    });
    $schema->create('template_query_global_fixtures', function ($table) {
        $table->increments('id');
        $table->string('category')->nullable();
        $table->string('name')->nullable();
        $table->integer('rank')->default(0);
    });
    $schema->create('template_search_fixtures', function ($table) {
        $table->increments('id');
        $table->string('name')->nullable();
        $table->json('meta')->nullable();
        $table->string('status')->nullable();
    });
    $schema->create('template_search_relation_fixtures', function ($table) {
        $table->increments('id');
        $table->integer('fixture_id');
        $table->string('label')->nullable();
    });

    $capsule->getConnection('mysql')->table('companies')->insert([
        ['uuid' => 'company-1', 'name' => 'Acme Logistics'],
    ]);
    $capsule->getConnection('mysql')->table('users')->insert([
        ['uuid' => 'creator-1', 'name' => 'Creator', 'email' => 'creator@example.com'],
        ['uuid' => 'updater-1', 'name' => 'Updater', 'email' => 'updater@example.com'],
    ]);
    $capsule->getConnection('mysql')->table('files')->insert([
        ['uuid' => 'file-background-1', 'path' => 'templates/background.png'],
    ]);
    $capsule->getConnection('mysql')->table('template_query_tenant_fixtures')->insert([
        ['company_uuid' => 'company-1', 'status' => 'active', 'name' => 'Alpha order', 'score' => 95, 'archived_at' => null],
        ['company_uuid' => 'company-1', 'status' => 'active', 'name' => 'Beta order', 'score' => 70, 'archived_at' => '2026-07-10 10:00:00'],
        ['company_uuid' => 'company-1', 'status' => 'draft', 'name' => 'Gamma order', 'score' => 60, 'archived_at' => null],
        ['company_uuid' => 'company-2', 'status' => 'active', 'name' => 'Other tenant order', 'score' => 99, 'archived_at' => null],
    ]);
    $capsule->getConnection('mysql')->table('template_query_tenant_note_fixtures')->insert([
        ['fixture_id' => 1, 'body' => 'First alpha note'],
        ['fixture_id' => 1, 'body' => 'Second alpha note'],
        ['fixture_id' => 2, 'body' => 'Archived beta note'],
    ]);
    $capsule->getConnection('mysql')->table('template_query_unscoped_fixtures')->insert([
        ['name' => 'Unscoped allowed but not global'],
    ]);
    $capsule->getConnection('mysql')->table('template_query_global_fixtures')->insert([
        ['category' => 'public', 'name' => 'Global Alpha', 'rank' => 2],
        ['category' => 'public', 'name' => 'Global Beta', 'rank' => 1],
        ['category' => 'private', 'name' => 'Global Private', 'rank' => 3],
    ]);
    $templateDefaults = [
        'public_id'             => null,
        'company_uuid'          => null,
        'created_by_uuid'       => null,
        'updated_by_uuid'       => null,
        'name'                  => null,
        'description'           => null,
        'context_type'          => null,
        'unit'                  => null,
        'width'                 => null,
        'height'                => null,
        'orientation'           => null,
        'margins'               => null,
        'background_color'      => null,
        'background_image_uuid' => null,
        'content'               => null,
        'element_schemas'       => null,
        'is_default'            => false,
        'is_system'             => false,
        'is_public'             => false,
        'created_at'            => '2026-07-18 00:00:00',
        'updated_at'            => '2026-07-18 00:00:00',
        'deleted_at'            => null,
    ];

    $capsule->getConnection('mysql')->table('templates')->insert(array_map(
        fn (array $row): array => array_merge($templateDefaults, $row),
        [
            [
                'uuid'                  => 'template-company',
                'public_id'             => 'template_company_public',
                'company_uuid'          => 'company-1',
                'created_by_uuid'       => 'creator-1',
                'updated_by_uuid'       => 'updater-1',
                'name'                  => 'Invoice Template',
                'description'           => 'Primary invoice layout',
                'context_type'          => 'invoice',
                'unit'                  => 'px',
                'width'                 => 800,
                'height'                => 600,
                'orientation'           => 'portrait',
                'margins'               => json_encode(['top' => 12, 'right' => 14]),
                'background_color'      => '#ffffff',
                'background_image_uuid' => 'file-background-1',
                'content'               => json_encode(['blocks' => [['type' => 'text']]]),
                'element_schemas'       => json_encode(['customer' => ['label' => 'Customer']]),
                'is_default'            => true,
                'is_system'             => false,
                'is_public'             => false,
                'created_at'            => '2026-07-18 00:00:00',
                'updated_at'            => '2026-07-18 00:00:00',
                'deleted_at'            => null,
            ],
            ['uuid' => 'template-system', 'name' => 'System Invoice', 'description' => 'Shared system layout', 'context_type' => 'invoice', 'is_system' => true],
            ['uuid' => 'template-public', 'name' => 'Public Invoice', 'description' => 'Shared public layout', 'context_type' => 'invoice', 'is_public' => true],
            ['uuid' => 'template-other-company', 'company_uuid' => 'company-2', 'name' => 'Other Tenant Invoice', 'description' => 'Hidden tenant layout', 'context_type' => 'invoice'],
            ['uuid' => 'template-other-context', 'company_uuid' => 'company-1', 'name' => 'Receipt Template', 'description' => 'Receipt layout', 'context_type' => 'receipt'],
        ]
    ));
    $capsule->getConnection('mysql')->table('template_queries')->insert([
        ['uuid' => 'query-1', 'template_uuid' => 'template-company', 'variable_name' => 'orders'],
        ['uuid' => 'query-2', 'template_uuid' => 'template-other-company', 'variable_name' => 'hidden'],
    ]);
    $capsule->getConnection('mysql')->table('template_search_fixtures')->insert([
        ['id' => 1, 'name' => 'Alpha Fixture', 'meta' => json_encode(['code' => 'ALPHA.001']), 'status' => 'active'],
    ]);
    $capsule->getConnection('mysql')->table('template_search_relation_fixtures')->insert([
        ['fixture_id' => 1, 'label' => 'Related Alpha'],
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

it('casts template layout data and resolves ownership background and query relationships', function () {
    template_models_database();

    $template = Template::where('uuid', 'template-company')->firstOrFail();

    expect($template->margins)->toBe(['top' => 12, 'right' => 14])
        ->and($template->content)->toBe(['blocks' => [['type' => 'text']]])
        ->and($template->element_schemas)->toBe(['customer' => ['label' => 'Customer']])
        ->and($template->is_default)->toBeTrue()
        ->and($template->is_system)->toBeFalse()
        ->and($template->is_public)->toBeFalse()
        ->and($template->width)->toBe(800.0)
        ->and($template->height)->toBe(600.0)
        ->and($template->getSearchableColumns())->toBe(['name', 'description', 'context_type'])
        ->and($template->company()->first()->name)->toBe('Acme Logistics')
        ->and($template->createdBy()->first()->email)->toBe('creator@example.com')
        ->and($template->updatedBy()->first()->email)->toBe('updater@example.com')
        ->and($template->backgroundImage()->first()->path)->toBe('templates/background.png')
        ->and($template->queries()->pluck('variable_name')->all())->toBe(['orders'])
        ->and(Template::search('invoice')->orderBy('uuid')->pluck('uuid')->all())->toBe([
            'template-company',
            'template-other-company',
            'template-public',
            'template-system',
        ]);
});

it('builds searchable query branches for custom search json relations and additional filters', function () {
    template_models_database();

    $customSearch = new TemplateCustomSearchFixture();

    expect($customSearch->scopeSearch(TemplateCustomSearchFixture::query(), 'Alpha'))->toBe('custom-search:Alpha')
        ->and($customSearch->lastSearch)->toBe('Alpha');

    $query = TemplateSearchFixture::query()->search('Alpha.001', function ($builder, string $search) {
        $builder->orWhere('status', $search === 'alpha%001' ? 'active' : 'missing');
    });

    $sql = $query->toSql();

    expect($sql)->toContain('lower(name)')
        ->and($sql)->toContain('json_extract(meta')
        ->and($sql)->not->toContain('ignored')
        ->and($sql)->toContain('exists')
        ->and($sql)->toContain('template_search_relation_fixtures')
        ->and($query->getBindings())->toContain('%alpha%001%');
});

it('exposes template query ownership relationships', function () {
    template_models_database();

    $query = new TemplateQuery([
        'template_uuid'   => 'template-company',
        'company_uuid'    => 'company-1',
        'created_by_uuid' => 'user-1',
    ]);

    expect($query->template())->toBeInstanceOf(Illuminate\Database\Eloquent\Relations\BelongsTo::class)
        ->and($query->template()->getForeignKeyName())->toBe('template_uuid')
        ->and($query->company())->toBeInstanceOf(Illuminate\Database\Eloquent\Relations\BelongsTo::class)
        ->and($query->company()->getForeignKeyName())->toBe('company_uuid')
        ->and($query->createdBy())->toBeInstanceOf(Illuminate\Database\Eloquent\Relations\BelongsTo::class)
        ->and($query->createdBy()->getForeignKeyName())->toBe('created_by_uuid');
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
        'with' => ['notes'],
        'sort' => [
            ['field' => 'score', 'direction' => 'desc'],
        ],
        'limit' => 1,
    ]);

    $results = $query->execute();

    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('Alpha order')
        ->and($results->first()->company_uuid)->toBe('company-1')
        ->and($results->first()->relationLoaded('notes'))->toBeTrue()
        ->and($results->first()->notes->pluck('body')->all())->toBe([
            'First alpha note',
            'Second alpha note',
        ]);
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
    $unscopedAllowedModel = new TemplateQuery([
        'model_type' => TemplateQueryUnscopedFixture::class,
    ]);

    expect($disallowed->execute())->toBeEmpty()
        ->and($missingClass->execute())->toBeEmpty()
        ->and($missingTenant->execute())->toBeEmpty()
        ->and($unscopedAllowedModel->execute())->toBeEmpty();
});

it('allows explicitly global template query models without tenant columns', function () {
    template_models_database();

    $query = new TemplateQuery([
        'model_type' => TemplateQueryGlobalFixture::class,
        'conditions' => [
            ['field' => 'category', 'operator' => '=', 'value' => 'public'],
            ['field' => 'name', 'operator' => 'like', 'value' => 'Global'],
            ['field' => 'rank', 'operator' => 'not in', 'value' => [3]],
            ['field' => 'name', 'operator' => 'not null'],
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
