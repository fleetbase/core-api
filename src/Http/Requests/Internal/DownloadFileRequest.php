<?php

namespace Fleetbase\Http\Requests\Internal;

use Fleetbase\Http\Requests\FleetbaseRequest;

class DownloadFileRequest extends FleetbaseRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return $this->session()->has('user');
    }

    /**
     * Prepare the data for validation.
     *
     * Ensures route parameters are available for validation rules.
     */
    protected function prepareForValidation(): void
    {
        if ($this->route('id')) {
            $this->merge([
                'id' => $this->route('id'),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'file' => ['required_without:id', 'uuid', 'exists:files,uuid'],
            'id'   => ['required_without:file', 'uuid', 'exists:files,uuid'],
            'disk' => ['sometimes', 'string'],
        ];
    }

    /**
     * Get the validation rules error messages.
     *
     * @return array
     */
    public function messages()
    {
        return [
            // Missing identifier
            'id.required_without'   => 'Please provide a file identifier.',
            'file.required_without' => 'Please provide a file identifier.',

            // Invalid format
            'id.uuid'   => 'The file identifier must be a valid UUID.',
            'file.uuid' => 'The file identifier must be a valid UUID.',

            // File not found
            'id.exists'   => 'The requested file does not exist.',
            'file.exists' => 'The requested file does not exist.',

            // Disk override
            'disk.string' => 'The storage disk must be a valid string.',
        ];
    }
}
