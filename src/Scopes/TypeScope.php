<?php

namespace Fleetbase\Scopes;

use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class TypeScope implements Scope
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
        // only allow authenticated users to see types created within their own company
        $builder->where(function ($query) {
            $query->where('company_uuid', session('company'));
            $query->orWhereNull('company_uuid');
        }); 
    }
}
