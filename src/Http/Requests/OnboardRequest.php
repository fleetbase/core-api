<?php

namespace Fleetbase\Http\Requests;

use Illuminate\Validation\Rule;

class OnboardRequest extends FleetbaseRequest
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
            'name'                  => ['required'],
            'email'                 => ['required', 'email', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'phone'                 => ['nullable', Rule::unique('users', 'phone')->whereNull('deleted_at')],
            'password'              => ['required', 'confirmed', 'min:4'],
            'password_confirmation' => ['required'],
            'organization_name'     => ['required'],
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
            '*.required'        => 'Your :attribute is required to signup',
            'email'             => 'You must enter a valid :attribute to signup',
            'email.unique'      => 'An account with this email address already exists',
            'phone.unique'      => 'An account with this phone number already exists',
            'password.required' => 'You must enter a password to signup',
        ];
    }
}
