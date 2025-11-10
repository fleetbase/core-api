<?php

namespace Fleetbase\Http\Requests;

use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FleetbaseRequest
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
            'password'              => ['required', 'confirmed', 'string', Password::min(8)->mixedCase()->letters()->numbers()->symbols()->uncompromised()],
            'password_confirmation' => ['sometimes', 'min:4', 'max:64'],
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
            '*.required'             => 'Your :attribute is required.',
            'password.required'      => 'You must enter a password.',
            'password.mixed'         => 'Password must contain both uppercase and lowercase letters.',
            'password.letters'       => 'Password must contain at least 1 letter.',
            'password.numbers'       => 'Password must contain at least 1 number.',
            'password.symbols'       => 'Password must contain at least 1 symbol.',
            'password.uncompromised' => 'The password you entered has appeared in a data breach. Please choose a different one.',
        ];
    }
}
