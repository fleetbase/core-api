<?php

namespace Fleetbase\Http\Requests\Internal;

use Fleetbase\Http\Requests\FleetbaseRequest;

class UploadBase64FileRequest extends FleetbaseRequest
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
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'data'         => ['required'],
            'file_name'    => ['required'],
            'file_type'    => ['nullable', 'string'],
            'content_type' => ['nullable', 'string'],
            'subject_uuid' => ['nullable', 'string'],
            'subject_type' => ['nullable', 'string'],
            // Image resize parameters
            'resize'         => 'nullable|string|in:thumb,sm,md,lg,xl,2xl',
            'resize_width'   => 'nullable|integer|min:1|max:10000',
            'resize_height'  => 'nullable|integer|min:1|max:10000',
            'resize_mode'    => 'nullable|string|in:fit,crop,stretch,contain',
            'resize_quality' => 'nullable|integer|min:1|max:100',
            'resize_format'  => 'nullable|string|in:jpg,jpeg,png,webp,gif,bmp,avif',
            'resize_upscale' => 'nullable|boolean',
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
            'data.required'       => 'Please provide a base64 encoded file.',
            'file_name.required'  => 'Please provide a file name.',
            'resize.in'           => 'Invalid resize preset. Must be one of: thumb, sm, md, lg, xl, 2xl',
            'resize_mode.in'      => 'Invalid resize mode. Must be one of: fit, crop, stretch, contain',
            'resize_quality.min'  => 'Quality must be at least 1.',
            'resize_quality.max'  => 'Quality must not exceed 100.',
            'resize_width.min'    => 'Width must be at least 1 pixel.',
            'resize_width.max'    => 'Width must not exceed 10000 pixels.',
            'resize_height.min'   => 'Height must be at least 1 pixel.',
            'resize_height.max'   => 'Height must not exceed 10000 pixels.',
            'resize_format.in'    => 'Invalid format. Must be one of: jpg, jpeg, png, webp, gif, bmp, avif',
        ];
    }
}
