<?php

namespace Fleetbase\Tests\Fixtures\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidationFactory;

/**
 * Shared setup for the admin socket settings tests: socket auth configured as in
 * SocketAuthFixtures, plus an in-memory `settings` table, a real array cache and request
 * validation.
 */
class SocketSettingsFixtures
{
    public static function setUp(?string $key = SocketAuthFixtures::KEY, array $config = []): Capsule
    {
        EloquentModel::clearBootedModels();

        $sqlite    = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];
        $container = SocketAuthFixtures::container($key, array_merge([
            'database.default'           => 'mysql',
            'database.connections.mysql' => $sqlite,
            'fleetbase.connection.db'    => 'mysql',
        ], $config));

        $container->instance('cache', new CacheRepository(new ArrayStore()));
        Facade::clearResolvedInstance('cache');

        $capsule = new Capsule($container);
        $capsule->addConnection($sqlite, 'mysql');
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $capsule->getDatabaseManager()->setDefaultConnection('mysql');
        $container->instance('db', $capsule->getDatabaseManager());
        Facade::clearResolvedInstance('db');

        $capsule->getConnection('mysql')->getSchemaBuilder()->create('settings', function ($table) {
            $table->increments('id');
            $table->string('key')->unique();
            $table->text('value')->nullable();
        });

        $validation = new ValidationFactory(new Translator(new ArrayLoader(), 'en'));
        Request::macro('validate', function (array $rules) use ($validation) {
            return $validation->make($this->all(), $rules)->validate();
        });

        return $capsule;
    }

    /**
     * Undo setUp(): the socket auth fixtures and the validate macro.
     */
    public static function reset(): void
    {
        $macros = new \ReflectionProperty(Request::class, 'macros');
        $macros->setAccessible(true);
        $macros->setValue(null, array_diff_key($macros->getValue(), ['validate' => true]));

        SocketAuthFixtures::reset();
    }
}
