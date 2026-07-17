<?php

namespace Fleetbase\Http\Requests\Internal;

use Fleetbase\Http\Requests\FleetbaseRequest;
use Fleetbase\Rules\PublicWebhookUrl;

class WebhookEndpointRequest extends FleetbaseRequest
{
    public function authorize()
    {
        return session('company');
    }

    public function rules()
    {
        return [
            'url' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'url', new PublicWebhookUrl()],
        ];
    }

    public function messages()
    {
        return [
            'url.required' => 'A webhook URL is required.',
            'url.url'      => 'The webhook URL must be a valid URL.',
        ];
    }
}
