<?php

namespace Fleetbase\Traits;

use Fleetbase\Support\Utils;
use Illuminate\Support\Str;

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
     * Generate a random public ID suffix.
     *
     * @return string
     */
    public static function getPublicId()
    {
        // Keep entropy in the retained suffix; bulk importers may generate thousands in the same second.
        return Str::lower(Str::random(10));
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
