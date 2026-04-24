<?php

namespace Fleetbase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClientCompanyRequest extends FormRequest
{
    /**
     * Authorization is enforced by the route middleware chain
     * (`auth:sanctum` + `fleetbase.company.context`) and the
     * controller's explicit `resolveOrg()` / `findScopedClient()`
     * tenant-boundary checks. This request only handles payload
     * shape validation.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'            => ['required', 'string', 'max:255'],
            'client_code'     => ['nullable', 'string', 'max:50'],
            'client_settings' => ['nullable', 'array'],
        ];
    }
}
