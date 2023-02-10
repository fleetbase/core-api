<?php

namespace Fleetbase\Http\Requests\Internal;

use Fleetbase\Http\Requests\Request;

class InviteUserRequest extends Request
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
            'user.email' => 'required|email',
            'user.name' => 'required',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array
     */
    public function attributes()
    {
        return [
            'user.email' => 'email address',
            'user.name' => 'name',
        ];
    }
}
