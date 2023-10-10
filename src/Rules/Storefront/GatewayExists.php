<?php

namespace Fleetbase\Rules\Storefront;

use Fleetbase\Models\Storefront\Gateway;
use Illuminate\Contracts\Validation\Rule;

class GatewayExists implements Rule
{
    /**
     * Determine if the validation rule passes.
     *
     * @param string $attribute
     *
     * @return bool
     */
    public function passes($attribute, $value)
    {
        if ($value === 'cash') {
            return true;
        }

        return Gateway::where(['code' => $value])->exists();
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return 'No gateway by code provided exists.';
    }
}
