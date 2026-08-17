<?php

namespace Fleetbase\Http\Requests;

/**
 * Download validation for the PUBLIC API.
 *
 * The internal counterpart requires the identifier to be a uuid, because the console
 * works in uuids. The public API does not: every other public endpoint addresses a
 * resource by its public_id and explicitly rejects uuids, and an upload returns
 * `file_xxxxxxxx`. Validating the public route with the internal rules meant a consumer
 * could not download the file it had just uploaded — it got
 * "The file identifier must be a valid UUID."
 *
 * Existence is deliberately not asserted here. FileController::download already resolves
 * through File::findRecordOrFail() and answers 404 for an unknown file, which is the
 * correct status for a missing resource; an `exists` rule would report 422 instead.
 */
class DownloadFileRequest extends FleetbaseRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * The route sits behind the `fleetbase.api` middleware group, which authenticates the
     * API credential before this runs. The internal request checks for a session user,
     * which is the wrong notion of identity for a key-authenticated request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
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
            'file' => ['required_without:id', 'string'],
            'id'   => ['required_without:file', 'string'],
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
            'id.required_without'   => 'Please provide a file identifier.',
            'file.required_without' => 'Please provide a file identifier.',
            'id.string'             => 'The file identifier must be a string.',
            'file.string'           => 'The file identifier must be a string.',
            'disk.string'           => 'The storage disk must be a valid string.',
        ];
    }
}
