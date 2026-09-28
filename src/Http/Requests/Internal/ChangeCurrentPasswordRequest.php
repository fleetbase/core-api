<?php

namespace Fleetbase\Http\Requests\Internal;

use Fleetbase\Http\Requests\FleetbaseRequest;
use Illuminate\Validation\Rules\Password;

class ChangeCurrentPasswordRequest extends FleetbaseRequest
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
     * The current password is checked here, in the same request that changes it,
     * so a session token alone is not enough to change the password.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'current_password' => [
                'required',
                'string',
                function ($attribute, $value, $fail) {
                    $user = $this->user();
                    if (!$user || !$user->checkPassword($value)) {
                        $fail('The current password provided is invalid.');
                    }
                },
            ],
            'password' => [
                'required',
                'string',
                'confirmed',
                'different:current_password',
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
            'current_password.required' => 'The current password is required.',
            'password.required'         => 'A new password is required.',
            'password.confirmed'        => 'Passwords do not match.',
            'password.different'        => 'The new password must be different from the current password.',
            'password.min'              => 'Password must be at least 8 characters.',
            'password.mixed'            => 'Password must contain both uppercase and lowercase letters.',
            'password.letters'          => 'Password must contain at least one letter.',
            'password.numbers'          => 'Password must contain at least one number.',
            'password.symbols'          => 'Password must contain at least one symbol.',
            'password.uncompromised'    => 'This password has appeared in a data breach. Please choose a different one.',
        ];
    }
}
