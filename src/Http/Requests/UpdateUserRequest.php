<?php

namespace Fleetbase\Http\Requests;

use Fleetbase\Rules\EmailDomainExcluded;
use Fleetbase\Rules\ValidPhoneNumber;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FleetbaseRequest
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
     * Uses `sometimes` + `required` (the correct Laravel pattern for PATCH/PUT):
     * - If the field is present in the payload it must pass all rules, including
     *   `required` which rejects empty strings and null.
     * - If the field is absent entirely it is skipped, allowing partial updates.
     *
     * @return array
     */
    public function rules()
    {
        // Resolve the target user UUID from the route parameter so that the
        // uniqueness rules can correctly ignore the user's own current value.
        $userId = $this->route('user') ?? $this->route('id');

        return [
            'name'  => ['sometimes', 'required', 'string', 'min:2', 'max:100'],

            // Email must be a valid address, must not be empty, and must remain
            // unique across non-deleted users — ignoring the current user's own row.
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')
                    ->ignore($userId, 'uuid')
                    ->whereNull('deleted_at'),
                new EmailDomainExcluded(),
            ],

            // Phone is optional (some user types may not have one), but if it is
            // supplied it must be a valid E.164 number and must remain unique.
            // `nullable` allows explicit null to clear the field; `required_with`
            // is not used here because phone is genuinely optional on some accounts.
            'phone' => [
                'sometimes',
                'nullable',
                new ValidPhoneNumber(),
                Rule::unique('users', 'phone')
                    ->ignore($userId, 'uuid')
                    ->whereNull('deleted_at'),
            ],
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
            'name.required'   => 'Name cannot be empty.',
            'name.min'        => 'Name must be at least 2 characters.',
            'email.required'  => 'Email address cannot be empty.',
            'email.email'     => 'A valid email address is required.',
            'email.unique'    => 'An account with this email address already exists.',
            'phone.unique'    => 'An account with this phone number already exists.',
        ];
    }
}
