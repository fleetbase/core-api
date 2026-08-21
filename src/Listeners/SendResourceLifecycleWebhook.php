<?php

namespace Fleetbase\Listeners;

use Fleetbase\Events\ResourceLifecycleEvent;
use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\ApiEvent;
use Fleetbase\Models\User;
use Fleetbase\Models\WebhookEndpoint;
use Fleetbase\Models\WebhookRequestLog;
use Fleetbase\Support\Utils;
use Fleetbase\Webhook\WebhookCall;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class SendResourceLifecycleWebhook implements ShouldQueue
{
    /**
     * Session keys which carry the request context a lifecycle event was created in.
     *
     * @var string[]
     */
    protected static array $contextSessionKeys = [
        'api_credential',
        'api_key',
        'api_secret',
        'api_environment',
        'is_sandbox',
        'company',
        'user',
    ];

    /**
     * Handle the event.
     *
     * @param ResourceLifecycleEvent $event
     *
     * @return void
     */
    public function handle($event)
    {
        // The context serialized on the event is the only trustworthy source for this job. A long
        // running queue worker keeps its session between jobs, so the context left behind by a
        // previously handled event must never be preferred over, or leak into, this one.
        $context        = static::resolveEventContext($event);
        $restoreSession = $this->applySessionContext($context);

        try {
            $this->sendWebhooksForEvent($event, $context);
        } finally {
            $restoreSession();
        }
    }

    /**
     * Send the webhooks for a single lifecycle event using the context serialized on it.
     *
     * @param ResourceLifecycleEvent $event
     * @param array<string, mixed>   $context
     *
     * @return void
     */
    protected function sendWebhooksForEvent($event, array $context)
    {
        $companyId       = $context['company'];
        $apiCredentialId = $context['api_credential'];
        $apiKey          = $context['api_key'];
        $apiSecret       = $context['api_secret'];
        $apiEnvironment  = $context['api_environment'];
        $isSandbox       = $context['is_sandbox'];

        // Compute the event payload exactly once so the persisted ApiEvent record and the
        // outbound webhook body are guaranteed to be identical. $event->getEventData() resolves
        // the model live at handle time, whereas $event->data is a snapshot frozen at dispatch
        // time; using each in a different place lets the DB record and the webhook diverge.
        $payload = $event->getEventData();

        // Prepare event
        $eventData = [
            'company_uuid'        => $companyId,
            'event'               => $event->broadcastAs(),
            'source'              => $apiCredentialId ? 'api' : 'console',
            'data'                => $payload,
            'method'              => $event->requestMethod,
            'description'         => $this->getHumanReadableEventDescription($event),
        ];

        // Validate api credential, if not uuid then it could be internal
        if ($apiCredentialId && Str::isUuid($apiCredentialId) && ApiCredential::where('uuid', $apiCredentialId)->exists()) {
            $eventData['api_credential_uuid'] = $apiCredentialId;
        }

        // Check if it was a personal access token which made the request
        if ($apiCredentialId && is_numeric($apiCredentialId) && PersonalAccessToken::where('id', $apiCredentialId)->exists()) {
            $eventData['access_token_id'] = (int) $apiCredentialId;
        }

        try {
            // log the api event
            $apiEvent = ApiEvent::create($eventData);
        } catch (\Exception|QueryException $e) {
            logger()->error($e->getMessage());

            return;
        }

        // get all webhooks for current company
        $webhooks = WebhookEndpoint::where([
            'company_uuid' => $companyId,
            'status'       => 'enabled',
            'mode'         => $apiEnvironment,
        ])->get();

        // Send Webhook for event
        foreach ($webhooks as $webhook) {
            // Only Send Webhook if webhook requires this event
            if ($webhook->cannotFireEvent($apiEvent->event)) {
                continue;
            }

            $durationStart = now();
            $connection    = $isSandbox ? 'sandbox' : 'mysql';

            try {
                // Send Webhook for the event
                WebhookCall::create()
                    ->meta([
                        'is_sandbox'          => $isSandbox,
                        'api_key'             => $apiKey,
                        'api_credential_uuid' => data_get($apiEvent, 'api_credential_uuid'),
                        'access_token_id'     => data_get($apiEvent, 'access_token_id'),
                        'company_uuid'        => $webhook->company_uuid,
                        'api_event_uuid'      => $apiEvent->uuid,
                        'webhook_uuid'        => $webhook->uuid,
                        'sent_at'             => Carbon::now(),
                    ])
                    ->url($webhook->url)
                    ->payload($payload)
                    ->useSecret($apiSecret)
                    ->dispatch();
            } catch (\Exception|\Aws\Sqs\Exception\SqsException $exception) {
                // get webhook attempt request/response interfaces
                $response = $exception->getResponse();
                $request  = $exception->getRequest();

                // Log error
                logger()->error($exception->getMessage());

                // Prepare log data
                $webhookRequestLogData = [
                    'company_uuid'        => $webhook->company_uuid,
                    'webhook_uuid'        => $webhook->uuid,
                    'api_event_uuid'      => $apiEvent->uuid,
                    'method'              => $request->getMethod(),
                    'status_code'         => $exception->getStatusCode(),
                    'reason_phrase'       => $response->getReasonPhrase(),
                    'duration'            => $durationStart->diffInSeconds(now()),
                    'url'                 => $request->getUri(),
                    'attempt'             => 1,
                    'response'            => $response->getBody(),
                    'status'              => 'failed',
                    'headers'             => $request->getHeaders(),
                    'meta'                => [
                        'exception'         => get_class($exception),
                        'exception_message' => $exception->getMessage(),
                    ],
                    'sent_at' => $durationStart,
                ];

                // Validate api credential, if not uuid then it could be internal
                if (isset($eventData['api_credential_uuid'])) {
                    $webhookRequestLogData['api_credential_uuid'] = $eventData['api_credential_uuid'];
                }

                // Check if it was a personal access token which made the request
                if (isset($eventData['access_token_id'])) {
                    $webhookRequestLogData['access_token_id'] = $eventData['access_token_id'];
                }

                // log webhook error in logs
                WebhookRequestLog::on($connection)->create($webhookRequestLogData);
            }
        }
    }

    /**
     * Resolve the request context which was serialized onto the event when it was dispatched.
     *
     * @param ResourceLifecycleEvent $event
     *
     * @return array<string, mixed>
     */
    public static function resolveEventContext($event): array
    {
        return [
            'api_credential'  => $event->apiCredential,
            'api_key'         => $event->apiKey ?? 'console',
            'api_secret'      => $event->apiSecret ?? 'internal',
            'api_environment' => $event->apiEnvironment ?? 'live',
            'is_sandbox'      => (bool) $event->isSandbox,
            'company'         => $event->companySession,
            'user'            => $event->userSession,
        ];
    }

    /**
     * Replace the session context with the context serialized on the event.
     *
     * The session is replaced unconditionally: a queue worker session may already hold the context
     * of an event it handled earlier, and that context must not be applied to this event. Downstream
     * code (model scopes, observers, resources) still reads this context from the session, so it is
     * written there for the duration of the job only.
     *
     * @param ResourceLifecycleEvent $event
     *
     * @return callable a callback which restores the session to the state it was in before the event
     */
    public function setSessionFromEvent($event): callable
    {
        return $this->applySessionContext(static::resolveEventContext($event));
    }

    /**
     * Write a resolved event context to the session, replacing whatever was there before.
     *
     * @param array<string, mixed> $context
     *
     * @return callable a callback which restores the session to the state it was in before the event
     */
    protected function applySessionContext(array $context): callable
    {
        $previous = [];

        foreach (static::$contextSessionKeys as $key) {
            if (session()->has($key)) {
                $previous[$key] = session()->get($key);
            }

            session()->put($key, $context[$key]);
        }

        return function () use ($previous) {
            foreach (static::$contextSessionKeys as $key) {
                if (array_key_exists($key, $previous)) {
                    session()->put($key, $previous[$key]);
                    continue;
                }

                session()->remove($key);
            }
        };
    }

    /**
     * Generate a description for the lifecycle event.
     *
     * @return string
     */
    public function getHumanReadableEventDescription(ResourceLifecycleEvent $event)
    {
        // get the model class name
        $modelType = $event->modelHumanName;
        $eventName = strtolower(Utils::humanize($event->eventName));

        // for driver assign
        if ($event->eventName === 'driver_assigned') {
            $eventName = 'assigned a driver';
        }

        // initialize description
        $description = $eventName === 'created' ? 'A new ' : '';
        $description = $eventName === 'updated' ? 'A ' : $description;

        // if model has  name use it instead of `A ...`
        if (isset($event->modelRecordName)) {
            $modelName = $event->modelRecordName;
            // set the description x is a / was
            $description = $eventName === 'created' ? $modelName . ' is a new ' . $modelType : '';
            $description = $eventName !== 'created' ? 'A ' . $modelType . ' (' . $modelName . ') was ' . $eventName : $description;
        } else {
            // set the resouce type in the description
            $description .= $modelType . ' ';
            $description .= 'was ' . $eventName;
        }

        if ($event->apiEnvironment && $event->apiKey) {
            $description .= ' via API';
        } elseif ($event->userSession) {
            // if event is triggered by a user in the console
            // get current user
            $user = User::find($event->userSession);

            if ($user) {
                $description .= ' by ' . $user->name;
            }
        }

        // return description
        return $description;
    }
}
