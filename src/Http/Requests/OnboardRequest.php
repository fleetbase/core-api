<?php

namespace Fleetbase\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
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

    protected function failedValidation(Validator $validator)
    {
        $errors   = $validator->errors();
        $response = [
            'errors' => [$errors->first()],
        ];
        // if more than one error display the others
        if ($errors->count() > 1) {
            $response['errors'] = collect($errors->all())
                ->values()
                ->toArray();
        }

        return response()->json($response, 422);
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array
     */
    public function attributes()
    {
        return collect(array_keys($this->rules()))
            ->mapWithKeys(function ($key) {
                return [$key => str_replace(['.', '_'], ' ', $key)];
            })
            ->toArray();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'name'                  => 'required',
            'email'                 => ['required', 'email', Rule::unique('users')->whereNull('deleted_at')],
            'phone'                 => ['nullable', Rule::unique('users')->whereNull('deleted_at')],
            'password'              => 'required|confirmed',
            'password_confirmation' => 'required',
            'organization_name'     => 'required',
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
