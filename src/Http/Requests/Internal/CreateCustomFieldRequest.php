<?php

namespace Fleetbase\Http\Requests\Internal;

use Fleetbase\Http\Requests\FleetbaseRequest;

class CreateCustomFieldRequest extends FleetbaseRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * You can wire this into policies/guards if needed.
     */
    public function authorize(): bool
    {
        return $this->session()->has('company');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_uuid'      => ['nullable', 'uuid', 'exists:companies,uuid'],
            'category_uuid'     => ['nullable', 'uuid', 'exists:categories,uuid'],
            'subject_uuid'      => ['nullable', 'uuid'],
            'subject_type'      => ['nullable', 'string'],
            'name'              => ['nullable', 'string', 'max:150'],
            'description'       => ['nullable', 'string', 'max:450'],
            'label'             => ['required', 'string', 'max:255'],
            'type'              => ['required', 'string', 'max:50'],
            'for'               => ['nullable', 'string', 'max:150'],
            'component'         => ['nullable', 'string', 'max:150'],
            'options'           => ['nullable', 'array'],
            'options.*'         => ['nullable'],
            'required'          => ['sometimes', 'boolean'],
            'editable'          => ['sometimes', 'boolean'],
            'default_value'     => ['nullable'],
            'validation_rules'  => ['nullable', 'array'],
            'meta'              => ['nullable', 'array'],
            'description'       => ['nullable', 'string'],
            'help_text'         => ['nullable', 'string'],
            'order'             => ['nullable', 'integer'],
        ];
    }

    /**
     * Customize validation error messages (optional).
     */
    public function messages(): array
    {
        return [
            'type.required' => 'A custom field type is required (e.g., text, number, date, etc.).',
            'type.string'   => 'The custom field type must be a valid string.',
        ];
    }
}
