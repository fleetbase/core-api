<?php

use Fleetbase\Expansions\Str as StrExpansion;
use Fleetbase\Http\Controllers\Internal\v1\ChatChannelController;
use Fleetbase\Models\ChatChannel;
use Fleetbase\Models\ChatParticipant;
use Fleetbase\Models\User;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str as SupportStr;

class ChatChannelControllerContainer extends FleetbaseTestContainer
{
    public function hasDebugModeEnabled(): bool
    {
        return true;
    }
}

class ChatChannelControllerTaggedCacheFake
{
    public function tags(array|string $tags): self
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

    public function delete(string $key): bool
    {
        return true;
    }

    public function rememberForever(string $key, Closure $callback): mixed
    {
        return $callback();
    }
}

class ChatChannelControllerResponseCacheFake
{
    public function clear(): void
    {
    }
}

if (!function_exists('event')) {
    function event(mixed $event = null): mixed
    {
        if (array_key_exists('webhook_events_observer_events', $GLOBALS)) {
            $GLOBALS['webhook_events_observer_events'][] = $event;
        }

        if (array_key_exists('trigger_public_notification_broadcast_events', $GLOBALS)) {
            $GLOBALS['trigger_public_notification_broadcast_events'][] = $event;
        }

        return $event;
    }
}

function chat_channel_controller_database(): Capsule
{
    EloquentModel::clearBootedModels();
    Container::setInstance(new ChatChannelControllerContainer());
    $_SERVER['REQUEST_METHOD'] = 'GET';

    if (!SupportStr::hasMacro('humanize')) {
        $strExpansion = new StrExpansion();
        SupportStr::macro('humanize', $strExpansion->humanize());
    }

    if (!Request::hasMacro('array')) {
        Request::macro('array', function (string $key, array $default = []): array {
            $value = $this->input($key, $default);

            return is_array($value) ? $value : $default;
        });
    }

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'api.cache.enabled'             => false,
        'database.default'              => 'mysql',
        'database.connections.mysql'    => $connection,
        'fleetbase.connection.db'       => 'mysql',
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connection, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('mysql');
    $container->instance('db', $databaseManager);
    $container->instance('responsecache', new ChatChannelControllerResponseCacheFake());
    Cache::swap(new ChatChannelControllerTaggedCacheFake());
    Facade::clearResolvedInstance('db');
    Facade::clearResolvedInstance('schema');

    session()->flush();
    session([
        'company' => 'company-1',
        'user'    => 'user-current',
    ]);

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->index();
        $table->string('company_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('username')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('type')->nullable();
        $table->timestamp('last_seen_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('company_users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->index();
        $table->string('user_uuid')->index();
        $table->string('status')->nullable();
        $table->boolean('external')->default(false);
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('chat_channels', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->index();
        $table->string('company_uuid')->nullable()->index();
        $table->string('created_by_uuid')->nullable();
        $table->string('name')->nullable();
        $table->string('slug')->nullable();
        $table->json('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('chat_participants', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable()->index();
        $table->string('company_uuid')->nullable()->index();
        $table->string('chat_channel_uuid')->nullable()->index();
        $table->string('user_uuid')->nullable()->index();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('chat_messages', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('chat_channel_uuid')->nullable()->index();
        $table->string('sender_uuid')->nullable()->index();
        $table->text('content')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('chat_receipts', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('chat_message_uuid')->nullable()->index();
        $table->string('participant_uuid')->nullable()->index();
        $table->dateTime('read_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('chat_attachments', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('chat_channel_uuid')->nullable();
        $table->string('chat_message_uuid')->nullable();
        $table->string('sender_uuid')->nullable();
        $table->string('file_uuid')->nullable();
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
    $schema->create('files', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    $now = '2026-07-18 00:00:00';
    $capsule->getConnection('mysql')->table('users')->insert([
        ['uuid' => 'user-current', 'public_id' => 'user_current', 'company_uuid' => 'company-1', 'name' => 'Current User', 'username' => 'current', 'email' => 'current@example.test', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'user-active', 'public_id' => 'user_active', 'company_uuid' => 'company-1', 'name' => 'Active User', 'username' => 'active', 'email' => 'active@example.test', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'user-extra', 'public_id' => 'user_extra', 'company_uuid' => 'company-1', 'name' => 'Extra User', 'username' => 'extra', 'email' => 'extra@example.test', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'user-other-company', 'public_id' => 'user_other', 'company_uuid' => 'company-2', 'name' => 'Other Company', 'username' => 'other', 'email' => 'other@example.test', 'created_at' => $now, 'updated_at' => $now],
    ]);
    $capsule->getConnection('mysql')->table('company_users')->insert([
        ['uuid' => 'company-user-current', 'company_uuid' => 'company-1', 'user_uuid' => 'user-current', 'status' => 'active', 'external' => false, 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'company-user-active', 'company_uuid' => 'company-1', 'user_uuid' => 'user-active', 'status' => 'active', 'external' => false, 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'company-user-extra', 'company_uuid' => 'company-1', 'user_uuid' => 'user-extra', 'status' => 'active', 'external' => false, 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'company-user-other', 'company_uuid' => 'company-2', 'user_uuid' => 'user-other-company', 'status' => 'active', 'external' => false, 'created_at' => $now, 'updated_at' => $now],
    ]);
    $capsule->getConnection('mysql')->table('chat_channels')->insert([
        ['uuid' => 'channel-current', 'public_id' => 'chat_current', 'company_uuid' => 'company-1', 'created_by_uuid' => 'user-current', 'name' => 'Current Channel', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'channel-other-company', 'public_id' => 'chat_other', 'company_uuid' => 'company-2', 'created_by_uuid' => 'user-other-company', 'name' => 'Other Channel', 'created_at' => $now, 'updated_at' => $now],
    ]);
    $capsule->getConnection('mysql')->table('chat_participants')->insert([
        ['uuid' => 'participant-current', 'public_id' => 'participant_current', 'company_uuid' => 'company-1', 'chat_channel_uuid' => 'channel-current', 'user_uuid' => 'user-current', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'participant-active', 'public_id' => 'participant_active', 'company_uuid' => 'company-1', 'chat_channel_uuid' => 'channel-current', 'user_uuid' => 'user-active', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'participant-other-company', 'public_id' => 'participant_other', 'company_uuid' => 'company-2', 'chat_channel_uuid' => 'channel-other-company', 'user_uuid' => 'user-other-company', 'created_at' => $now, 'updated_at' => $now],
    ]);

    return $capsule;
}

function chat_channel_controller(): ChatChannelController
{
    return new ChatChannelController();
}

function chat_channel_controller_payload($resource): array
{
    return $resource->resolve(Request::create('/int/v1/chat-channels', 'GET'));
}

function chat_channel_controller_reflect(string $method, string $id): mixed
{
    $reflection = new ReflectionMethod(ChatChannelController::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke(chat_channel_controller(), $id);
}

afterEach(function () {
    session()->flush();
    config([
        'api.cache.enabled'       => null,
        'database.default'        => null,
        'database.connections'    => [],
        'fleetbase.connection.db' => null,
    ]);
    EloquentModel::clearBootedModels();
    Container::setInstance(new FleetbaseTestContainer());
    Facade::clearResolvedInstances();
});

test('internal chat channel create scopes initial participants to active company and skips current user', function () {
    $capsule = chat_channel_controller_database();

    $response = chat_channel_controller()->createRecord(Request::create('/int/v1/chat-channels', 'POST', [
        'chatChannel' => [
            'name' => 'Dispatch Coordination',
            'meta' => [
                'priority' => 'high',
            ],
            'participants' => [
                'user_current',
                'user_active',
                'user_other',
                'missing_user',
            ],
        ],
    ]));

    $channel              = ChatChannel::query()->where('name', 'Dispatch Coordination')->firstOrFail();
    $participantUserUuids = ChatParticipant::query()
        ->where('chat_channel_uuid', $channel->uuid)
        ->orderBy('user_uuid')
        ->pluck('user_uuid')
        ->all();

    expect(chat_channel_controller_payload($response)['name'])->toBe('Dispatch Coordination')
        ->and($channel->company_uuid)->toBe('company-1')
        ->and($channel->created_by_uuid)->toBe('user-current')
        ->and($channel->meta)->toBe(['priority' => 'high'])
        ->and($participantUserUuids)->toBe(['user-active', 'user-current'])
        ->and($capsule->getConnection('mysql')->table('chat_participants')->where('chat_channel_uuid', $channel->uuid)->where('user_uuid', 'user-other-company')->exists())->toBeFalse();
});

test('internal chat channel available participants excludes self existing participants and other companies', function () {
    chat_channel_controller_database();

    $response = chat_channel_controller()->getAvailableParticipants(Request::create('/int/v1/chat-channels/available-participants', 'GET', [
        'channel' => 'chat_current',
    ]));

    expect($response->collection->pluck('uuid')->all())->toBe(['user-extra']);
});

test('internal chat channel scoped lookup helpers resolve only active company users and channels', function () {
    chat_channel_controller_database();

    $activeUser    = chat_channel_controller_reflect('companyUserQuery', 'user_active')->first();
    $otherUser     = chat_channel_controller_reflect('companyUserQuery', 'user_other')->first();
    $activeChannel = chat_channel_controller_reflect('companyChatChannelQuery', 'chat_current')->first();
    $otherChannel  = chat_channel_controller_reflect('companyChatChannelQuery', 'chat_other')->first();

    expect($activeUser)->not->toBeNull()
        ->and($activeUser->uuid)->toBe('user-active')
        ->and($otherUser)->toBeNull()
        ->and($activeChannel)->not->toBeNull()
        ->and($activeChannel->uuid)->toBe('channel-current')
        ->and($otherChannel)->toBeNull();
});

test('internal chat channel unread count requires active company channel and participant membership', function () {
    $capsule    = chat_channel_controller_database();
    $connection = $capsule->getConnection('mysql');
    $now        = '2026-07-18 00:05:00';

    $connection->table('chat_messages')->insert([
        ['uuid' => 'message-read', 'company_uuid' => 'company-1', 'chat_channel_uuid' => 'channel-current', 'sender_uuid' => 'participant-active', 'content' => 'read', 'created_at' => '2026-07-18 00:06:00', 'updated_at' => $now],
        ['uuid' => 'message-unread', 'company_uuid' => 'company-1', 'chat_channel_uuid' => 'channel-current', 'sender_uuid' => 'participant-active', 'content' => 'unread', 'created_at' => '2026-07-18 00:07:00', 'updated_at' => $now],
        ['uuid' => 'message-own', 'company_uuid' => 'company-1', 'chat_channel_uuid' => 'channel-current', 'sender_uuid' => 'participant-current', 'content' => 'own', 'created_at' => '2026-07-18 00:08:00', 'updated_at' => $now],
    ]);
    $connection->table('chat_receipts')->insert([
        'uuid'              => 'receipt-read',
        'company_uuid'      => 'company-1',
        'chat_message_uuid' => 'message-read',
        'participant_uuid'  => 'participant-current',
        'read_at'           => $now,
        'created_at'        => $now,
        'updated_at'        => $now,
    ]);

    $request = Request::create('/int/v1/chat-channels/channel-current/unread-count');
    $request->setUserResolver(fn () => User::query()->whereKey('user-current')->first());

    $success        = chat_channel_controller()->getUnreadCountForChannel('channel-current', $request);
    $notParticipant = chat_channel_controller()->getUnreadCountForChannel('channel-other-company', $request);

    expect($success->getStatusCode())->toBe(200)
        ->and($success->getData(true))->toBe(['unreadCount' => 1])
        ->and($notParticipant->getStatusCode())->toBe(404)
        ->and($notParticipant->getData(true))->toBe(['error' => 'Chat channel not found.']);
});
