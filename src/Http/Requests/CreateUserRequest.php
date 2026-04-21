<?php

namespace Fleetbase\Http\Requests;

use Fleetbase\Rules\EmailDomainExcluded;
use Fleetbase\Rules\ExcludeWords;
use Fleetbase\Rules\ValidPhoneNumber;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CreateUserRequest extends FleetbaseRequest
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
        return session('company');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Note: email uniqueness is intentionally NOT enforced here. When an email
     * already exists in the system the controller detects this and redirects to
     * the cross-organisation invite flow rather than attempting to create a
     * duplicate user. Enforcing uniqueness at the request layer would prevent
     * that branch from ever being reached.
     *
     * Phone is `sometimes|nullable` because invited users may not supply a phone
     * number at invite time — they complete their profile after accepting. When a
     * phone number IS provided the uniqueness constraint is still enforced so that
     * two active users cannot share the same number.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'name'                  => ['required', 'min:2', 'max:50', 'regex:/^(?!.*\b[a-z0-9]+(?:\.[a-z0-9]+){1,}\b)[a-zA-ZÀ-ÿ\'\-\s\.]+$/u', new ExcludeWords($this->excludedWords)],
            'email'                 => ['required', 'email', new EmailDomainExcluded()],
            'phone'                 => ['sometimes', 'nullable', new ValidPhoneNumber(), Rule::unique('users', 'phone')->whereNull('deleted_at')],
            'password'              => ['sometimes', 'confirmed', 'string', Password::min(8)->mixedCase()->letters()->numbers()->symbols()->uncompromised()],
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
            '*.required'             => 'Your :attribute is required',
            'email'                  => 'You must enter a valid :attribute',
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
