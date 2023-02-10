<?php

namespace Fleetbase\Scopes;

use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class DriverScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function apply(Builder $builder, Model $model)
    {
        // only allowed to see drivers belonging to current authenticated company
        // $builder->where('company_uuid', session('company'));
        // $builder->where(function ($query) {
        //     $query->where('company_uuid', session('company'));
        //     $query->orWhereHas('user', function ($query) {
        //         $query->where('company_uuid', session('company'));
        //     });
        // });
        // make sure the user of the driver isn't deleted
        $builder->whereHas('user');
    }
}
