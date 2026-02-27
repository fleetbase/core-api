<?php

namespace Fleetbase\Http\Requests\Internal;

use Fleetbase\Http\Requests\FleetbaseRequest;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ConfirmCurrentPassword implements Rule
{
    protected $user;

    public function __construct($user)
    {
        $this->user = $user;
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param string $attribute
     *
     * @return bool
     */
    public function passes($attribute, $value)
    {
        return $this->user && $this->user->checkPassword($value);
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return 'The current password provided is invalid.';
    }
}

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
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(8)
                    ->mixedCase()
                    ->letters()
                    ->numbers()
                    ->symbols()
                    ->uncompromised(),
                new ConfirmCurrentPassword($this->user()),
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
            'password.required'      => 'The current password is required.',
            'password.string'        => 'The current password must be a string.',
            'password.min'           => 'Password must be at least 8 characters.',
            'password.mixed'         => 'Password must contain both uppercase and lowercase letters.',
            'password.letters'       => 'Password must contain at least one letter.',
            'password.numbers'       => 'Password must contain at least one number.',
            'password.symbols'       => 'Password must contain at least one symbol.',
            'password.uncompromised' => 'This password has appeared in a data breach. Please choose a different one.',
        ];
    }
}
