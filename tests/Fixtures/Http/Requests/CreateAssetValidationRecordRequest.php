<?php

namespace Fleetbase\Tests\Fixtures\Http\Requests;

class CreateAssetValidationRecordRequest
{
    public function authorize()
    {
        return false;
    }

    public function rules()
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
