<?php

namespace Fleetbase\Listeners;

use Fleetbase\Events\ResourceLifecycleEvent;
use Fleetbase\Models\ApiEvent;
use Fleetbase\Models\User;
use Fleetbase\Models\WebhookEndpoint;
use Fleetbase\Models\WebhookRequestLog;
use Fleetbase\Support\Utils;
use Fleetbase\Webhook\WebhookCall;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SendResourceLifecycleWebhook implements ShouldQueue
{
    /**
     * Handle the event.
     *
     * @param \Fleetbase\Events\ResourceLifecycleEvent $event
     *
     * @return void
     */
    public function handle($event)
    {
        $this->setSessionFromEvent($event);

        // get session variables or fallback to event value
        $companyId = session()->get('company', $event->companySession);
        // $userId = session()->get('user', $event->userSession);
        $apiCredentialId = session()->get('api_credential', $event->apiCredential);
        $apiKey          = session()->get('api_key', $event->apiKey);
        $apiSecret       = session()->get('api_secret', $event->apiSecret);
        $apiEnvironment  = session()->get('api_environment', $event->apiEnvironment);
        $isSandbox       = session()->get('is_sandbox', $event->isSandbox);

        try {
            // log the api event
            $apiEvent = ApiEvent::create([
                'company_uuid'        => $companyId,
                'api_credential_uuid' => $apiCredentialId,
                'event'               => $event->broadcastAs(),
                'source'              => $apiCredentialId ? 'api' : 'console',
                'data'                => $event->getEventData(),
                'method'              => $event->requestMethod,
                'description'         => $this->getHumanReadableEventDescription($event),
            ]);
        } catch (QueryException $e) {
            // Log::error('Failed to create ApiEvent! ' . $e->getMessage());
            // failed to log api event
            return;
        }

        // if no api environment set used do not send webhooks
        if (!$apiEnvironment) {
            return;
        }

        // get all webhooks for current company
        $webhooks = WebhookEndpoint::where([
            'company_uuid' => $companyId,
            'status'       => 'enabled',
            'mode'         => $apiEnvironment,
        ])->where(function ($q) use ($apiCredentialId) {
            $q->whereNull('api_credential_uuid');
            $q->orWhere('api_credential_uuid', $apiCredentialId);
        })->get();

        // Log::info('[webhooks] ' . print_r($webhooks, true));
        // send webhook for event
        foreach ($webhooks as $webhook) {
            Log::info('[webhooks] #event ' . print_r($apiEvent->event, true));
            Log::info('[webhooks] #events ' . print_r(implode(', ', $webhook->events), true));

            // only send webhook if webhook requires this event
            if (!empty($webhook->events) && is_array($webhook->events) && !in_array($apiEvent->event, $webhook->events)) {
                continue;
            }

            $durationStart = now();
            $connection    = $isSandbox ? 'sandbox' : 'mysql';

            try {
                // send webhook for the event
                WebhookCall::create()
                    ->meta([
                        'is_sandbox'          => $isSandbox,
                        'api_key'             => $apiKey,
                        'api_credential_uuid' => $apiCredentialId,
                        'company_uuid'        => $webhook->company_uuid,
                        'api_event_uuid'      => $apiEvent->uuid,
                        'webhook_uuid'        => $webhook->uuid,
                        'sent_at'             => Carbon::now(),
                    ])
                    ->url($webhook->url)
                    ->payload($event->data)
                    ->useSecret($apiSecret)
                    ->dispatch();
            } catch (\Aws\Sqs\Exception\SqsException $exception) {
                // get webhook attempt request/response interfaces
                $response = $exception->getResponse();
                $request  = $exception->getRequest();

                // log webhook error in logs
                WebhookRequestLog::on($connection)->create([
                    'company_uuid'        => $webhook->company_uuid,
                    'webhook_uuid'        => $webhook->uuid,
                    'api_credential_uuid' => $apiCredentialId,
                    'api_event_uuid'      => $apiEvent->uuid,
                    'method'              => $request->getMethod(),
                    'status_code'         => $exception->getStatusCode(),
                    'reason_phrase'       => $response->getReasonPhrase() ?? $exception->getMessage(),
                    'duration'            => $durationStart->diffInSeconds(now()),
                    'url'                 => $request->getUri(),
                    'attempt'             => 1,
                    'response'            => $response->getBody(),
                    'status'              => 'failed',
                    'headers'             => $request->getHeaders(),
                    'meta'                => [
                        'exception' => get_class($exception),
                    ],
                    'sent_at' => $durationStart,
                ]);
            }
        }
    }

    public function setSessionFromEvent($event)
    {
        // set session variables if not set
        if (!session()->has('api_credential')) {
            session()->put('api_credential', $event->apiCredential);
        }

        if (!session()->has('api_key')) {
            session()->put('api_key', $event->apiKey);
        }

        if (!session()->has('api_secret')) {
            session()->put('api_secret', $event->apiSecret);
        }

        if (!session()->has('api_environment')) {
            session()->put('api_environment', $event->apiEnvironment);
        }

        if (!session()->has('is_sandbox')) {
            session()->put('is_sandbox', $event->isSandbox);
        }

        if (!session()->has('company')) {
            session()->put('company', $event->companySession);
        }

        if (!session()->has('user')) {
            session()->put('user', $event->userSession);
        }
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
