<?php

namespace Fleetbase\Http\Requests\Internal;

use Fleetbase\Http\Requests\Request;

class ResetPasswordRequest extends Request
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
            'code' => ['required', 'exists:verification_codes,code'],
            'link' => ['required', 'exists:verification_codes,uuid'],
            'password' => 'required|confirmed|min:6',
            'password_confirmation' => 'required',
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
            'code' => 'Invalid password reset request!',
            'link' => 'Invalid password reset request!',
        ];
    }
}
