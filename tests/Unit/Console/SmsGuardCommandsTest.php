<?php

use Fleetbase\Console\Commands\SmsGuardMode;
use Fleetbase\Console\Commands\SmsGuardPause;
use Fleetbase\Console\Commands\SmsGuardUnpause;
use Fleetbase\Support\Sms\SmsGuard;
use Fleetbase\Support\Sms\SmsPolicy;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Console\Tester\CommandTester;

function sms_guard_commands_database(): Capsule
{
    EloquentModel::clearBootedModels();

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'sms.guard'                  => ['enabled' => true, 'mode' => 'enforce'],
        'database.default'           => 'mysql',
        'database.connections.mysql' => $connection,
        'fleetbase.connection.db'    => 'mysql',
    ]);
    $container->instance('cache', new CacheRepository(new ArrayStore()));
    app()->forgetInstance(SmsGuard::class);
    Facade::clearResolvedInstances();

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->getDatabaseManager()->setDefaultConnection('mysql');
    $container->instance('db', $capsule->getDatabaseManager());
    Facade::clearResolvedInstance('db');

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('settings', function ($table) {
        $table->increments('id');
        $table->string('key')->unique();
        $table->text('value')->nullable();
    });
    $schema->create('companies', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('name')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $capsule->getConnection('mysql')->table('companies')->insert([
        ['uuid' => 'company-acme', 'public_id' => 'company_acme', 'name' => 'Acme'],
    ]);

    return $capsule;
}

function sms_guard_command_tester(object $command): CommandTester
{
    $command->setLaravel(app());

    return new CommandTester($command);
}

afterEach(function () {
    EloquentModel::unsetConnectionResolver();
    EloquentModel::clearBootedModels();
    Facade::clearResolvedInstances();
});

test('sms:mode shows and switches the guard mode at runtime', function () {
    sms_guard_commands_database();

    $show = sms_guard_command_tester(new SmsGuardMode());
    $show->execute([]);

    $switch = sms_guard_command_tester(new SmsGuardMode());
    $switch->execute(['mode' => 'Monitor']);

    $bad = sms_guard_command_tester(new SmsGuardMode());
    $bad->execute(['mode' => 'loud']);

    expect($show->getStatusCode())->toBe(0)
        ->and($show->getDisplay())->toContain('Mode:    enforce')
        ->and($show->getDisplay())->toContain('Pauses:  none')
        ->and($switch->getStatusCode())->toBe(0)
        ->and($switch->getDisplay())->toContain('SMS guard mode set to monitor.')
        ->and(SmsPolicy::settings()['mode'])->toBe('monitor')
        ->and(SmsPolicy::stored())->toBe(['mode' => 'monitor'])
        ->and($bad->getStatusCode())->toBe(1)
        ->and($bad->getDisplay())->toContain('Mode must be "enforce" or "monitor".');
});

test('sms:pause and sms:unpause manage a company pause by public id and list it', function () {
    sms_guard_commands_database();

    $pause = sms_guard_command_tester(new SmsGuardPause());
    $pause->execute(['scope' => 'company_acme', '--minutes' => '15', '--reason' => 'incident 42']);

    $listed = sms_guard_command_tester(new SmsGuardMode());
    $listed->execute([]);

    $paused = SmsGuard::make()->breaker()->pausedUntil('company:company-acme');

    $missing = sms_guard_command_tester(new SmsGuardPause());
    $missing->execute(['scope' => 'company_nobody']);

    $platform = sms_guard_command_tester(new SmsGuardPause());
    $platform->execute(['scope' => 'platform']);

    $unpause = sms_guard_command_tester(new SmsGuardUnpause());
    $unpause->execute(['scope' => 'company-acme']);

    $unpauseMissing = sms_guard_command_tester(new SmsGuardUnpause());
    $unpauseMissing->execute(['scope' => 'company_nobody']);

    expect($pause->getStatusCode())->toBe(0)
        ->and($pause->getDisplay())->toContain('SMS paused for company:company-acme until ')
        ->and($paused)->not->toBeNull()
        ->and($paused['reason'])->toBe('incident 42')
        ->and($paused['auto'])->toBeFalse()
        ->and($paused['until'])->toBeGreaterThan(time())
        ->and($listed->getDisplay())->toContain('company:company-acme')
        ->and($listed->getDisplay())->toContain('incident 42')
        ->and($missing->getStatusCode())->toBe(1)
        ->and($platform->getStatusCode())->toBe(0)
        ->and($platform->getDisplay())->toContain('SMS paused for platform until sms:unpause is run.')
        ->and(SmsGuard::make()->breaker()->pausedUntil('platform')['until'])->toBeNull()
        ->and($unpause->getStatusCode())->toBe(0)
        ->and(SmsGuard::make()->breaker()->pausedUntil('company:company-acme'))->toBeNull()
        ->and(SmsPolicy::settings()['pauses'])->not->toHaveKey('company:company-acme')
        ->and(SmsPolicy::settings()['pauses'])->toHaveKey('platform')
        ->and($unpauseMissing->getStatusCode())->toBe(1)
        ->and(SmsGuardPause::companyUuidFromScope('company:abc'))->toBe('abc')
        ->and(SmsGuardPause::companyUuidFromScope('platform'))->toBeNull();
});
