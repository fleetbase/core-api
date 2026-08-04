<?php

use Fleetbase\Contracts\Directive;
use Fleetbase\Support\DirectiveParser;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

class DirectiveParserOrder extends Model
{
    protected $table      = 'orders';
    protected $primaryKey = 'uuid';
    public $incrementing  = false;
    protected $keyType    = 'string';
    public $timestamps    = false;
    protected $guarded    = [];

    public function payloads()
    {
        return $this->hasMany(DirectiveParserPayload::class, 'order_uuid', 'uuid');
    }
}

class DirectiveParserPayload extends Model
{
    protected $table      = 'payloads';
    protected $primaryKey = 'uuid';
    public $incrementing  = false;
    protected $keyType    = 'string';
    public $timestamps    = false;
    protected $guarded    = [];
}

class DirectiveParserSessionFake
{
    public function get(string $key, mixed $default = null): mixed
    {
        return session($key, $default);
    }
}

class DirectiveParserAuthFake
{
    public function user(): object
    {
        return (object) [
            'uuid' => 'user-1',
        ];
    }
}

function directive_parser_database(): void
{
    $connectionConfig = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'database.default'             => 'testing',
        'database.connections.testing' => $connectionConfig,
    ]);

    session()->flush();
    session(['company' => 'company-1']);

    $capsule = new Capsule($container);
    $capsule->addConnection($connectionConfig, 'testing');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('testing');
    $container->instance('db', $databaseManager);
    Facade::clearResolvedInstance('db');

    $container->instance('session', new DirectiveParserSessionFake());
    $container->instance('auth', new DirectiveParserAuthFake());
    Facade::clearResolvedInstance('session');
    Facade::clearResolvedInstance('auth');
}

test('directive parser applies query directives with session and user placeholders', function () {
    directive_parser_database();

    $query = DirectiveParserOrder::query();

    DirectiveParser::apply($query, ['where', 'company_uuid', '=', 'session.company']);
    (new DirectiveParser())->applyDirective($query, ['where', 'created_by_uuid', '=', 'self.uuid']);

    expect($query->toSql())->toBe('select * from "orders" where "company_uuid" = ? and "created_by_uuid" = ?')
        ->and($query->getBindings())->toBe(['company-1', 'user-1']);
});

test('directive parser qualifies nested relation columns without altering methods operators or values', function () {
    directive_parser_database();

    $query = DirectiveParserOrder::query();

    DirectiveParser::apply($query, ['whereHas', 'payloads', 'where', 'status', '=', 'ready']);
    DirectiveParser::apply($query, ['whereHas', 'payloads']);
    DirectiveParser::apply($query, ['whereHas', 'payloads', 'whereColumn', 'status', 'orders.status']);

    expect($query->toSql())->toContain('exists')
        ->and($query->toSql())->toContain('"payloads"."status" = ?')
        ->and($query->toSql())->not->toContain('"payloads"."status" = "orders"."status"')
        ->and($query->getBindings())->toBe(['ready']);
});

test('directive parser delegates directive classes resolved from the container', function () {
    $container = bind_test_container();
    $container->bind('Tests\\Fixtures\\DirectiveParserCompanyDirective', function () {
        return new class implements Directive {
            public function apply(Builder $builder): Builder
            {
                return $builder->where('company_uuid', 'company-from-directive');
            }
        };
    });

    directive_parser_database();

    $query = DirectiveParserOrder::query();

    DirectiveParser::apply($query, ['Tests\\Fixtures\\DirectiveParserCompanyDirective']);

    expect($query->toSql())->toBe('select * from "orders" where "company_uuid" = ?')
        ->and($query->getBindings())->toBe(['company-from-directive']);
});
