<?php

namespace Fleetbase\Http\Requests\Internal;

use Fleetbase\Http\Requests\Request;

class ResendUserInvite extends Request
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return session('company');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'user' => ['required', 'exists:users,uuid']
        ];
    }
}
