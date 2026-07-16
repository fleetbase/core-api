<?php

namespace Fleetbase\Http\Requests\Internal;

use Fleetbase\Http\Requests\FleetbaseRequest;
use Fleetbase\Rules\EmailDomainExcluded;
use Illuminate\Validation\Rule;

class ChangeUserEmailRequest extends FleetbaseRequest
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
            'email' => ['required', 'email', new EmailDomainExcluded(), Rule::unique('users', 'email')->whereNull('deleted_at')],
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
            'email.required' => 'A new email address is required.',
            'email.email'    => 'You must enter a valid email address.',
            'email.unique'   => 'An account with this email address already exists.',
        ];
    }
}
