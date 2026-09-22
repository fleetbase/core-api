<?php

namespace Fleetbase\Http\Requests;

use Fleetbase\Rules\EmailDomainExcluded;
use Fleetbase\Rules\ExcludeWords;
use Fleetbase\Rules\ValidOAuthRegistrationIntent;
use Fleetbase\Rules\ValidPhoneNumber;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class OnboardRequest extends FleetbaseRequest
{
    /**
     * Array of blacklisted words which cannot be used in onboard names and company names.
     *
     * @return array
     */
    protected $excludedWords = ['test', 'test123', 'abctest', 'testing', 'example', 'trial', 'trialing', 'asdf', '1234', 'asdas', 'dsdsds', 'dummy', 'xxxx', 'aaa', 'demo', 'zzz', 'zzzz', 'none'];

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
            'name'                  => ['required', 'min:2', 'max:50', 'regex:/^(?!.*\b[a-z0-9]+(?:\.[a-z0-9]+){1,}\b)[a-zA-ZÀ-ÿ\'\-\s\.]+$/u', new ExcludeWords($this->excludedWords)],
            'email'                 => ['required', 'email', Rule::unique('users', 'email')->whereNull('deleted_at'), new EmailDomainExcluded()],
            'phone'                 => ['required', new ValidPhoneNumber(), Rule::unique('users', 'phone')->whereNull('deleted_at')],
            // A signup proves itself either with a password or with an OAuth
            // registration intent. Everything else — phone, organization name, the
            // word blacklists — is unchanged, so an OAuth account is held to exactly
            // the same standard as a password one.
            'password'              => ['required_without:oauth_intent', 'nullable', 'confirmed', 'string', Password::min(8)->mixedCase()->letters()->numbers()->symbols()->uncompromised()],
            'password_confirmation' => ['required_with:password', 'nullable', 'min:4', 'max:64'],
            'oauth_intent'          => ['required_without:password', 'nullable', 'string', new ValidOAuthRegistrationIntent()],
            'organization_name'     => ['required', 'min:4', 'max:100', 'regex:/^(?!.*\b[a-z0-9]+(?:\.[a-z0-9]+){1,}\b)[a-zA-ZÀ-ÿ0-9\'\-\s\.]+$/u', new ExcludeWords($this->excludedWords)],
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
            '*.required'             => 'Your :attribute is required to signup',
            'email'                  => 'You must enter a valid :attribute to signup',
            'email.unique'           => 'An account with this email address already exists',
            'phone.unique'           => 'An account with this phone number already exists',
            'password.required'      => 'You must enter a password.',
            'password.mixed'         => 'Password must contain both uppercase and lowercase letters.',
            'password.letters'       => 'Password must contain at least 1 letter.',
            'password.numbers'       => 'Password must contain at least 1 number.',
            'password.symbols'       => 'Password must contain at least 1 symbol.',
            'password.uncompromised' => 'The password you entered has appeared in a data breach. Please choose a different one.',
        ];
    }
}
