<?php

namespace Fleetbase\Http\Requests;

use Illuminate\Validation\Rule;

class CreateCommentRequest extends FleetbaseRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return request()->session()->has('api_credential');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'subject' => [Rule::requiredIf(function () {
                return !$this->filled('subject_id') && !$this->filled('subject_type') && !$this->filled('parent') && $this->isMethod('POST');
            })],
            'subject_id' => [Rule::requiredIf(function () {
                return !$this->filled('parent') && !$this->filled('subject') && $this->isMethod('POST');
            })],
            'subject_type' => [Rule::requiredIf(function () {
                return !$this->filled('parent') && !$this->filled('subject') && $this->isMethod('POST');
            })],
            'parent' => [Rule::requiredIf(function () {
                return !$this->filled('subject') && !$this->filled('subject_type') && !$this->filled('subject_id') && $this->isMethod('POST');
            })],
            'content'         => ['required'],
        ];
    }
}
