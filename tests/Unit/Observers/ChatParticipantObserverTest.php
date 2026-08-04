<?php

use Fleetbase\Expansions\Str as StrExpansion;
use Fleetbase\Models\ChatLog;
use Fleetbase\Models\ChatParticipant;
use Fleetbase\Observers\ChatParticipantObserver;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str as SupportStr;

class ChatParticipantObserverCacheFake
{
    public function tags(array $tags): self
    {
        return $this;
    }

    public function flush(): bool
    {
        return true;
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

    public function rememberForever(string $key, Closure $callback): mixed
    {
        return $callback();
    }
}

class ChatParticipantObserverResponseCacheFake
{
    public function clear(): void
    {
    }
}

if (!function_exists('event')) {
    function event(mixed $event = null): mixed
    {
        return $event;
    }
}

function chat_participant_observer_database(): Capsule
{
    EloquentModel::clearBootedModels();

    if (!SupportStr::hasMacro('humanize')) {
        $strExpansion = new StrExpansion();
        SupportStr::macro('humanize', $strExpansion->humanize());
    }

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'api.cache.enabled'             => false,
        'database.default'              => 'testing',
        'database.connections.testing'  => $connection,
        'database.connections.mysql'    => $connection,
        'fleetbase.connection.db'       => 'testing',
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'testing');
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('testing');
    $container->instance('db', $databaseManager);
    $container->instance('responsecache', new ChatParticipantObserverResponseCacheFake());
    Cache::swap(new ChatParticipantObserverCacheFake());
    Facade::clearResolvedInstance('db');
    Facade::clearResolvedInstance('schema');

    $schema      = $capsule->getConnection('testing')->getSchemaBuilder();
    $mysqlSchema = $capsule->getConnection('mysql')->getSchemaBuilder();
    foreach ([$schema, $mysqlSchema] as $connectionSchema) {
        $connectionSchema->create('users', function ($table) {
            $table->string('uuid')->primary();
            $table->string('public_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
    $schema->create('chat_channels', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('created_by_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('slug')->nullable();
        $table->json('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('chat_participants', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('chat_channel_uuid')->nullable();
        $table->string('user_uuid')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('chat_logs', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('chat_channel_uuid')->nullable();
        $table->string('initiator_uuid')->nullable();
        $table->string('event_type')->nullable();
        $table->text('content')->nullable();
        $table->json('subjects')->nullable();
        $table->json('meta')->nullable();
        $table->string('status')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    $now   = '2026-07-18 10:00:00';
    $db    = $capsule->getConnection('testing');
    $users = [
        ['uuid' => 'user-owner', 'public_id' => 'user_owner_public', 'name' => 'Owner User', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'user-added', 'public_id' => 'user_added_public', 'name' => 'Added User', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'user-removed', 'public_id' => 'user_removed_public', 'name' => 'Removed User', 'created_at' => $now, 'updated_at' => $now],
    ];
    $db->table('users')->insert($users);
    $capsule->getConnection('mysql')->table('users')->insert($users);
    $db->table('chat_channels')->insert([
        'uuid'            => 'channel-1',
        'public_id'       => 'chat_channel_public_1',
        'company_uuid'    => 'company-1',
        'created_by_uuid' => 'user-owner',
        'name'            => 'Dispatch Chat',
        'created_at'      => $now,
        'updated_at'      => $now,
    ]);
    $db->table('chat_participants')->insert([
        ['uuid' => 'participant-owner', 'public_id' => 'chat_participant_owner', 'company_uuid' => 'company-1', 'chat_channel_uuid' => 'channel-1', 'user_uuid' => 'user-owner', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'participant-added', 'public_id' => 'chat_participant_added', 'company_uuid' => 'company-1', 'chat_channel_uuid' => 'channel-1', 'user_uuid' => 'user-added', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'participant-removed', 'public_id' => 'chat_participant_removed', 'company_uuid' => 'company-1', 'chat_channel_uuid' => 'channel-1', 'user_uuid' => 'user-removed', 'created_at' => $now, 'updated_at' => $now],
    ]);

    return $capsule;
}

function chat_participant_observer_subject(): ChatParticipantObserver
{
    chat_participant_observer_database();

    return new ChatParticipantObserver();
}

afterEach(function () {
    session()->flush();
    if (app()->bound('config')) {
        config([
            'database.default'        => 'mysql',
            'fleetbase.connection.db' => 'mysql',
        ]);
    }
    Facade::clearResolvedInstances();
});

it('logs added participants using the active session participant as initiator', function () {
    $observer = chat_participant_observer_subject();
    session(['user' => 'user-owner']);

    $observer->created(ChatParticipant::query()->whereKey('participant-added')->firstOrFail());

    $log = ChatLog::query()->firstOrFail();

    expect($log->event_type)->toBe('added_participant')
        ->and($log->initiator_uuid)->toBe('participant-owner')
        ->and($log->chat_channel_uuid)->toBe('channel-1')
        ->and($log->subjects)->toBe(['user:user-owner', 'user:user-added'])
        ->and($log->content)->toBe('{subject.0.name} has added {subject.1.name} to this chat.')
        ->and($log->status)->toBe('complete');
});

it('logs removed participants using the active session participant when someone else is removed', function () {
    $observer = chat_participant_observer_subject();
    session(['user' => 'user-owner']);

    $observer->deleted(ChatParticipant::query()->whereKey('participant-removed')->firstOrFail());

    $log = ChatLog::query()->firstOrFail();

    expect($log->event_type)->toBe('removed_participant')
        ->and($log->initiator_uuid)->toBe('participant-owner')
        ->and($log->subjects)->toBe(['user:user-owner', 'user:user-removed'])
        ->and($log->content)->toBe('{subject.0.name} has removed {subject.1.name} from this chat.');
});

it('logs self-removal as leaving the chat when the removed participant is the active user', function () {
    $observer = chat_participant_observer_subject();
    session(['user' => 'user-removed']);

    $observer->deleted(ChatParticipant::query()->whereKey('participant-removed')->firstOrFail());

    $log = ChatLog::query()->firstOrFail();

    expect($log->event_type)->toBe('removed_participant')
        ->and($log->initiator_uuid)->toBe('participant-removed')
        ->and($log->subjects)->toBe(['user:user-removed'])
        ->and($log->content)->toBe('{subject.0.name} has left the chat.');
});
