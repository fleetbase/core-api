<?php

namespace Fleetbase\Rules;

use Fleetbase\Models\Place;
use Fleetbase\Models\Storefront\StoreLocation;
use Fleetbase\Support\Utils;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Str;

class IsValidLocation implements Rule
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
        // Validate Place id
        if (Str::startsWith($value, 'place_')) {
            return Place::where('public_id', $value)->exists();
        }

        // Validate StoreLocation id
        if (Str::startsWith($value, 'store_location_')) {
            return StoreLocation::where('public_id', $value)->exists();
        }

        // Validate object with coordinates
        if (isset($value->coordinates)) {
            return Utils::isCoordinates($value->coordinates);
        }

        // Validate coordinates
        if (Utils::isCoordinates($value)) {
            return true;
        }

        return false;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return 'Invalid :attribute.';
    }
}
