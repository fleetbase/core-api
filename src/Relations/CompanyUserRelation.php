<?php

namespace Fleetbase\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Match a user's membership by both user UUID and the user's company UUID.
 */
class CompanyUserRelation extends HasOne
{
    public function addConstraints()
    {
        parent::addConstraints();

        if (static::$constraints) {
            $this->query->where($this->related->qualifyColumn('company_uuid'), $this->parent->company_uuid);
        }
    }

    public function addEagerConstraints(array $models)
    {
        parent::addEagerConstraints($models);

        // Eloquent builds eager relations on an empty parent. Read company UUIDs
        // from the actual models instead, retaining the user/company pairs.
        $this->query->where(function (Builder $query) use ($models) {
            foreach (collect($models)->groupBy('company_uuid') as $companyModels) {
                $query->orWhere(function (Builder $query) use ($companyModels) {
                    $query->where($this->related->qualifyColumn('company_uuid'), $companyModels->first()->company_uuid)
                        ->whereIn($this->foreignKey, $companyModels->pluck($this->localKey)->all());
                });
            }
        });
    }

    public function match(array $models, Collection $results, $relation)
    {
        $memberships = $results->groupBy('company_uuid');

        foreach (collect($models)->groupBy('company_uuid') as $companyUuid => $companyModels) {
            parent::match($companyModels->all(), $memberships->get($companyUuid, new Collection()), $relation);
        }

        return $models;
    }

    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*'])
    {
        return parent::getRelationExistenceQuery($query, $parentQuery, $columns)
            ->whereColumn($this->related->qualifyColumn('company_uuid'), $parentQuery->getModel()->qualifyColumn('company_uuid'));
    }
}
