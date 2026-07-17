<?php

use Fleetbase\Events\BroadcastNotificationCreated;
use Fleetbase\Exceptions\FleetbaseRequestException;
use Fleetbase\Exceptions\FleetbaseRequestValidationException;
use Fleetbase\Exceptions\PolicyDoesNotExist;
use Illuminate\Broadcasting\Channel;
use Illuminate\Notifications\Notification;
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
    public string $uuid = 'user-uuid';
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

test('request exceptions expose stable error arrays and messages', function () {
    $previous = new RuntimeException('previous');
    $requestException = new FleetbaseRequestException('single-error', 'Bad request', 422, $previous);
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
            'id' => 'notification-1',
            'type' => EventsAndExceptionsNotification::class,
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
