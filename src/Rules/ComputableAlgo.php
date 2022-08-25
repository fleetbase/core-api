<?php

namespace Fleetbase\Rules;

use Illuminate\Contracts\Validation\Rule;
use Fleetbase\Support\Algo;

class ComputableAlgo implements Rule
{
    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        $distanceAndTime = Algo::calculateDrivingDistanceAndTime('1.3506853', '103.87199110000006', '1.3621663', '103.88450490000002');
        
        return Algo::exec($value, $distanceAndTime) > 0;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return 'Algorithm provided is not computable.';
    }
}
