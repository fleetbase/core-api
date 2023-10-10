<?php

namespace Fleetbase\Http\Requests;

use Fleetbase\Support\Utils;
use Illuminate\Support\Str;

class SwitchOrganizationRequest extends FleetbaseRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'next' => ['required', $this->isApiRequest() ? 'exists:companies,public_id' : 'exists:companies,uuid'],
        ];
    }

    public function isApiRequest()
    {
        $routeNamespace        = Utils::get($this->route(), 'action.namespace');
        $isFleetOpsApiRequest  = $routeNamespace === 'Fleetbase\Http\Controllers\Api\v1';
        $isNavigatorApiRequest = Str::startsWith($this->route()->uri, 'navigator/v1');

        return $isFleetOpsApiRequest || $isNavigatorApiRequest;
    }
}
