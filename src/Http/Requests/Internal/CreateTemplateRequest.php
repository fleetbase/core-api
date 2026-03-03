<?php

namespace Fleetbase\Http\Requests\Internal;

use Fleetbase\Http\Requests\FleetbaseRequest;

class CreateTemplateRequest extends FleetbaseRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->session()->has('company');
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name'         => 'required|min:2|max:191',
            'context_type' => 'required|string|max:191',
            'orientation'  => 'nullable|in:portrait,landscape',
            'unit'         => 'nullable|in:mm,px,in',
            'width'        => 'nullable|numeric|min:1',
            'height'       => 'nullable|numeric|min:1',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required'         => 'A template name is required.',
            'name.min'              => 'The template name must be at least 2 characters.',
            'context_type.required' => 'A context type is required to determine which variables are available.',
        ];
    }
}
