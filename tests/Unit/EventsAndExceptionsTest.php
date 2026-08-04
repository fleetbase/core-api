<?php

use Fleetbase\Events\BroadcastNotificationCreated;
use Fleetbase\Events\ChatParticipantAdded;
use Fleetbase\Events\ChatParticipantRemoved;
use Fleetbase\Events\ResourceLifecycleEvent;
use Fleetbase\Events\ScheduleConstraintViolated;
use Fleetbase\Events\ScheduleCreated;
use Fleetbase\Events\ScheduleDeleted;
use Fleetbase\Events\ScheduleItemAssigned;
use Fleetbase\Events\ScheduleItemCreated;
use Fleetbase\Events\ScheduleItemDeleted;
use Fleetbase\Events\ScheduleItemUpdated;
use Fleetbase\Events\ScheduleUpdated;
use Fleetbase\Events\UserCreatedNewCompany;
use Fleetbase\Events\UserRemovedFromCompany;
use Fleetbase\Exceptions\FleetbaseRequestException;
use Fleetbase\Exceptions\FleetbaseRequestValidationException;
use Fleetbase\Exceptions\PolicyDoesNotExist;
use Fleetbase\Exceptions\UnauthorizedRequestException;
use Fleetbase\Listeners\SendResourceLifecycleWebhook;
use Fleetbase\Models\ChatChannel;
use Fleetbase\Models\ChatParticipant;
use Fleetbase\Models\Company;
use Fleetbase\Models\Model as FleetbaseModel;
use Fleetbase\Models\Schedule;
use Fleetbase\Models\ScheduleItem;
use Fleetbase\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\MessageBag;

class EventsAndExceptionsNotification extends Notification
{
    public $id = 'notification-1';

    public function broadcastOn(): array
    {
        return [new Channel('notifications.custom')];
    }
}

class EventsAndExceptionsCustomNotification extends EventsAndExceptionsNotification
{
    public function broadcastWith(): array
    {
        return ['custom' => true];
    }

    public function broadcastType(): string
    {
        return 'custom.notification';
    }
}

class EventsAndExceptionsNotifiable
{
    public string $uuid      = 'user-uuid';
    public string $public_id = 'user_1234567';

    public function getKey(): string
    {
        return 'primary-key';
    }

    public function receivesBroadcastNotificationsOn(Notification $notification): string
    {
        return 'notifiable.direct';
    }
}

class EventsAndExceptionsArrayChannelNotifiable
{
    public string $uuid      = 'array-user-uuid';
    public string $public_id = 'array_user_1234567';

    public function getKey(): string
    {
        return 'array-primary-key';
    }

    public function receivesBroadcastNotificationsOn(Notification $notification)
    {
        return [new Channel('notifiable.array-one'), new Channel('notifiable.array-two')];
    }
}

class EventsAndExceptionsLogger
{
    public array $errors = [];

    public function error(string $message, array $context = []): void
    {
        $this->errors[] = compact('message', 'context');
    }
}

class EventsAndExceptionsRoute
{
    public function __construct(private string $uri)
    {
    }

    public function uri(): string
    {
        return $this->uri;
    }
}

class EventsAndExceptionsChatParticipant extends ChatParticipant
{
    public function load($relations)
    {
        return $this;
    }

    public function getIsOnlineAttribute(): bool
    {
        return true;
    }

    public function getLastSeenAtAttribute(): Carbon
    {
        return Carbon::parse('2026-07-18 14:10:00', 'UTC');
    }

    public function getUpdatedAtAttribute(): string
    {
        return '2026-07-18 14:01:00';
    }

    public function getCreatedAtAttribute(): string
    {
        return '2026-07-18 14:00:00';
    }

    public function getDeletedAtAttribute(): null
    {
        return null;
    }
}

class EventsAndExceptionsUser extends User
{
    public function getAvatarUrlAttribute(): string
    {
        return 'https://fleetbase.test/avatar.png';
    }

    public function isOnline(): bool
    {
        return true;
    }

    public function lastSeenAt(): Carbon
    {
        return Carbon::parse('2026-07-18 14:10:00', 'UTC');
    }
}

class EventsAndExceptionsPermissionController
{
    public function getResourceSingularName(): string
    {
        return 'api_key';
    }
}

class EventsAndExceptionsLifecycleEvent extends ResourceLifecycleEvent
{
    public ?EloquentModel $record  = null;
    public ?JsonResource $resource = null;

    public static function fake(array $properties, ?EloquentModel $record = null, ?JsonResource $resource = null): self
    {
        $reflection = new ReflectionClass(self::class);
        /** @var self $event */
        $event = $reflection->newInstanceWithoutConstructor();

        foreach ($properties as $property => $value) {
            $event->{$property} = $value;
        }

        $event->record   = $record;
        $event->resource = $resource;

        return $event;
    }

    public function getModelRecord(): ?EloquentModel
    {
        return $this->record;
    }

    public function getModelResource($model, ?string $namespace = null, ?int $version = null): JsonResource
    {
        return $this->resource ?? new JsonResource($model);
    }
}

test('request exceptions expose stable error arrays and messages', function () {
    $previous            = new RuntimeException('previous');
    $requestException    = new FleetbaseRequestException('single-error', 'Bad request', 422, $previous);
    $validationException = new FleetbaseRequestValidationException(
        new MessageBag(['email' => ['The email field is required.'], 'password' => ['The password field is required.']]),
        'Invalid payload'
    );

    expect($requestException->getMessage())->toBe('Bad request')
        ->and($requestException->getCode())->toBe(422)
        ->and($requestException->getPrevious())->toBe($previous)
        ->and($requestException->getErrors())->toBe(['single-error'])
        ->and($validationException->getMessage())->toBe('Invalid payload')
        ->and($validationException->getErrors())->toBe([
            'The email field is required.',
            'The password field is required.',
        ])
        ->and((new FleetbaseRequestValidationException(['name' => 'required']))->getErrors())->toBe(['name' => 'required']);
});

test('policy exceptions include the missing policy identity', function () {
    expect(PolicyDoesNotExist::named('manage users')->getMessage())->toBe('There is no policy named `manage users`.')
        ->and(PolicyDoesNotExist::withId(42)->getMessage())->toBe('There is no policy with id `42`.');
});

test('company membership events carry user and company payloads without stale relations', function () {
    bind_test_container();

    $user = new User();
    $user->setRawAttributes(['uuid' => 'user-1', 'email' => 'owner@example.test']);
    $user->setRelation('companies', collect(['stale']));

    $company = new Company();
    $company->setRawAttributes(['uuid' => 'company-1', 'name' => 'Acme']);
    $company->setRelation('owner', $user);

    $created = new UserCreatedNewCompany($user, $company);
    $removed = new UserRemovedFromCompany($user, $company);

    expect($created->user)->toBe($user)
        ->and($created->company)->toBe($company)
        ->and($removed->user->uuid)->toBe('user-1')
        ->and($removed->company->uuid)->toBe('company-1')
        ->and($removed->user->getRelations())->toBe([])
        ->and($removed->company->getRelations())->toBe([]);
});

test('unauthorized request exception falls back cleanly and includes resolved permission when available', function () {
    if (!Illuminate\Http\Request::hasMacro('getController')) {
        Illuminate\Http\Request::macro('getController', fn () => $this->attributes->get('_controller') ?? $this->route()?->controller);
    }

    $permissionRequest = Illuminate\Http\Request::create('/int/v1/api-keys', 'POST');
    $permissionRequest->attributes->set('_controller', new EventsAndExceptionsPermissionController());
    $permissionRequest->setRouteResolver(fn () => new class {
        public function getAction(string $key): string
        {
            return 'EventsAndExceptionsPermissionController@createRecord';
        }
    });

    $withPermission       = new UnauthorizedRequestException($permissionRequest, 403, new RuntimeException('previous'));
    $unknownRouteRequest  = Illuminate\Http\Request::create('/int/v1/unknown', 'GET');
    $withoutPermission    = new UnauthorizedRequestException($unknownRouteRequest);
    $missingActionRequest = Illuminate\Http\Request::create('/int/v1/api-keys', 'POST');
    $missingActionRequest->attributes->set('_controller', new EventsAndExceptionsPermissionController());
    $missingActionRequest->setRouteResolver(fn () => new class {
        public function getAction(string $key): ?string
        {
            return null;
        }
    });
    $withoutResolvedPermission     = new UnauthorizedRequestException($missingActionRequest);
    $unresolvableControllerRequest = Illuminate\Http\Request::create('/int/v1/api-keys', 'POST');
    $unresolvableControllerRequest->attributes->set('_controller', new stdClass());
    $withResolutionFailure = new UnauthorizedRequestException($unresolvableControllerRequest);

    expect($withPermission->getMessage())->toBe('User is not authorized to create api-key')
        ->and($withPermission->getCode())->toBe(403)
        ->and($withPermission->getPrevious()->getMessage())->toBe('previous')
        ->and($withoutPermission->getMessage())->toBe('Unauthorized Request')
        ->and($withoutResolvedPermission->getMessage())->toBe('Unauthorized Request')
        ->and($withResolutionFailure->getMessage())->toBe('Unauthorized Request');
});

test('broadcast notification event merges notification and notifiable channels', function () {
    $event = new BroadcastNotificationCreated(
        new EventsAndExceptionsNotifiable(),
        new EventsAndExceptionsNotification(),
        ['message' => 'Hello']
    );

    $channels = array_map(fn ($channel) => (string) $channel, $event->broadcastOn());

    expect($channels)->toContain('notifications.custom')
        ->and($channels)->toContain('notifiable.direct')
        ->and($channels)->toContain('events_and_exceptions_notifiable.user-uuid')
        ->and($channels)->toContain('events_and_exceptions_notifiable.user_1234567')
        ->and($event->broadcastType())->toBe(EventsAndExceptionsNotification::class)
        ->and($event->broadcastWith())->toBe([
            'message' => 'Hello',
            'id'      => 'notification-1',
            'type'    => EventsAndExceptionsNotification::class,
        ]);
});

test('broadcast notification event preserves array channel responses from notifiables', function () {
    $event = new BroadcastNotificationCreated(
        new EventsAndExceptionsArrayChannelNotifiable(),
        new EventsAndExceptionsNotification(),
        ['message' => 'Hello']
    );

    $channels = $event->broadcastOn();

    expect($channels[1])->toBeArray()
        ->and(array_map(fn ($channel) => (string) $channel, $channels[1]))->toBe([
            'notifiable.array-one',
            'notifiable.array-two',
        ]);
});

test('broadcast notification event honors custom broadcast payload and type', function () {
    $event = new BroadcastNotificationCreated(
        new EventsAndExceptionsNotifiable(),
        new EventsAndExceptionsCustomNotification(),
        ['message' => 'ignored']
    );

    expect($event->broadcastType())->toBe('custom.notification')
        ->and($event->broadcastWith())->toBe(['custom' => true]);
});

test('schedule events expose the schedule or schedule item they were created with', function () {
    bind_test_container();

    $schedule = new Schedule();
    $schedule->setRawAttributes(['uuid' => 'schedule-1', 'company_uuid' => 'company-1'], true);

    $item = new ScheduleItem();
    $item->setRawAttributes(['uuid' => 'item-1', 'schedule_uuid' => 'schedule-1'], true);

    expect((new ScheduleCreated($schedule))->schedule)->toBe($schedule)
        ->and((new ScheduleUpdated($schedule))->schedule)->toBe($schedule)
        ->and((new ScheduleDeleted($schedule))->schedule)->toBe($schedule)
        ->and((new ScheduleItemCreated($item))->scheduleItem)->toBe($item)
        ->and((new ScheduleItemUpdated($item))->scheduleItem)->toBe($item)
        ->and((new ScheduleItemDeleted($item))->scheduleItem)->toBe($item)
        ->and((new ScheduleItemAssigned($item))->scheduleItem)->toBe($item);

    $violations = [
        ['constraint_key' => 'max_hours', 'message' => 'Daily limit exceeded.'],
    ];
    $constraintEvent = new ScheduleConstraintViolated($item, $violations);

    expect($constraintEvent->scheduleItem)->toBe($item)
        ->and($constraintEvent->violations)->toBe($violations);
});

test('chat participant events broadcast participant payloads to chat and user channels', function (string $eventClass, string $broadcastName) {
    bind_test_container();
    Carbon::setTestNow(Carbon::parse('2026-07-18 14:15:16', 'UTC'));

    $request = Illuminate\Http\Request::create('/int/v1/chat-participants');
    $request->setRouteResolver(fn () => new EventsAndExceptionsRoute('int/v1/chat-participants'));
    app()->instance('request', $request);

    $channel = new ChatChannel();
    $channel->setRawAttributes([
        'uuid'      => 'channel-uuid',
        'public_id' => 'chat_channel_public',
    ], true);

    $user = new EventsAndExceptionsUser();
    $user->setRawAttributes([
        'uuid'      => 'user-uuid',
        'public_id' => 'user_public',
        'name'      => 'Ada Lovelace',
        'username'  => 'ada',
        'email'     => 'ada@example.test',
        'phone'     => '+15555550123',
    ], true);

    $participant = new EventsAndExceptionsChatParticipant();
    $participant->setRawAttributes([
        'id'                => 42,
        'uuid'              => 'participant-uuid',
        'public_id'         => 'participant_public',
        'chat_channel_uuid' => 'channel-uuid',
        'user_uuid'         => 'user-uuid',
        'created_at'        => '2026-07-18 14:00:00',
        'updated_at'        => '2026-07-18 14:01:00',
    ], true);
    $participant->setRelation('chatChannel', $channel);
    $participant->setRelation('user', $user);

    $event    = new $eventClass($participant);
    $channels = array_map(fn ($channel) => (string) $channel, $event->broadcastOn());
    $payload  = $event->broadcastWith();

    expect($event->eventId)->toStartWith('event_')
        ->and($event->createdAt->toDateTimeString())->toBe('2026-07-18 14:15:16')
        ->and($event->broadcastAs())->toBe($broadcastName)
        ->and($channels)->toBe([
            'chat.channel-uuid',
            'chat.chat_channel_public',
            'user.user-uuid',
            'user.user_public',
        ])
        ->and($payload['id'])->toBe($event->eventId)
        ->and($payload['event'])->toBe($broadcastName)
        ->and($payload['created_at'])->toBe('2026-07-18 14:15:16')
        ->and($payload['channel_id'])->toBe('chat_channel_public')
        ->and($payload['data']['id'])->toBe(42)
        ->and($payload['data']['uuid'])->toBe('participant-uuid')
        ->and($payload['data']['chat_channel_uuid'])->toBe('channel-uuid')
        ->and($payload['data']['user_uuid'])->toBe('user-uuid')
        ->and($payload['data']['name'])->toBe('Ada Lovelace')
        ->and($payload['data']['username'])->toBe('ada')
        ->and($payload['data']['email'])->toBe('ada@example.test')
        ->and($payload['data']['phone'])->toBe('+15555550123');

    Carbon::setTestNow();
})->with([
    'participant added'   => [ChatParticipantAdded::class, 'chat.added_participant'],
    'participant removed' => [ChatParticipantRemoved::class, 'chat.removed_participant'],
]);

test('resource lifecycle events normalize payload children without dropping chat message relations', function () {
    bind_test_container(['api.version' => 'v1']);
    Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00'));

    $company = new Company();
    $company->setRawAttributes([
        'uuid'      => 'company-uuid',
        'public_id' => 'company_1234567',
        'name'      => 'Acme',
    ], true);

    $child = new FleetbaseModel();
    $child->setRawAttributes([
        'uuid'      => 'child-uuid',
        'public_id' => 'child_1234567',
    ], true);

    $normalized = ResourceLifecycleEvent::transformResourceChildrenToId([
        'company'    => new JsonResource($company),
        'missing'    => new JsonResource(null),
        'created_at' => Carbon::parse('2026-07-17 11:59:00'),
        'plain'      => 'value',
    ]);

    $record = new FleetbaseModel();
    $record->setRawAttributes([
        'uuid'         => 'record-uuid',
        'company_uuid' => 'company-uuid',
    ], true);

    $resource = new class($record, $child) extends JsonResource {
        public function __construct($resource, private FleetbaseModel $child)
        {
            parent::__construct($resource);
        }

        public function toArray($request): array
        {
            return [
                'child'      => new JsonResource($this->child),
                'created_at' => Carbon::parse('2026-07-17 12:01:00'),
            ];
        }
    };

    $event = EventsAndExceptionsLifecycleEvent::fake([
        'modelName'           => 'order',
        'modelClassNamespace' => FleetbaseModel::class,
        'modelClassName'      => 'Order',
        'modelHumanName'      => 'order',
        'modelRecordName'     => null,
        'modelUuid'           => 'record-uuid',
        'namespace'           => '\\Fleetbase',
        'version'             => 1,
        'eventName'           => 'created',
        'sentAt'              => '2026-07-17 12:00:00',
        'eventId'             => 'event_123',
        'apiVersion'          => 'v1',
        'requestMethod'       => 'POST',
        'apiCredential'       => 'console',
        'apiSecret'           => 'internal',
        'apiKey'              => null,
        'apiEnvironment'      => 'live',
        'isSandbox'           => false,
        'data'                => [],
        'userSession'         => null,
        'companySession'      => 'company-uuid',
    ], $record, $resource);

    expect($normalized)->toBe([
        'company'    => 'company_1234567',
        'missing'    => null,
        'created_at' => '2026-07-17 11:59:00',
        'plain'      => 'value',
    ])
        ->and($event->broadcastAs())->toBe('order.created')
        ->and($event->broadcastWith())->toBe([
            'id'          => 'event_123',
            'api_version' => 'v1',
            'event'       => 'order.created',
            'created_at'  => '2026-07-17 12:00:00',
            'data'        => [
                'child'      => 'child_1234567',
                'created_at' => '2026-07-17 12:01:00',
            ],
        ]);
});

test('resource lifecycle events build company model api relationship and chat channels', function () {
    bind_test_container();
    session()->flush();
    session([
        'company'        => 'session-company',
        'api_credential' => 'api-credential-1',
        'user'           => 'session-user',
    ]);

    $company = new Company();
    $company->setRawAttributes(['public_id' => 'company_1234567'], true);

    $channel = new ChatChannel();
    $channel->setRawAttributes([
        'uuid'            => '11111111-1111-4111-8111-111111111111',
        'public_id'       => 'chat_1234567',
        'company_uuid'    => 'model-company',
        'created_by_uuid' => 'creator-user',
    ], true);
    $channel->setRelation('company', $company);

    $participant = new ChatParticipant();
    $participant->setRawAttributes([
        'uuid'              => '22222222-2222-4222-8222-222222222222',
        'public_id'         => 'chatparticipant_1234567',
        'company_uuid'      => 'model-company',
        'chat_channel_uuid' => '11111111-1111-4111-8111-111111111111',
        'user_uuid'         => 'participant-user',
    ], true);
    $participant->setRelation('chatChannel', $channel);

    $event = EventsAndExceptionsLifecycleEvent::fake([
        'modelName'           => 'chat_participant',
        'modelClassNamespace' => ChatParticipant::class,
        'modelClassName'      => 'ChatParticipant',
        'modelHumanName'      => 'chat participant',
        'modelRecordName'     => null,
        'modelUuid'           => '22222222-2222-4222-8222-222222222222',
        'namespace'           => '\\Fleetbase',
        'version'             => 1,
        'eventName'           => 'updated',
        'sentAt'              => '2026-07-17 12:30:00',
        'eventId'             => 'event_456',
        'apiVersion'          => 'v1',
        'requestMethod'       => 'PATCH',
        'apiCredential'       => 'api-credential-1',
        'apiSecret'           => 'secret',
        'apiKey'              => 'key',
        'apiEnvironment'      => 'live',
        'isSandbox'           => false,
        'data'                => [],
        'userSession'         => 'session-user',
        'companySession'      => 'session-company',
    ], $participant);

    $channels = array_map(fn ($channel) => (string) $channel, $event->broadcastOn());

    expect($channels)->toContain('company.session-company')
        ->and($channels)->toContain('chat_participant.chatparticipant_1234567')
        ->and($channels)->toContain('chat_participant.22222222-2222-4222-8222-222222222222')
        ->and($channels)->toContain('api.api-credential-1')
        ->and($channels)->toContain('user.session-user')
        ->and($channels)->toContain('chat.chat_1234567')
        ->and($channels)->toContain('chat.11111111-1111-4111-8111-111111111111');
});

test('resource lifecycle events log and return empty contracts when the model cannot be resolved', function () {
    if (!function_exists('Fleetbase\\Events\\logger')) {
        eval('namespace Fleetbase\\Events; function logger() { return \\app("log"); }');
    }

    bind_test_container(['api.version' => 'v1']);

    $logger = new EventsAndExceptionsLogger();
    app()->instance('log', $logger);

    $event = EventsAndExceptionsLifecycleEvent::fake([
        'modelName'           => 'order',
        'modelClassNamespace' => FleetbaseModel::class,
        'modelClassName'      => 'Order',
        'modelHumanName'      => 'order',
        'modelRecordName'     => 'Order 1001',
        'modelUuid'           => 'missing-record',
        'namespace'           => '\\Fleetbase',
        'version'             => 1,
        'eventName'           => 'updated',
        'sentAt'              => '2026-07-17 12:45:00',
        'eventId'             => 'event_missing',
        'apiVersion'          => 'v1',
        'requestMethod'       => 'PATCH',
        'apiCredential'       => 'credential-uuid',
        'apiSecret'           => 'secret',
        'apiKey'              => 'key',
        'apiEnvironment'      => 'sandbox',
        'isSandbox'           => true,
        'data'                => ['before' => 'state'],
        'userSession'         => 'user-uuid',
        'companySession'      => 'company-uuid',
    ]);

    expect($event->broadcastOn())->toBe([])
        ->and($event->broadcastWith())->toBe([])
        ->and($logger->errors)->toHaveCount(2)
        ->and($logger->errors[0]['message'])->toBe('Unable to resolve a model to broadcast for')
        ->and($logger->errors[0]['context']['modelUuid'])->toBe('missing-record')
        ->and($logger->errors[0]['context']['apiEnvironment'])->toBe('sandbox')
        ->and($logger->errors[0]['context']['data'])->toBe(['before' => 'state'])
        ->and($logger->errors[1]['message'])->toBe('Unable to resolve a model to get event data for')
        ->and($logger->errors[1]['context']['modelUuid'])->toBe('missing-record')
        ->and($logger->errors[1]['context']['eventName'])->toBe('updated')
        ->and($logger->errors[1]['context']['isSandbox'])->toBeTrue();
});

test('resource lifecycle events broadcast relationship storefront and direct chat channel routes', function () {
    bind_test_container();
    session()->flush();
    session(['company' => 'session-company', 'user' => 'session-user']);

    $company = new Company();
    $company->setRawAttributes(['public_id' => 'company_7654321'], true);

    $customer = new User();
    $customer->setRawAttributes(['public_id' => 'user_1234567'], true);

    $driver = new User();
    $driver->setRawAttributes(['public_id' => 'user_7654321'], true);

    $channel = new ChatChannel();
    $channel->setRawAttributes([
        'uuid'            => '33333333-3333-4333-8333-333333333333',
        'public_id'       => 'chat_7654321',
        'company_uuid'    => 'model-company',
        'created_by_uuid' => 'creator-user',
        'meta'            => ['storefront_id' => 'storefront_1234567'],
    ], true);
    $channel->setRelation('company', $company);
    $channel->setRelation('customer', $customer);
    $channel->setRelation('driverAssigned', $driver);
    $channel->setAttribute('customer_uuid', 'customer-uuid');
    $channel->setAttribute('driver_assigned_uuid', 'driver-assigned-uuid');
    $channel->setAttribute('driverAssigned_uuid', 'driver-assigned-uuid');

    $event = EventsAndExceptionsLifecycleEvent::fake([
        'modelName'           => 'chat_channel',
        'modelClassNamespace' => ChatChannel::class,
        'modelClassName'      => 'ChatChannel',
        'modelHumanName'      => 'chat channel',
        'modelRecordName'     => null,
        'modelUuid'           => '33333333-3333-4333-8333-333333333333',
        'namespace'           => '\\Fleetbase',
        'version'             => 1,
        'eventName'           => 'created',
        'sentAt'              => '2026-07-17 13:30:00',
        'eventId'             => 'event_chat_channel',
        'apiVersion'          => 'v1',
        'requestMethod'       => 'POST',
        'apiCredential'       => 'console',
        'apiSecret'           => 'internal',
        'apiKey'              => null,
        'apiEnvironment'      => 'live',
        'isSandbox'           => false,
        'data'                => [],
        'userSession'         => 'session-user',
        'companySession'      => 'session-company',
    ], $channel);

    $channels = array_map(fn ($channel) => (string) $channel, $event->broadcastOn());

    expect($channels)->toContain('company.session-company')
        ->and($channels)->toContain('company.company_7654321')
        ->and($channels)->toContain('chat_channel.chat_7654321')
        ->and($channels)->toContain('chat_channel.33333333-3333-4333-8333-333333333333')
        ->and($channels)->toContain('driverAssigned.driver-assigned-uuid')
        ->and($channels)->toContain('driverAssigned.user_7654321')
        ->and($channels)->toContain('customer.customer-uuid')
        ->and($channels)->toContain('customer.user_1234567')
        ->and($channels)->toContain('storefront.storefront_1234567')
        ->and($channels)->toContain('user.session-user')
        ->and($channels)->toContain('chat.chat_7654321')
        ->and($channels)->toContain('chat.33333333-3333-4333-8333-333333333333');
});

test('resource lifecycle events prefer webhook payloads when resources provide them', function () {
    bind_test_container(['api.version' => 'v1']);

    $record = new FleetbaseModel();
    $record->setRawAttributes([
        'uuid'         => 'record-uuid',
        'company_uuid' => 'company-uuid',
    ], true);

    $resource = new class($record) extends JsonResource {
        public function toWebhookPayload(): array
        {
            return [
                'id'     => 'custom-payload-id',
                'status' => 'ready',
            ];
        }

        public function toArray($request): array
        {
            return ['id' => 'array-payload-id'];
        }
    };

    $event = EventsAndExceptionsLifecycleEvent::fake([
        'modelName'           => 'order',
        'modelClassNamespace' => FleetbaseModel::class,
        'modelClassName'      => 'Order',
        'modelHumanName'      => 'order',
        'modelRecordName'     => null,
        'modelUuid'           => 'record-uuid',
        'namespace'           => '\\Fleetbase',
        'version'             => 1,
        'eventName'           => 'ready',
        'sentAt'              => '2026-07-17 14:00:00',
        'eventId'             => 'event_payload',
        'apiVersion'          => 'v1',
        'requestMethod'       => 'PATCH',
        'apiCredential'       => 'console',
        'apiSecret'           => 'internal',
        'apiKey'              => null,
        'apiEnvironment'      => 'live',
        'isSandbox'           => false,
        'data'                => [],
        'userSession'         => null,
        'companySession'      => 'company-uuid',
    ], $record, $resource);

    expect($event->broadcastWith())->toBe([
        'id'          => 'event_payload',
        'api_version' => 'v1',
        'event'       => 'order.ready',
        'created_at'  => '2026-07-17 14:00:00',
        'data'        => [
            'id'     => 'custom-payload-id',
            'status' => 'ready',
        ],
    ]);
});

test('resource lifecycle webhook listener restores event session defaults and describes api changes', function () {
    bind_test_container();
    session()->flush();

    $listener = new SendResourceLifecycleWebhook();
    $event    = EventsAndExceptionsLifecycleEvent::fake([
        'modelName'           => 'order',
        'modelClassNamespace' => FleetbaseModel::class,
        'modelClassName'      => 'Order',
        'modelHumanName'      => 'order',
        'modelRecordName'     => 'Order 1001',
        'modelUuid'           => 'record-uuid',
        'namespace'           => '\\Fleetbase',
        'version'             => 1,
        'eventName'           => 'driver_assigned',
        'sentAt'              => '2026-07-17 13:00:00',
        'eventId'             => 'event_789',
        'apiVersion'          => 'v1',
        'requestMethod'       => 'PATCH',
        'apiCredential'       => 'credential-uuid',
        'apiSecret'           => 'secret',
        'apiKey'              => 'key',
        'apiEnvironment'      => 'sandbox',
        'isSandbox'           => true,
        'data'                => [],
        'userSession'         => 'user-uuid',
        'companySession'      => 'company-uuid',
    ]);

    $listener->setSessionFromEvent($event);

    expect(session('api_credential'))->toBe('credential-uuid')
        ->and(session('api_key'))->toBe('key')
        ->and(session('api_secret'))->toBe('secret')
        ->and(session('api_environment'))->toBe('sandbox')
        ->and(session('is_sandbox'))->toBeTrue()
        ->and(session('company'))->toBe('company-uuid')
        ->and(session('user'))->toBe('user-uuid')
        ->and($listener->getHumanReadableEventDescription($event))->toBe('A order (Order 1001) was assigned a driver via API');
});
