<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Http\Requests\Internal\WebhookEndpointRequest;
use Fleetbase\Models\WebhookEndpoint;
use Fleetbase\Rules\PublicWebhookUrl;

class WebhookEndpointController extends FleetbaseController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'webhook_endpoint';

    /**
     * Create webhook endpoint request.
     *
     * @var WebhookEndpointRequest
     */
    public $createRequest = WebhookEndpointRequest::class;

    /**
     * Update webhook endpoint request.
     *
     * @var WebhookEndpointRequest
     */
    public $updateRequest = WebhookEndpointRequest::class;

    /**
     * The service which this controller belongs to.
     *
     * @var string
     */
    public $service = 'developers';

    /**
     * Enables a webhook endpoint.
     *
     * @return \Illuminate\Http\Response
     */
    public function enable(string $id)
    {
        if (!$id) {
            return response()->error('No webhook to enable', 401);
        }

        $webhook = WebhookEndpoint::where('uuid', $id)->where('company_uuid', session('company'))->first();
        if (!$webhook) {
            return response()->error('No webhook found', 401);
        }

        $urlRule = new PublicWebhookUrl();
        if (!$urlRule->passes('url', $webhook->url)) {
            return response()->error($urlRule->message(), 422);
        }

        $webhook->enable();

        return response()->json([
            'message' => 'Webhook enabled',
            'status'  => $webhook->status,
        ]);
    }

    /**
     * Disabled a webhook endpoint.
     *
     * @return \Illuminate\Http\Response
     */
    public function disable(string $id)
    {
        if (!$id) {
            return response()->error('No webhook to disable', 401);
        }

        $webhook = WebhookEndpoint::where('uuid', $id)->where('company_uuid', session('company'))->first();
        if (!$webhook) {
            return response()->error('No webhook found', 401);
        }

        $webhook->disable();

        return response()->json([
            'message' => 'Webhook disabled',
            'status'  => $webhook->status,
        ]);
    }

    /**
     * Get all webhook events applicable.
     *
     * @return \Illuminate\Http\Response
     */
    public static function events()
    {
        return response()->json(config('api.events'));
    }

    /**
     * Get all webhook versions applicable.
     *
     * @return \Illuminate\Http\Response
     */
    public static function versions()
    {
        return response()->json(config('api.versions'));
    }
}
