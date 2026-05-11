<?php

namespace Fleetbase\Support;

class Timezone
{
    /**
     * Return the first valid IANA timezone from a list of candidates.
     */
    public static function firstValid(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $candidate = trim($candidate);
            if (in_array($candidate, \DateTimeZone::listIdentifiers(), true)) {
                return $candidate;
            }
        }

        return null;
    }
}
