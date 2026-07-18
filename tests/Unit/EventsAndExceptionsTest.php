<?php

use Fleetbase\Events\BroadcastNotificationCreated;
use Fleetbase\Events\ResourceLifecycleEvent;
use Fleetbase\Events\ScheduleConstraintViolated;
use Fleetbase\Events\ScheduleCreated;
use Fleetbase\Events\ScheduleDeleted;
use Fleetbase\Events\ScheduleItemAssigned;
use Fleetbase\Events\ScheduleItemCreated;
use Fleetbase\Events\ScheduleItemDeleted;
use Fleetbase\Events\ScheduleItemUpdated;
use Fleetbase\Events\ScheduleUpdated;
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

test('unauthorized request exception falls back cleanly and includes resolved permission when available', function () {
    if (!Illuminate\Http\Request::hasMacro('getController')) {
        Illuminate\Http\Request::macro('getController', fn () => $this->attributes->get('_controller'));
    }

    $permissionRequest = Illuminate\Http\Request::create('/int/v1/api-keys', 'POST');
    $permissionRequest->attributes->set('_controller', new EventsAndExceptionsPermissionController());
    $permissionRequest->setRouteResolver(fn () => new class {
        public function getAction(string $key): string
        {
            return 'EventsAndExceptionsPermissionController@createRecord';
        }
    });

    $withPermission = new UnauthorizedRequestException($permissionRequest, 403, new RuntimeException('previous'));

    expect($withPermission->getMessage())->toBe('User is not authorized to create api-key')
        ->and($withPermission->getCode())->toBe(403)
        ->and($withPermission->getPrevious()->getMessage())->toBe('previous');
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
