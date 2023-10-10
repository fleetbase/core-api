<?php

namespace Fleetbase\Traits;

use Fleetbase\Support\Utils;
use Vinkla\Hashids\Facades\Hashids;

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
     * Generate a hashid.
     *
     * @return string
     */
    public static function getPublicId()
    {
        $hashid = lcfirst(Hashids::encode(time(), rand(), rand()));
        $hashid = substr($hashid, 0, 7);

        return $hashid;
    }

    public static function generatePublicId(string $type)
    {
        $model  = new static();
        $hashid = static::getPublicId();
        $exists = $model->where('public_id', 'like', '%' . $hashid . '%')->withTrashed()->exists();

        if ($exists) {
            return static::generatePublicId($type);
        }

        return $type . '_' . $hashid;
    }

    /**
     * The resource table name.
     *
     * @var string
     */
    public static function getPublicIdType()
    {
        return with(new static())->publicIdType;
    }
}
