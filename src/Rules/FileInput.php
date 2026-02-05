<?php

namespace Fleetbase\Rules;

use Fleetbase\Support\Utils;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * FileInput Validation Rule.
 *
 * Validates that input is a valid file source (upload, base64, ID, or URL).
 */
class FileInput implements Rule
{
    /**
     * The validation error message.
     */
    protected string $message = 'The :attribute must be a valid file upload, base64 string, file ID, or URL.';

    /**
     * Determine if the validation rule passes.
     *
     * @param string $attribute
     */
    public function passes($attribute, $value): bool
    {
        // Check for UploadedFile
        if ($value instanceof UploadedFile) {
            return $value->isValid();
        }

        if (is_string($value)) {
            // Check for public ID
            if (Utils::isPublicId($value)) {
                return true;
            }

            // Check for Base64 data URI
            if (Str::startsWith($value, 'data:image') || Str::startsWith($value, 'data:application')) {
                return true;
            }

            // Check for valid URL
            if (filter_var($value, FILTER_VALIDATE_URL)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the validation error message.
     */
    public function message(): string
    {
        return $this->message;
    }
}
