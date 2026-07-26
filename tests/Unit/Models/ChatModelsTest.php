<?php

use Fleetbase\Expansions\Str as StrExpansion;
use Fleetbase\Models\ChatAttachment;
use Fleetbase\Models\ChatChannel;
use Fleetbase\Models\ChatLog;
use Fleetbase\Models\ChatMessage;
use Fleetbase\Models\ChatParticipant;
use Fleetbase\Models\ChatReceipt;
use Fleetbase\Models\Company;
use Fleetbase\Models\File;
use Fleetbase\Models\User;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str as SupportStr;

class ChatModelsTaggedCacheFake
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

    public function delete(string $key): bool
    {
        return true;
    }

    public function rememberForever(string $key, Closure $callback): mixed
    {
        return $callback();
    }
}

class ChatModelsResponseCacheFake
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

function chat_models_database(): Capsule
{
    EloquentModel::clearBootedModels();

    if (!SupportStr::hasMacro('humanize')) {
        $strExpansion = new StrExpansion();
        SupportStr::macro('humanize', $strExpansion->humanize());
    }

    $connectionConfig = [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ];

    $container = bind_test_container([
        'api.cache.enabled'            => false,
        'database.default'             => 'testing',
        'database.connections.testing' => $connectionConfig,
        'database.connections.mysql'   => $connectionConfig,
        'fleetbase.connection.db'      => 'testing',
    ]);

    $capsule = new Capsule($container);
    $capsule->addConnection($connectionConfig, 'testing');
    $capsule->addConnection($connectionConfig, 'mysql');
    $capsule->setEventDispatcher(new Dispatcher($container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $databaseManager = $capsule->getDatabaseManager();
    $databaseManager->setDefaultConnection('testing');
    $container->instance('db', $databaseManager);
    $container->instance('responsecache', new ChatModelsResponseCacheFake());
    Cache::swap(new ChatModelsTaggedCacheFake());
    Facade::clearResolvedInstance('db');
    Facade::clearResolvedInstance('schema');

    $schema      = $capsule->getConnection('testing')->getSchemaBuilder();
    $mysqlSchema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $mysqlSchema->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('name')->nullable();
        $table->timestamp('last_seen_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

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
    $schema->create('chat_messages', function ($table) {
        $table->string('uuid')->primary();
        $table->string('public_id')->nullable();
        $table->string('company_uuid')->nullable();
        $table->string('chat_channel_uuid')->nullable();
        $table->string('sender_uuid')->nullable();
        $table->text('content')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('chat_receipts', function ($table) {
        $table->string('uuid')->primary();
        $table->string('company_uuid')->nullable();
        $table->string('chat_message_uuid')->nullable();
        $table->string('participant_uuid')->nullable();
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

    return $capsule;
}

function chat_participant_with_name(string $uuid, ?string $name): ChatParticipant
{
    $participant = new ChatParticipant();
    $participant->setRawAttributes(['uuid' => $uuid], true);
    $participant->setRelation('user', null);

    if ($name !== null) {
        $user = new User();
        $user->setRawAttributes(['uuid' => 'user-' . $uuid, 'name' => $name], true);
        $participant->setRelation('user', $user);
    }

    return $participant;
}

it('derives chat channel titles from explicit names loaded participants and empty fallbacks', function () {
    bind_test_container();

    $named = new ChatChannel();
    $named->setRawAttributes(['name' => 'Dispatch Room'], true);

    $participantNamed = new ChatChannel();
    $participantNamed->setRawAttributes(['name' => null], true);
    $participantNamed->setRelation('participants', collect([
        chat_participant_with_name('participant-1', 'Ada'),
        chat_participant_with_name('participant-2', 'Grace'),
        chat_participant_with_name('participant-3', null),
        chat_participant_with_name('participant-4', 'Linus'),
        chat_participant_with_name('participant-5', 'Barbara'),
        chat_participant_with_name('participant-6', 'Ignored'),
    ]));

    $untitled = new ChatChannel();
    $untitled->setRawAttributes(['name' => null], true);
    $untitled->setRelation('participants', collect([
        chat_participant_with_name('participant-7', null),
    ]));

    expect($named->title)->toBe('Dispatch Room')
        ->and($participantNamed->title)->toBe('Ada, Grace, Linus, Barbara')
        ->and($untitled->title)->toBe('Untitled Chat');
});

it('derives chat channel title fallbacks from database backed participants when relation is not loaded', function () {
    $capsule    = chat_models_database();
    $testing    = $capsule->getConnection('testing');
    $timestamps = ['created_at' => now(), 'updated_at' => now()];

    $testing->getSchemaBuilder()->create('users', function ($table) {
        $table->string('uuid')->primary();
        $table->string('name')->nullable();
        $table->timestamp('last_seen_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $testing->table('users')->insert([
        ['uuid' => 'user-db-1', 'name' => 'Katherine', ...$timestamps],
        ['uuid' => 'user-db-2', 'name' => 'Dorothy', ...$timestamps],
    ]);
    $testing->table('chat_channels')->insert([
        'uuid' => 'channel-db-title',
        'name' => null,
        ...$timestamps,
    ]);
    $testing->table('chat_participants')->insert([
        ['uuid' => 'participant-db-1', 'chat_channel_uuid' => 'channel-db-title', 'user_uuid' => 'user-db-1', ...$timestamps],
        ['uuid' => 'participant-db-2', 'chat_channel_uuid' => 'channel-db-title', 'user_uuid' => 'user-db-2', ...$timestamps],
    ]);

    $channel = ChatChannel::query()->whereKey('channel-db-title')->firstOrFail();

    expect($channel->relationLoaded('participants'))->toBeFalse()
        ->and($channel->title)->toBe('Untitled Chat');
});

it('counts unread chat messages by participant receipts and ignores sender messages', function () {
    $capsule    = chat_models_database();
    $connection = $capsule->getConnection('testing');
    $now        = '2026-06-01 12:00:00';

    $connection->table('chat_channels')->insert([
        'uuid'         => 'channel-1',
        'company_uuid' => 'company-1',
        'created_at'   => $now,
        'updated_at'   => $now,
    ]);
    $connection->table('chat_participants')->insert([
        ['uuid' => 'participant-reader', 'chat_channel_uuid' => 'channel-1', 'user_uuid' => 'user-reader', 'created_at' => $now, 'updated_at' => $now],
        ['uuid' => 'participant-sender', 'chat_channel_uuid' => 'channel-1', 'user_uuid' => 'user-sender', 'created_at' => $now, 'updated_at' => $now],
    ]);
    $connection->table('chat_messages')->insert([
        ['uuid' => 'message-read', 'chat_channel_uuid' => 'channel-1', 'sender_uuid' => 'participant-sender', 'content' => 'already read', 'created_at' => '2026-06-01 12:01:00', 'updated_at' => $now],
        ['uuid' => 'message-unread', 'chat_channel_uuid' => 'channel-1', 'sender_uuid' => 'participant-sender', 'content' => 'needs attention', 'created_at' => '2026-06-01 12:02:00', 'updated_at' => $now],
        ['uuid' => 'message-own', 'chat_channel_uuid' => 'channel-1', 'sender_uuid' => 'participant-reader', 'content' => 'own message', 'created_at' => '2026-06-01 12:03:00', 'updated_at' => $now],
    ]);
    $connection->table('chat_receipts')->insert([
        'uuid'              => 'receipt-1',
        'chat_message_uuid' => 'message-read',
        'participant_uuid'  => 'participant-reader',
        'read_at'           => $now,
        'created_at'        => $now,
        'updated_at'        => $now,
    ]);

    $channel = ChatChannel::query()->whereKey('channel-1')->firstOrFail();
    $reader  = ChatParticipant::query()->whereKey('participant-reader')->firstOrFail();
    $user    = new User();
    $user->setRawAttributes(['uuid' => 'user-reader'], true);

    expect($channel->getUnreadMessageCountForUser($user))->toBe(1)
        ->and($channel->getUnreadMessagesForUser($user)->pluck('uuid')->all())->toBe(['message-unread'])
        ->and($channel->getUnreadMessagesForParticipant($reader)->pluck('uuid')->all())->toBe(['message-unread']);

    $missingUser = new User();
    $missingUser->setRawAttributes(['uuid' => 'missing-user'], true);

    expect($channel->getUnreadMessagesForUser($missingUser))->toHaveCount(0)
        ->and($channel->getUnreadMessageCountForUser($missingUser))->toBe(0);
});

it('silently skips chat message notifications when no channel is available', function () {
    chat_models_database();

    $message = new ChatMessage([
        'uuid'              => 'message-without-channel',
        'chat_channel_uuid' => null,
        'sender_uuid'       => 'participant-1',
        'content'           => 'No channel yet',
    ]);

    $message->notifyParticipants();

    expect($message->chatChannel)->toBeNull();
});

it('sets chat receipt read timestamps and exposes participant names', function () {
    chat_models_database();
    Carbon::setTestNow(Carbon::parse('2026-06-02 08:15:00', 'UTC'));

    $connection = Capsule::connection('testing');
    $connection->table('chat_participants')->insert([
        'uuid'              => 'participant-1',
        'public_id'         => 'chat_participant_public_1',
        'chat_channel_uuid' => 'channel-1',
        'user_uuid'         => 'user-1',
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);
    $connection->table('chat_messages')->insert([
        'uuid'              => 'message-1',
        'public_id'         => 'chat_message_public_1',
        'chat_channel_uuid' => 'channel-1',
        'sender_uuid'       => 'participant-1',
        'content'           => 'read this',
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);

    $receipt = new ChatReceipt();
    $receipt->forceFill([
        'uuid'              => 'receipt-1',
        'chat_message_uuid' => 'message-1',
        'participant_uuid'  => 'participant-1',
    ]);
    $receipt->save();

    $participant = chat_participant_with_name('participant-1', 'Ada Lovelace');
    $receipt->setRelation('participant', $participant);

    expect($receipt->read_at->toDateTimeString())->toBe('2026-06-02 08:15:00')
        ->and($receipt->participant_name)->toBe('Ada Lovelace');

    Carbon::setTestNow();
});

it('combines chat messages attachments and logs into chronological feed entries', function () {
    $capsule    = chat_models_database();
    $connection = $capsule->getConnection('testing');

    $connection->table('chat_channels')->insert([
        'uuid'       => 'channel-1',
        'created_at' => '2026-06-03 09:00:00',
        'updated_at' => '2026-06-03 09:00:00',
    ]);
    $connection->table('chat_messages')->insert([
        'uuid'              => 'message-1',
        'chat_channel_uuid' => 'channel-1',
        'content'           => 'message',
        'created_at'        => '2026-06-03 09:02:00',
        'updated_at'        => '2026-06-03 09:02:00',
    ]);
    $connection->table('chat_attachments')->insert([
        'uuid'              => 'attachment-1',
        'chat_channel_uuid' => 'channel-1',
        'chat_message_uuid' => null,
        'created_at'        => '2026-06-03 09:01:00',
        'updated_at'        => '2026-06-03 09:01:00',
    ]);
    $connection->table('chat_logs')->insert([
        'uuid'              => 'log-1',
        'chat_channel_uuid' => 'channel-1',
        'event_type'        => 'participant_added',
        'content'           => 'log',
        'created_at'        => '2026-06-03 09:03:00',
        'updated_at'        => '2026-06-03 09:03:00',
    ]);

    $channel = ChatChannel::query()->whereKey('channel-1')->firstOrFail();
    $feed    = $channel->feed;

    expect($feed->pluck('type')->all())->toBe(['attachment', 'message', 'log'])
        ->and($feed[0]['data'])->toBeInstanceOf(ChatAttachment::class)
        ->and($feed[1]['data'])->toBeInstanceOf(ChatMessage::class)
        ->and($feed[2]['data'])->toBeInstanceOf(ChatLog::class);

    $resourceFeed = $channel->resource_feed;

    expect($resourceFeed->pluck('type')->all())->toBe(['attachment', 'message', 'log'])
        ->and($resourceFeed[0]['data'])->toBeInstanceOf(Fleetbase\Http\Resources\ChatAttachment::class)
        ->and($resourceFeed[1]['data'])->toBeInstanceOf(Fleetbase\Http\Resources\ChatMessage::class)
        ->and($resourceFeed[2]['data'])->toBeInstanceOf(Fleetbase\Http\Resources\ChatLog::class);
});

it('resolves chat log subjects content and relationship contracts', function () {
    $capsule = chat_models_database();
    $testing = $capsule->getConnection('testing');
    $mysql   = $capsule->getConnection('mysql');

    $mysql->table('users')->insert([
        ['uuid' => 'user-1', 'name' => 'Ada Lovelace', 'created_at' => now(), 'updated_at' => now()],
        ['uuid' => 'user-2', 'name' => 'Grace Hopper', 'created_at' => now(), 'updated_at' => now()],
    ]);
    $testing->table('chat_channels')->insert([
        'uuid'         => 'channel-1',
        'company_uuid' => 'company-1',
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    $testing->table('chat_participants')->insert([
        'uuid'              => 'participant-1',
        'company_uuid'      => 'company-1',
        'chat_channel_uuid' => 'channel-1',
        'user_uuid'         => 'user-1',
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);

    $log = ChatLog::create([
        'company_uuid'      => 'company-1',
        'chat_channel_uuid' => 'channel-1',
        'initiator_uuid'    => 'participant-1',
        'event_type'        => 'custom',
        'content'           => '{subject.0.name} mentioned {subject.1.name} and kept {subject.2.name}.',
        'subjects'          => ['user:user-1', 'missing-format', 'user:user-2', 'user:missing'],
        'status'            => 'complete',
    ]);

    expect($log->company()->getRelated())->toBeInstanceOf(Company::class)
        ->and($log->company()->getForeignKeyName())->toBe('company_uuid')
        ->and($log->chatChannel()->getRelated())->toBeInstanceOf(ChatChannel::class)
        ->and($log->chatChannel()->getForeignKeyName())->toBe('chat_channel_uuid')
        ->and($log->initiator()->getRelated())->toBeInstanceOf(ChatParticipant::class)
        ->and($log->initiator()->getForeignKeyName())->toBe('initiator_uuid')
        ->and(array_map(fn ($subject) => $subject->uuid, $log->resolveSubjects()))->toBe(['user-1', 'user-2'])
        ->and($log->getContent())->toBe('Ada Lovelace mentioned Grace Hopper and kept {subject.2.name}.')
        ->and($log->resolved_content)->toBe('Ada Lovelace mentioned Grace Hopper and kept {subject.2.name}.');
});

it('creates chat lifecycle logs with stable event types subjects and content templates', function () {
    $capsule = chat_models_database();
    $db      = $capsule->getConnection('testing');

    $db->table('chat_channels')->insert([
        'uuid'         => 'channel-1',
        'company_uuid' => 'company-1',
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    $db->table('chat_participants')->insert([
        ['uuid' => 'participant-1', 'company_uuid' => 'company-1', 'chat_channel_uuid' => 'channel-1', 'user_uuid' => 'user-1', 'created_at' => now(), 'updated_at' => now()],
        ['uuid' => 'participant-2', 'company_uuid' => 'company-1', 'chat_channel_uuid' => 'channel-1', 'user_uuid' => 'user-2', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $initiator = ChatParticipant::query()->whereKey('participant-1')->firstOrFail();
    $added     = ChatParticipant::query()->whereKey('participant-2')->firstOrFail();

    $createdChat    = ChatLog::participantAdded($initiator, $initiator);
    $addedOther     = ChatLog::participantAdded($initiator, $added);
    $deletedMessage = ChatLog::messageDeleted($initiator, 'message-1');
    $startedChat    = ChatLog::chatStarted($initiator);
    $endedChat      = ChatLog::chatEnded($initiator);

    expect($createdChat->event_type)->toBe('created_chat')
        ->and($createdChat->subjects)->toBe(['user:user-1'])
        ->and($createdChat->content)->toBe('{subject.0.name} has created a new chat.')
        ->and($addedOther->event_type)->toBe('added_participant')
        ->and($addedOther->subjects)->toBe(['user:user-1', 'user:user-2'])
        ->and($deletedMessage->event_type)->toBe('deleted_message')
        ->and($deletedMessage->subjects)->toBe(['user:user-1', 'message:message-1'])
        ->and($startedChat->event_type)->toBe('started_chat')
        ->and($startedChat->subjects)->toBe(['user:user-1'])
        ->and($endedChat->event_type)->toBe('ended_chat')
        ->and($endedChat->subjects)->toBe(['user:user-1'])
        ->and(ChatLog::query()->pluck('status')->unique()->all())->toBe(['complete']);
});

it('defines chat attachment ownership channel message and file relationships', function () {
    bind_test_container();

    $attachment = new ChatAttachment([
        'company_uuid'      => 'company-1',
        'chat_channel_uuid' => 'channel-1',
        'chat_message_uuid' => 'message-1',
        'sender_uuid'       => 'participant-1',
        'file_uuid'         => 'file-1',
    ]);

    expect($attachment->getTable())->toBe('chat_attachments')
        ->and($attachment->getFillable())->toBe([
            'company_uuid',
            'chat_channel_uuid',
            'chat_message_uuid',
            'sender_uuid',
            'file_uuid',
        ])
        ->and($attachment->sender()->getRelated())->toBeInstanceOf(ChatParticipant::class)
        ->and($attachment->sender()->getForeignKeyName())->toBe('sender_uuid')
        ->and($attachment->sender()->getOwnerKeyName())->toBe('uuid')
        ->and($attachment->chatChannel()->getRelated())->toBeInstanceOf(ChatChannel::class)
        ->and($attachment->chatChannel()->getForeignKeyName())->toBe('chat_channel_uuid')
        ->and($attachment->message()->getRelated())->toBeInstanceOf(ChatMessage::class)
        ->and($attachment->message()->getForeignKeyName())->toBe('chat_message_uuid')
        ->and($attachment->file()->getRelated())->toBeInstanceOf(File::class)
        ->and($attachment->file()->getForeignKeyName())->toBe('file_uuid');
});

it('defines chat channel ownership and feed relationship contracts', function () {
    bind_test_container();

    $channel = new ChatChannel([
        'company_uuid'    => 'company-1',
        'created_by_uuid' => 'user-1',
    ]);

    expect($channel->company()->getRelated())->toBeInstanceOf(Company::class)
        ->and($channel->company()->getForeignKeyName())->toBe('company_uuid')
        ->and($channel->company()->getOwnerKeyName())->toBe('uuid')
        ->and($channel->createdBy()->getRelated())->toBeInstanceOf(User::class)
        ->and($channel->createdBy()->getForeignKeyName())->toBe('created_by_uuid')
        ->and($channel->createdBy()->getOwnerKeyName())->toBe('uuid')
        ->and($channel->lastMessage()->getRelated())->toBeInstanceOf(ChatMessage::class)
        ->and($channel->lastMessage()->getForeignKeyName())->toBe('chat_channel_uuid')
        ->and($channel->participants()->getRelated())->toBeInstanceOf(ChatParticipant::class)
        ->and($channel->participants()->getForeignKeyName())->toBe('chat_channel_uuid')
        ->and($channel->messages()->getRelated())->toBeInstanceOf(ChatMessage::class)
        ->and($channel->messages()->getForeignKeyName())->toBe('chat_channel_uuid')
        ->and($channel->attachments()->getRelated())->toBeInstanceOf(ChatAttachment::class)
        ->and($channel->attachments()->getForeignKeyName())->toBe('chat_channel_uuid')
        ->and($channel->logs()->getRelated())->toBeInstanceOf(ChatLog::class)
        ->and($channel->logs()->getForeignKeyName())->toBe('chat_channel_uuid');
});
