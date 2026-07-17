<?php

namespace Fleetbase\Tests\Fixtures\Http\Requests;

class UpdateAssetValidationRecordRequest extends CreateAssetValidationRecordRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
