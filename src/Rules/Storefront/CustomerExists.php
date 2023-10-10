<?php

namespace Fleetbase\Rules\Storefront;

use Fleetbase\Models\Contact;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Str;

class CustomerExists implements Rule
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
        $value = Str::replaceFirst('customer', 'contact', $value);

        return Contact::where('public_id', $value)->exists();
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return 'No customer found.';
    }
}
