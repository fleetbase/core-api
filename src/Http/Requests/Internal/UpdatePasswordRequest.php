<?php

namespace Fleetbase\Http\Requests\Internal;

use Fleetbase\Http\Requests\FleetbaseRequest;
use Illuminate\Validation\Rules\Password;

class UpdatePasswordRequest extends FleetbaseRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return $this->user();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'password'              => [
                'required',
                'confirmed',
                'string',
                Password::min(8)
                    ->mixedCase()
                    ->letters()
                    ->numbers()
                    ->symbols()
                    ->uncompromised(),
            ],
            'password_confirmation' => ['required', 'string'],
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
            'password.required'      => 'You must enter a password.',
            'password.min'           => 'Password must be at least 8 characters.',
            'password.mixed'         => 'Password must contain both uppercase and lowercase letters.',
            'password.letters'       => 'Password must contain at least one letter.',
            'password.numbers'       => 'Password must contain at least one number.',
            'password.symbols'       => 'Password must contain at least one symbol.',
            'password.uncompromised' => 'This password has appeared in a data breach. Please choose a different one.',
        ];
    }
}
