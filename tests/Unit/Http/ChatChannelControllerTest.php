<?php

use Fleetbase\Exceptions\FleetbaseRequestValidationException;
use Fleetbase\Expansions\Str as StrExpansion;
use Fleetbase\Http\Controllers\Api\v1\ChatChannelController as PublicChatChannelController;
use Fleetbase\Http\Controllers\Internal\v1\ChatChannelController;
use Fleetbase\Http\Controllers\Internal\v1\ChatMessageController;
use Fleetbase\Http\Controllers\Internal\v1\ChatReceiptController;
use Fleetbase\Http\Requests\CreateChatChannelRequest;
use Fleetbase\Http\Requests\UpdateChatChannelRequest;
use Fleetbase\Models\ChatAttachment;
use Fleetbase\Models\ChatChannel;
use Fleetbase\Models\ChatMessage;
use Fleetbase\Models\ChatParticipant;
use Fleetbase\Models\ChatReceipt;
use Fleetbase\Models\User;
use Illuminate\Container\Container;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\QueryException;
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

class ChatChannelControllerFilesystemFake implements Filesystem
{
    public function disk(string $name): self
    {
        return $this;
    }

    public function exists($path)
    {
        return true;
    }

    public function get($path)
    {
        return '';
    }

    public function readStream($path)
    {
        return false;
    }

    public function put($path, $contents, $options = [])
    {
        return true;
    }

    public function writeStream($path, $resource, array $options = [])
    {
        return true;
    }

    public function getVisibility($path)
    {
        return 'public';
    }

    public function setVisibility($path, $visibility)
    {
        return true;
    }

    public function prepend($path, $data)
    {
        return true;
    }

    public function append($path, $data)
    {
        return true;
    }

    public function delete($paths)
    {
        return true;
    }

    public function copy($from, $to)
    {
        return true;
    }

    public function move($from, $to)
    {
        return true;
    }

    public function size($path)
    {
        return 0;
    }

    public function lastModified($path)
    {
        return 0;
    }

    public function files($directory = null, $recursive = false)
    {
        return [];
    }

    public function allFiles($directory = null)
    {
        return [];
    }

    public function directories($directory = null, $recursive = false)
    {
        return [];
    }

    public function allDirectories($directory = null)
    {
        return [];
    }

    public function makeDirectory($path)
    {
        return true;
    }

    public function deleteDirectory($directory)
    {
        return true;
    }

    public function url(?string $path): string
    {
        return '/storage/' . ltrim((string) $path, '/');
    }

    public function temporaryUrl(?string $path, mixed $expiration): string
    {
        return $this->url($path) . '?temporary=1';
    }
}

class PublicChatChannelControllerRoute
{
    public object $controller;

    public function __construct(private string $method = 'query')
    {
        $this->controller = new class {
        };
    }

    public function getAction(?string $key = null): mixed
    {
        $action = [
            'controller' => PublicChatChannelController::class . '@' . $this->method,
        ];

        return $key ? $action[$key] ?? null : $action;
    }

    public function getActionMethod(): string
    {
        return $this->method;
    }

    public function uri(): string
    {
        return 'v1/chat-channels';
    }
}

class ChatChannelControllerNotificationDispatcherFake implements NotificationDispatcher
{
    public array $sent = [];

    public function send($notifiables, $notification): void
    {
        $this->sent[] = [$notifiables, $notification];
    }

    public function sendNow($notifiables, $notification, ?array $channels = null): void
    {
        $this->send($notifiables, $notification);
    }
}

class ChatReceiptControllerFailingModel
{
    public function __construct(private Throwable $exception)
    {
    }

    public function createRecordFromRequest(Request $request): never
    {
        throw $this->exception;
    }
}

class ChatMessageControllerFailingModel
{
    public function __construct(private Throwable $exception)
    {
    }

    public function createRecordFromRequest(Request $request, mixed $before = null, mixed $after = null): never
    {
        throw $this->exception;
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
    if (!Request::hasMacro('or')) {
        Request::macro('or', function (array $params = [], mixed $default = null): mixed {
            foreach ($params as $param) {
                if ($this->has($param)) {
                    return $this->input($param);
                }
            }

            return $default;
        });
    }
    Request::macro('getController', function () {
        return $this->route()?->controller;
    });

    $connection = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'api.cache.enabled'             => false,
        'database.default'              => 'mysql',
        'database.connections.mysql'    => $connection,
        'filesystems.default'           => 'local',
        'filesystems.disks.local.root'  => sys_get_temp_dir(),
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
    $container->instance('filesystem', new ChatChannelControllerFilesystemFake());
    $container->instance(NotificationDispatcher::class, new ChatChannelControllerNotificationDispatcherFake());
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
    $container->instance('db.schema', $schema);
    Facade::clearResolvedInstance('db.schema');
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
        $table->string('disk')->nullable();
        $table->string('path')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('directives', function ($table) {
        $table->string('uuid')->primary();
        $table->string('permission_uuid')->nullable()->index();
        $table->string('subject_uuid')->nullable();
        $table->string('subject_type')->nullable();
        $table->string('key')->nullable();
        $table->string('operator')->nullable();
        $table->string('value')->nullable();
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

function chat_message_controller(): ChatMessageController
{
    return new ChatMessageController();
}

function chat_receipt_controller(): ChatReceiptController
{
    return new ChatReceiptController();
}

function public_chat_channel_controller(): PublicChatChannelController
{
    return new PublicChatChannelController();
}

function chat_channel_controller_payload($resource): array
{
    return $resource->resolve(Request::create('/int/v1/chat-channels', 'GET'));
}

function public_chat_channel_controller_payload($resource): array
{
    return $resource->resolve(Request::create('/v1/chat-channels', 'GET'));
}

function public_chat_channel_create_request(array $input): CreateChatChannelRequest
{
    return CreateChatChannelRequest::create('/v1/chat-channels', 'POST', $input);
}

function public_chat_channel_update_request(array $input): UpdateChatChannelRequest
{
    return UpdateChatChannelRequest::create('/v1/chat-channels/chat_current', 'PUT', $input);
}

function public_chat_channel_query_request(array $query = []): Request
{
    $request = Request::create('/v1/chat-channels', 'GET', $query);
    $request->setRouteResolver(fn () => new PublicChatChannelControllerRoute());

    return $request;
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

test('internal chat channel create returns stable error response when persistence fails', function () {
    $capsule = chat_channel_controller_database();
    $capsule->getConnection('mysql')->getSchemaBuilder()->drop('chat_channels');

    $response = chat_channel_controller()->createRecord(Request::create('/int/v1/chat-channels', 'POST', [
        'chatChannel' => [
            'name'         => 'Dispatch Coordination',
            'participants' => ['user_active'],
        ],
    ]));

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true))->toHaveKey('errors')
        ->and($response->getData(true)['errors'][0])->toContain('no such table: chat_channels');
});

test('internal chat channel available participants excludes self existing participants and other companies', function () {
    chat_channel_controller_database();

    $response = chat_channel_controller()->getAvailableParticipants(Request::create('/int/v1/chat-channels/available-participants', 'GET', [
        'channel' => 'chat_current',
        'query'   => 'Extra',
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

test('internal chat channel aggregate unread count sums only channels for the current user', function () {
    $capsule    = chat_channel_controller_database();
    $connection = $capsule->getConnection('mysql');
    $now        = '2026-07-18 01:00:00';

    $connection->table('chat_channels')->insert([
        'uuid'            => 'channel-second',
        'public_id'       => 'chat_second',
        'company_uuid'    => 'company-1',
        'created_by_uuid' => 'user-active',
        'name'            => 'Second Channel',
        'created_at'      => $now,
        'updated_at'      => $now,
    ]);
    $connection->table('chat_participants')->insert([
        ['uuid' => 'participant-current-second', 'public_id' => 'participant_current_second', 'company_uuid' => 'company-1', 'chat_channel_uuid' => 'channel-second', 'user_uuid' => 'user-current', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'participant-active-second', 'public_id' => 'participant_active_second', 'company_uuid' => 'company-1', 'chat_channel_uuid' => 'channel-second', 'user_uuid' => 'user-active', 'created_at' => $now, 'updated_at' => $now],
    ]);
    $connection->table('chat_messages')->insert([
        ['uuid' => 'aggregate-read', 'company_uuid' => 'company-1', 'chat_channel_uuid' => 'channel-current', 'sender_uuid' => 'participant-active', 'content' => 'read', 'created_at' => '2026-07-18 01:01:00', 'updated_at' => $now],
        ['uuid' => 'aggregate-current-unread', 'company_uuid' => 'company-1', 'chat_channel_uuid' => 'channel-current', 'sender_uuid' => 'participant-active', 'content' => 'current unread', 'created_at' => '2026-07-18 01:02:00', 'updated_at' => $now],
        ['uuid' => 'aggregate-current-own', 'company_uuid' => 'company-1', 'chat_channel_uuid' => 'channel-current', 'sender_uuid' => 'participant-current', 'content' => 'own', 'created_at' => '2026-07-18 01:03:00', 'updated_at' => $now],
        ['uuid' => 'aggregate-second-unread', 'company_uuid' => 'company-1', 'chat_channel_uuid' => 'channel-second', 'sender_uuid' => 'participant-active-second', 'content' => 'second unread', 'created_at' => '2026-07-18 01:04:00', 'updated_at' => $now],
        ['uuid' => 'aggregate-other-company', 'company_uuid' => 'company-2', 'chat_channel_uuid' => 'channel-other-company', 'sender_uuid' => 'participant-other-company', 'content' => 'hidden', 'created_at' => '2026-07-18 01:05:00', 'updated_at' => $now],
    ]);
    $connection->table('chat_receipts')->insert([
        'uuid'              => 'aggregate-receipt-read',
        'company_uuid'      => 'company-1',
        'chat_message_uuid' => 'aggregate-read',
        'participant_uuid'  => 'participant-current',
        'read_at'           => $now,
        'created_at'        => $now,
        'updated_at'        => $now,
    ]);

    $request = Request::create('/int/v1/chat-channels/unread-count');
    $request->setUserResolver(fn () => User::query()->whereKey('user-current')->first());

    $response = chat_channel_controller()->getUnreadCount($request);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['unreadCount' => 2]);
});

test('internal chat message controller creates attachments and notifies participants', function () {
    chat_channel_controller_database();

    $response = chat_message_controller()->createRecord(Request::create('/int/v1/chat-messages', 'POST', [
        'chatMessage' => [
            'chat_channel_uuid' => 'channel-current',
            'sender_uuid'       => 'participant-current',
            'content'           => 'Internal message',
            'attachment_files'  => ['file-1', 'file-2'],
        ],
    ]));

    $message = ChatMessage::query()->where('content', 'Internal message')->firstOrFail();

    expect(chat_channel_controller_payload($response['chatMessage'])['content'])->toBe('Internal message')
        ->and($message->company_uuid)->toBe('company-1')
        ->and($message->sender_uuid)->toBe('participant-current')
        ->and(ChatAttachment::query()->where('chat_message_uuid', $message->uuid)->orderBy('file_uuid')->pluck('file_uuid')->all())->toBe(['file-1', 'file-2']);
});

test('internal chat message controller returns stable create error responses', function (Closure $exceptionFactory, array $expectedErrors) {
    chat_channel_controller_database();

    $controller        = chat_message_controller();
    $controller->model = new ChatMessageControllerFailingModel($exceptionFactory());

    $response = $controller->createRecord(Request::create('/int/v1/chat-messages', 'POST', [
        'chatMessage' => [
            'chat_channel_uuid' => 'missing-channel',
            'sender_uuid'       => 'participant-current',
            'content'           => 'Unsent message',
        ],
    ]));

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true))->toBe([
            'errors' => $expectedErrors,
        ]);
})->with([
    'validation failure' => [
        fn () => new FleetbaseRequestValidationException(['chat_channel_uuid' => ['The selected chat channel is invalid.']]),
        ['chat_channel_uuid' => ['The selected chat channel is invalid.']],
    ],
    'query failure' => [
        fn () => new QueryException('mysql', 'insert into chat_messages', [], new RuntimeException('constraint failed')),
        ['constraint failed (Connection: mysql, SQL: insert into chat_messages)'],
    ],
    'generic failure' => [
        fn () => new RuntimeException('message creation failed'),
        ['message creation failed'],
    ],
]);

test('internal chat receipt controller returns existing receipts and creates missing receipts', function () {
    $capsule    = chat_channel_controller_database();
    $connection = $capsule->getConnection('mysql');
    $now        = '2026-07-18 00:20:00';

    $connection->table('chat_messages')->insert([
        'uuid'              => 'message-internal-receipt',
        'public_id'         => 'message_internal_receipt',
        'company_uuid'      => 'company-1',
        'chat_channel_uuid' => 'channel-current',
        'sender_uuid'       => 'participant-active',
        'content'           => 'Internal receipt',
        'created_at'        => $now,
        'updated_at'        => $now,
    ]);
    $connection->table('chat_receipts')->insert([
        'uuid'              => 'receipt-existing',
        'company_uuid'      => 'company-1',
        'chat_message_uuid' => 'message-internal-receipt',
        'participant_uuid'  => 'participant-current',
        'read_at'           => $now,
        'created_at'        => $now,
        'updated_at'        => $now,
    ]);

    $existing = chat_receipt_controller()->createRecord(Request::create('/int/v1/chat-receipts', 'POST', [
        'chatReceipt' => [
            'chat_message_uuid' => 'message-internal-receipt',
            'participant_uuid'  => 'participant-current',
        ],
    ]));
    $created = chat_receipt_controller()->createRecord(Request::create('/int/v1/chat-receipts', 'POST', [
        'chatReceipt' => [
            'chat_message_uuid' => 'message-internal-receipt',
            'participant_uuid'  => 'participant-active',
        ],
    ]));

    expect($existing['chatReceipt']->resource->uuid)->toBe('receipt-existing')
        ->and($created['chatReceipt']->resource->chat_message_uuid)->toBe('message-internal-receipt')
        ->and($created['chatReceipt']->resource->participant_uuid)->toBe('participant-active')
        ->and(ChatReceipt::query()->where('chat_message_uuid', 'message-internal-receipt')->count())->toBe(2);
});

test('internal chat receipt controller returns stable create error responses', function (Closure $exceptionFactory, array $expectedErrors) {
    chat_channel_controller_database();

    $controller        = chat_receipt_controller();
    $controller->model = new ChatReceiptControllerFailingModel($exceptionFactory());

    $response = $controller->createRecord(Request::create('/int/v1/chat-receipts', 'POST', [
        'chatReceipt' => [
            'chat_message_uuid' => 'message-missing',
            'participant_uuid'  => 'participant-current',
        ],
    ]));

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true))->toBe([
            'errors' => $expectedErrors,
        ]);
})->with([
    'validation failure' => [
        fn () => new FleetbaseRequestValidationException(['chat_message_uuid' => ['The selected chat message is invalid.']]),
        ['chat_message_uuid' => ['The selected chat message is invalid.']],
    ],
    'query failure' => [
        fn () => new QueryException('mysql', 'insert into chat_receipts', [], new RuntimeException('constraint failed')),
        ['constraint failed (Connection: mysql, SQL: insert into chat_receipts)'],
    ],
    'generic failure' => [
        fn () => new RuntimeException('receipt creation failed'),
        ['receipt creation failed'],
    ],
]);

test('public chat channel creates channel with creator and valid participants only', function () {
    $capsule = chat_channel_controller_database();

    $response = public_chat_channel_controller()->create(public_chat_channel_create_request([
        'name'         => 'Public Dispatch',
        'participants' => [
            'user_active',
            'user_other',
            'missing_user',
        ],
    ]));

    $channel              = ChatChannel::query()->where('name', 'Public Dispatch')->firstOrFail();
    $participantUserUuids = ChatParticipant::query()
        ->where('chat_channel_uuid', $channel->uuid)
        ->orderBy('user_uuid')
        ->pluck('user_uuid')
        ->all();

    expect(public_chat_channel_controller_payload($response)['name'])->toBe('Public Dispatch')
        ->and($channel->company_uuid)->toBe('company-1')
        ->and($channel->created_by_uuid)->toBe('user-current')
        ->and($participantUserUuids)->toBe(['user-active', 'user-current', 'user-other-company'])
        ->and($capsule->getConnection('mysql')->table('chat_channels')->where('public_id', 'chat_other')->value('company_uuid'))->toBe('company-2');
});

test('public chat channel updates finds queries and deletes records with not found contracts', function () {
    chat_channel_controller_database();

    $updated = public_chat_channel_controller()->update('chat_current', public_chat_channel_update_request([
        'name' => 'Renamed Channel',
    ]));
    $missingUpdate = public_chat_channel_controller()->update('missing_chat', public_chat_channel_update_request([
        'name' => 'Missing Channel',
    ]));
    $found         = public_chat_channel_controller()->find('chat_current');
    $queried       = public_chat_channel_controller()->query(public_chat_channel_query_request());
    $deleted       = public_chat_channel_controller()->delete('chat_current');
    $missing       = public_chat_channel_controller()->find('chat_current');
    $missingDelete = public_chat_channel_controller()->delete('missing_chat');

    expect(public_chat_channel_controller_payload($updated)['name'])->toBe('Renamed Channel')
        ->and($missingUpdate->getStatusCode())->toBe(404)
        ->and($missingUpdate->getData(true))->toBe(['error' => 'Chat channel resource not found.'])
        ->and(public_chat_channel_controller_payload($found)['id'])->toBe('chat_current')
        ->and($queried->collection->pluck('public_id')->all())->toContain('chat_current')
        ->and($deleted->resource->public_id)->toBe('chat_current')
        ->and($missing->getStatusCode())->toBe(404)
        ->and($missing->getData(true))->toBe(['error' => 'Chat channel resource not found.'])
        ->and($missingDelete->getStatusCode())->toBe(404)
        ->and($missingDelete->getData(true))->toBe(['error' => 'Chat channel resource not found.']);
});

test('public chat channel available participants optionally excludes existing channel members', function () {
    chat_channel_controller_database();

    $withoutChannel = public_chat_channel_controller()->getAvailablePartificants(Request::create('/v1/chat-channels/available-participants'));
    $withChannel    = public_chat_channel_controller()->getAvailablePartificants(Request::create('/v1/chat-channels/available-participants', 'GET', [
        'channel' => 'chat_current',
    ]));

    expect($withoutChannel->collection->pluck('uuid')->all())->toBe(['user-current', 'user-active', 'user-extra'])
        ->and($withChannel->collection->pluck('uuid')->all())->toBe(['user-extra']);
});

test('public chat channel participant endpoints add remove and reject missing references', function () {
    chat_channel_controller_database();

    $added = public_chat_channel_controller()->addParticipant('chat_current', Request::create('/v1/chat-channels/chat_current/participants', 'POST', [
        'user' => 'user_extra',
    ]));
    $missingChannel = public_chat_channel_controller()->addParticipant('missing_chat', Request::create('/v1/chat-channels/missing_chat/participants', 'POST', [
        'user' => 'user_extra',
    ]));
    $missingUser = public_chat_channel_controller()->addParticipant('chat_current', Request::create('/v1/chat-channels/chat_current/participants', 'POST', [
        'user' => 'missing_user',
    ]));
    $removed            = public_chat_channel_controller()->removeParticipant($added->resource->public_id);
    $missingParticipant = public_chat_channel_controller()->removeParticipant('missing_participant');

    expect($added->resource->user_uuid)->toBe('user-extra')
        ->and($added->resource->chat_channel_uuid)->toBe('channel-current')
        ->and($missingChannel->getStatusCode())->toBe(404)
        ->and($missingChannel->getData(true))->toBe(['error' => 'Chat channel resource not found.'])
        ->and($missingUser->getStatusCode())->toBe(422)
        ->and($missingUser->getData(true))->toBe(['error' => 'User to add as participant not found.'])
        ->and($removed->resource->public_id)->toBe($added->resource->public_id)
        ->and(ChatParticipant::withTrashed()->whereKey($added->resource->uuid)->first()->trashed())->toBeTrue()
        ->and($missingParticipant->getStatusCode())->toBe(422)
        ->and($missingParticipant->getData(true))->toBe(['error' => 'Chat participant resource not found.']);
});

test('public chat channel messages create records reject missing references and delete safely', function () {
    chat_channel_controller_database();

    $sent = public_chat_channel_controller()->sendMessage('chat_current', Request::create('/v1/chat-channels/chat_current/messages', 'POST', [
        'sender'  => 'participant_current',
        'content' => 'Arrived at pickup',
    ]));
    $missingChannel = public_chat_channel_controller()->sendMessage('missing_chat', Request::create('/v1/chat-channels/missing_chat/messages', 'POST', [
        'sender'  => 'participant_current',
        'content' => 'No channel',
    ]));
    $missingSender = public_chat_channel_controller()->sendMessage('chat_current', Request::create('/v1/chat-channels/chat_current/messages', 'POST', [
        'sender'  => 'missing_participant',
        'content' => 'No sender',
    ]));
    $deleted       = public_chat_channel_controller()->deleteMessage($sent->resource->public_id);
    $missingDelete = public_chat_channel_controller()->deleteMessage('missing_message');

    expect($sent->resource->content)->toBe('Arrived at pickup')
        ->and($sent->resource->company_uuid)->toBe('company-1')
        ->and($sent->resource->chat_channel_uuid)->toBe('channel-current')
        ->and($sent->resource->sender_uuid)->toBe('participant-current')
        ->and($missingChannel->getStatusCode())->toBe(404)
        ->and($missingChannel->getData(true))->toBe(['error' => 'Chat channel resource not found.'])
        ->and($missingSender->getStatusCode())->toBe(422)
        ->and($missingSender->getData(true))->toBe(['error' => 'Sender of chat message not found.'])
        ->and($deleted->resource->public_id)->toBe($sent->resource->public_id)
        ->and(ChatMessage::withTrashed()->whereKey($sent->resource->uuid)->first()->trashed())->toBeTrue()
        ->and($missingDelete->getStatusCode())->toBe(404)
        ->and($missingDelete->getData(true))->toBe(['error' => 'Chat message resource not found.']);
});

test('public chat channel messages attach files and roll back when an attachment is missing', function () {
    $capsule    = chat_channel_controller_database();
    $connection = $capsule->getConnection('mysql');
    $now        = '2026-07-18 00:12:00';

    $connection->table('files')->insert([
        'uuid'         => 'file-valid',
        'public_id'    => 'file_valid',
        'company_uuid' => 'company-1',
        'disk'         => 'local',
        'path'         => 'chat/file-valid.txt',
        'created_at'   => $now,
        'updated_at'   => $now,
    ]);

    $sent = public_chat_channel_controller()->sendMessage('chat_current', Request::create('/v1/chat-channels/chat_current/messages', 'POST', [
        'sender'  => 'participant_current',
        'content' => 'See attached POD',
        'files'   => ['file_valid'],
    ]));
    $failed = public_chat_channel_controller()->sendMessage('chat_current', Request::create('/v1/chat-channels/chat_current/messages', 'POST', [
        'sender'  => 'participant_current',
        'content' => 'Missing attachment',
        'files'   => ['missing_file'],
    ]));

    expect($sent->resource->content)->toBe('See attached POD')
        ->and(ChatAttachment::query()->where('chat_message_uuid', $sent->resource->uuid)->first())->not->toBeNull()
        ->and(ChatAttachment::query()->where('chat_message_uuid', $sent->resource->uuid)->value('file_uuid'))->toBe('file-valid')
        ->and($failed->getStatusCode())->toBe(400)
        ->and($failed->getData(true))->toBe(['error' => 'Attachment file not found.'])
        ->and(ChatMessage::withTrashed()->where('content', 'Missing attachment')->first()?->trashed())->toBeTrue();
});

test('public chat channel read receipts are idempotent and validate references', function () {
    $capsule    = chat_channel_controller_database();
    $connection = $capsule->getConnection('mysql');
    $now        = '2026-07-18 00:15:00';
    $connection->table('chat_messages')->insert([
        'uuid'              => 'message-receipt',
        'public_id'         => 'message_receipt',
        'company_uuid'      => 'company-1',
        'chat_channel_uuid' => 'channel-current',
        'sender_uuid'       => 'participant-active',
        'content'           => 'Please acknowledge',
        'created_at'        => $now,
        'updated_at'        => $now,
    ]);

    $created = public_chat_channel_controller()->createReadReceipt('message_receipt', Request::create('/v1/chat-messages/message_receipt/read-receipts', 'POST', [
        'participant' => 'participant_current',
    ]));
    $existing = public_chat_channel_controller()->createReadReceipt('message_receipt', Request::create('/v1/chat-messages/message_receipt/read-receipts', 'POST', [
        'participant' => 'participant_current',
    ]));
    $invalid = public_chat_channel_controller()->createReadReceipt('missing_message', Request::create('/v1/chat-messages/missing_message/read-receipts', 'POST', [
        'participant' => 'participant_current',
    ]));

    expect($created->resource->chat_message_uuid)->toBe('message-receipt')
        ->and($created->resource->participant_uuid)->toBe('participant-current')
        ->and($existing->resource->uuid)->toBe($created->resource->uuid)
        ->and(ChatReceipt::query()->where('chat_message_uuid', 'message-receipt')->where('participant_uuid', 'participant-current')->count())->toBe(1)
        ->and($invalid->getStatusCode())->toBe(404)
        ->and($invalid->getData(true))->toBe(['error' => 'Invalid message or participant reference.']);
});

test('public chat channel read receipt reports insert failures with debug details', function () {
    $capsule    = chat_channel_controller_database();
    $connection = $capsule->getConnection('mysql');
    $now        = '2026-07-18 00:25:00';

    $connection->table('chat_messages')->insert([
        'uuid'              => 'message-receipt-failure',
        'public_id'         => 'message_receipt_failure',
        'company_uuid'      => 'company-1',
        'chat_channel_uuid' => 'channel-current',
        'sender_uuid'       => 'participant-active',
        'content'           => 'Receipt failure',
        'created_at'        => $now,
        'updated_at'        => $now,
    ]);
    $connection->statement("CREATE TRIGGER block_chat_receipt_insert BEFORE INSERT ON chat_receipts BEGIN SELECT RAISE(ABORT, 'receipt insert blocked'); END");

    $response = public_chat_channel_controller()->createReadReceipt('message_receipt_failure', Request::create('/v1/chat-messages/message_receipt_failure/read-receipts', 'POST', [
        'participant' => 'participant_current',
    ]));

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getData(true)['error'])->toContain('receipt insert blocked');
});
