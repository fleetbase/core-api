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
     * Generate a hashid with improved uniqueness using microseconds and process ID.
     *
     * @return string
     */
    public static function getPublicId()
    {
        $sqids  = new \Sqids\Sqids();
        
        // Improve uniqueness by adding microseconds and process ID
        // This significantly reduces collision probability under high load
        $hashid = lcfirst($sqids->encode([
            time(),
            (int)(microtime(true) * 10000), // microseconds for sub-second uniqueness
            getmypid(),                      // process ID for multi-process uniqueness
            rand(),
            rand()
        ]));
        
        $hashid = substr($hashid, 0, 7);

        return $hashid;
    }

    /**
     * Generate a unique public ID with race condition protection.
     *
     * @param string|null $type The public ID type prefix
     * @param int $attempt Current attempt number (for internal recursion tracking)
     * @return string
     */
    public static function generatePublicId(?string $type = null, int $attempt = 0): string
    {
        // Prevent infinite loops
        if ($attempt > 10) {
            throw new \RuntimeException('Failed to generate unique public_id after 10 attempts');
        }

        $model  = new static();
        if (is_null($type)) {
            $type = static::getPublicIdType() ?? strtolower(Utils::classBasename($model));
        }
        
        $hashid   = static::getPublicId();
        $publicId = $type . '_' . $hashid;
        
        // Use exact match instead of LIKE for better performance and accuracy
        $exists = $model->where('public_id', $publicId)->withTrashed()->exists();

        if ($exists) {
            // Add small random delay to reduce collision probability on retry
            usleep(rand(100, 1000)); // 0.1-1ms
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
