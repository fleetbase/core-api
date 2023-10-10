<?php

namespace Fleetbase\Rules\Storefront;

use Fleetbase\Models\Storefront\Cart;
use Illuminate\Contracts\Validation\Rule;

class CartExists implements Rule
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
        return Cart::where(['public_id' => $attribute, 'unique_identifier' => $attribute])->exists();
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return 'Cart session does not exists.';
    }
}
