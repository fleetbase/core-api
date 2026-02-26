<?php

namespace Fleetbase\Http\Requests;

use Illuminate\Contracts\Validation\Validator;

class LoginRequest extends FleetbaseRequest
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
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            // Intentionally no 'exists:users,email' rule here — exposing whether
            // an identity exists in the database enables user enumeration attacks.
            // Validation of identity existence is handled in the controller with
            // a generic error message to prevent information leakage.
            'identity' => ['required'],
            'password' => ['required'],
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
            'identity.required' => 'An email address or phone number is required.',
            'password.required' => 'A password is required.',
        ];
    }
}
