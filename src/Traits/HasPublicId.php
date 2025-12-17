<?php

namespace Fleetbase\Traits;

use Fleetbase\Support\Utils;
use Illuminate\Support\Facades\DB;

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
     * Generate a hashid with maximum uniqueness.
     *
     * @return string
     */
    public static function getPublicId()
    {
        $sqids  = new \Sqids\Sqids();
        
        // Maximize uniqueness with multiple entropy sources
        $hashid = lcfirst($sqids->encode([
            time(),                              // Current second
            (int)(microtime(true) * 1000000),   // Microseconds (increased precision)
            getmypid(),                          // Process ID
            rand(0, 999999),                     // Large random number
            rand(0, 999999),                     // Another large random number
            rand(0, 999999),                     // Third random number for extra entropy
        ]));
        
        // Increase from 7 to 10 characters for better collision resistance
        // 62^10 = 839 quadrillion combinations vs 62^7 = 3.5 trillion
        $hashid = substr($hashid, 0, 10);

        return $hashid;
    }

    /**
     * Generate a unique public ID with robust race condition protection.
     *
     * @param string|null $type The public ID type prefix
     * @param int $attempt Current attempt number (for internal recursion tracking)
     * @return string
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
            // attempt 0: 1ms, attempt 1: 2ms, attempt 2: 4ms, etc.
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
