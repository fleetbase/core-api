<?php

namespace Fleetbase\Http\Requests\Api;

use Fleetbase\Http\Requests\FleetbaseRequest;
use Fleetbase\Rules\PublicWebhookUrl;

/**
 * Create or update a webhook endpoint with an API key (POST/PUT /v1/webhook-endpoints).
 */
class WebhookEndpointRequest extends FleetbaseRequest
{
    public function authorize(): bool
    {
        return (bool) session('company') && (bool) session('api_credential');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $events = config('api.events');
        $known  = is_array($events) && count($events) ? 'in:' . implode(',', $events) : 'string';

        return [
            'url'         => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'url', 'max:2048', new PublicWebhookUrl()],
            'events'      => ['sometimes', 'nullable', 'array'],
            'events.*'    => ['string', $known],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'version'     => ['sometimes', 'nullable', 'string', 'max:32'],
            'status'      => ['sometimes', 'in:enabled,disabled'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'url.required' => 'A webhook URL is required.',
            'url.url'      => 'The webhook URL must be a valid URL.',
            'events.*.in'  => 'Unknown webhook event: :input.',
        ];
    }
}
