<?php

namespace Fleetbase\Http\Requests\Internal;

use Fleetbase\Http\Requests\FleetbaseRequest;
use Fleetbase\Rules\EmailDomainExcluded;
use Illuminate\Validation\Rule;

class ChangeCurrentUserEmailRequest extends FleetbaseRequest
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
        $user = $this->user();

        return [
            'email'    => [
                'required',
                'email',
                new EmailDomainExcluded(),
                Rule::unique('users', 'email')->whereNull('deleted_at')->ignore($user?->uuid, 'uuid'),
            ],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Configure the validator instance.
     *
     * @param \Illuminate\Validation\Validator $validator
     *
     * @return void
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $user     = $this->user();
            $password = $this->input('password');

            if (!$user || !is_string($password) || !$user->checkPassword($password)) {
                $validator->errors()->add('password', 'The current password provided is invalid.');
            }
        });
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'email.required'    => 'A new email address is required.',
            'email.email'       => 'You must enter a valid email address.',
            'email.unique'      => 'An account with this email address already exists.',
            'password.required' => 'The current password is required.',
            'password.string'   => 'The current password must be a string.',
        ];
    }
}
