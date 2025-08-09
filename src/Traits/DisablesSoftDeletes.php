<?php

namespace Fleetbase\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

trait DisablesSoftDeletes
{
    /**
     * Override default delete behavior to always hard delete.
     */
    protected function performDeleteOnModel()
    {
        $this->forceDelete();
    }

    /**
     * Remove the SoftDeletes global scope during model boot.
     */
    protected static function bootDisablesSoftDeletes()
    {
        static::addGlobalScope('disablesSoftDeletes', function (Builder $builder) {
            $builder->withoutGlobalScope(SoftDeletingScope::class);
        });
    }

    /**
     * Disables soft delete scrop for all eloquent queries.
     */
    public function newEloquentBuilder($query)
    {
        return parent::newEloquentBuilder($query)->withoutGlobalScope(SoftDeletingScope::class);
    }

    /**
     * Prevent the model from reporting as trashed.
     */
    public function trashed()
    {
        return false;
    }

    /**
     * Prevent restore operations.
     */
    public function restore()
    {
        return $this;
    }
}
