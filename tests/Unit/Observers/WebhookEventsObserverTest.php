<?php

use Fleetbase\Events\ResourceLifecycleEvent;
use Fleetbase\Expansions\Str as StrExpansion;
use Fleetbase\Models\Model as FleetbaseModel;
use Fleetbase\Observers\WebhookEventsObserver;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str as SupportStr;

if (!function_exists('event')) {
    function event(mixed $event = null): mixed
    {
        $GLOBALS['webhook_events_observer_events'][] = $event;

        return $event;
    }
}

class WebhookEventsObserverModel extends FleetbaseModel
{
    protected $resource = WebhookEventsObserverResource::class;
    protected $table    = 'webhook_events_observer_models';

    public static ?self $record = null;

    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        return new class {
            public function withoutGlobalScopes(): self
            {
                return $this;
            }

            public function first(): ?WebhookEventsObserverModel
            {
                return WebhookEventsObserverModel::$record;
            }
        };
    }
}

class WebhookEventsObserverResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'   => $this->public_id,
            'name' => $this->name,
        ];
    }
}

function webhook_events_observer_model(array $attributes = [], array $original = []): WebhookEventsObserverModel
{
    $model = new WebhookEventsObserverModel();
    $model->setRawAttributes(array_merge([
        'uuid'         => 'model-1',
        'public_id'    => 'model_1',
        'name'         => 'Dispatch Test Model',
        'company_uuid' => 'company-1',
    ], $attributes), true);

    if ($original !== []) {
        $model->syncOriginal();
        $model->setRawAttributes(array_merge($model->getAttributes(), $original), true);
    }

    WebhookEventsObserverModel::$record = $model;

    return $model;
}

function webhook_events_observer_dispatched_events(): array
{
    if (!empty($GLOBALS['webhook_events_observer_events'])) {
        return $GLOBALS['webhook_events_observer_events'];
    }

    return $GLOBALS['trigger_public_notification_broadcast_events'] ?? [];
}

beforeEach(function () {
    $GLOBALS['webhook_events_observer_events']                  = [];
    $GLOBALS['trigger_public_notification_broadcast_events']    = [];
    bind_test_container(['api.version' => 'v1']);
    Carbon::setTestNow(Carbon::parse('2026-07-18 10:00:00'));

    if (!SupportStr::hasMacro('humanize')) {
        $strExpansion = new StrExpansion();
        SupportStr::macro('humanize', $strExpansion->humanize());
    }
});

afterEach(function () {
    $GLOBALS['webhook_events_observer_events']                  = [];
    $GLOBALS['trigger_public_notification_broadcast_events']    = [];
    WebhookEventsObserverModel::$record                         = null;
    Carbon::setTestNow();
    Facade::clearResolvedInstances();
});

it('dispatches resource lifecycle events when models are created or deleted', function (string $method, string $eventName) {
    $model = webhook_events_observer_model();

    (new WebhookEventsObserver())->{$method}($model);
    $events = webhook_events_observer_dispatched_events();

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(ResourceLifecycleEvent::class)
        ->and($events[0]->modelUuid)->toBe('model-1')
        ->and($events[0]->modelClassName)->toBe('WebhookEventsObserverModel')
        ->and($events[0]->modelRecordName)->toBe('Dispatch Test Model')
        ->and($events[0]->eventName)->toBe($eventName)
        ->and($events[0]->apiVersion)->toBe('v1')
        ->and($events[0]->sentAt)->toBe('2026-07-18 10:00:00');
})->with([
    ['created', 'created'],
    ['deleted', 'deleted'],
]);

it('only dispatches update lifecycle events when the model has changed', function () {
    $observer = new WebhookEventsObserver();

    $observer->updated(webhook_events_observer_model());

    expect(webhook_events_observer_dispatched_events())->toBe([]);

    $changed = webhook_events_observer_model(['name' => 'Renamed Model']);
    $changed->syncOriginalAttribute('name');
    $changed->name = 'Renamed Model Again';
    $changed->syncChanges();

    $observer->updated($changed);
    $events = webhook_events_observer_dispatched_events();

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(ResourceLifecycleEvent::class)
        ->and($events[0]->eventName)->toBe('updated')
        ->and($events[0]->modelRecordName)->toBe('Renamed Model Again');
});
