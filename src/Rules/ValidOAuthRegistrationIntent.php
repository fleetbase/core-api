<?php

namespace Fleetbase\Rules;

use Fleetbase\Support\OAuth;
use Illuminate\Contracts\Validation\Rule;

/**
 * Validates that an OAuth registration intent is still good.
 *
 * Non-consuming: the intent is single-use, and burning it here would mean that any
 * other field failing validation would also destroy the user's proven identity and
 * force them back through the provider.
 */
class ValidOAuthRegistrationIntent implements Rule
{
    /**
     * Determine if the validation rule passes.
     *
     * @param string $attribute
     */
    public function passes($attribute, $value): bool
    {
        return OAuth::isValidRegistrationIntent(is_string($value) ? $value : null);
    }

    /**
     * Get the validation error message.
     */
    public function message(): string
    {
        return 'This sign-in session has expired. Please sign in with your provider again.';
    }
}
