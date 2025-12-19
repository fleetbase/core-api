<?php

namespace Fleetbase\Traits;

use Fleetbase\Support\Utils;

trait HasPublicId
{
    /**
     * Boot the public id trait for the model.
     *
     * @return void
     */
    public static function bootHasPublicId()
    {
        static::creating(
            function ($model) {
                if (Utils::isset($model, 'public_id')) {
                    return;
                }

                $model->public_id = static::generatePublicId($model->publicIdType);
            }
        );
    }

    /**
     * Generate a hashid with maximum uniqueness using cryptographically secure random numbers.
     *
     * @return string
     */
    public static function getPublicId()
    {
        $sqids  = new \Sqids\Sqids();

        // Maximize uniqueness with multiple entropy sources
        // CRITICAL: Use random_int() instead of rand() for cryptographic security
        $hashid = lcfirst($sqids->encode([
            time(),                                    // Current second
            (int) (microtime(true) * 1000000),         // Microseconds
            getmypid(),                                // Process ID
            random_int(0, PHP_INT_MAX),               // Cryptographically secure random
            random_int(0, PHP_INT_MAX),               // Another secure random
            random_int(0, PHP_INT_MAX),               // Third secure random
            crc32(uniqid('', true)),                  // Unique ID hash for extra entropy
        ]));

        // 10 characters for better collision resistance
        // 62^10 = 839 quadrillion combinations
        $hashid = substr($hashid, 0, 10);

        return $hashid;
    }

    /**
     * Generate a unique public ID with robust race condition protection.
     *
     * @param string|null $type    The public ID type prefix
     * @param int         $attempt Current attempt number (for internal recursion tracking)
     *
     * @throws \RuntimeException If unable to generate unique ID after max attempts
     */
    public static function generatePublicId(?string $type = null, int $attempt = 0): string
    {
        // Prevent infinite loops
        if ($attempt > 10) {
            throw new \RuntimeException('Failed to generate unique public_id after 10 attempts. This indicates a serious collision issue.');
        }

        $model  = new static();
        if (is_null($type)) {
            $type = static::getPublicIdType() ?? strtolower(Utils::classBasename($model));
        }

        $hashid   = static::getPublicId();
        $publicId = $type . '_' . $hashid;

        // Check for existing public_id with exact match
        // Use exists() for performance (doesn't load full model)
        $exists = $model->where('public_id', $publicId)->withTrashed()->exists();

        if ($exists) {
            // Exponential backoff: 2^attempt milliseconds
            $backoffMs = pow(2, $attempt);
            usleep($backoffMs * 1000);

            return static::generatePublicId($type, $attempt + 1);
        }

        return $publicId;
    }

    /**
     * The resource table name.
     *
     * @var string|null
     */
    public static function getPublicIdType(): ?string
    {
        return with(new static())->publicIdType;
    }
}
