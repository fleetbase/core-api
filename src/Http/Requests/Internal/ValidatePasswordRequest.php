<?php

namespace Fleetbase\Http\Requests\Internal;

use Fleetbase\Http\Requests\FleetbaseRequest;

class ValidatePasswordRequest extends FleetbaseRequest
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
            'current_password'      => 'required|string|min:6',
            'confirm_password'      => 'required|string|min:6|same:current_password',
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'current_password.required' => 'The current password is required.',
            'current_password.string'   => 'The current password must be a string.',
            'current_password.min'      => 'The current password must be at least 8 characters.',
        ];
    }
}
