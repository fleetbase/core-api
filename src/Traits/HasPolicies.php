<?php

namespace Fleetbase\Traits;

use Fleetbase\Contracts\Policy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasPermissions;

trait HasPolicies
{
    use HasPermissions;

    private $policyClass;

    public static function bootHasPolicies()
    {
        static::deleting(function ($model) {
            if (method_exists($model, 'isForceDeleting') && !$model->isForceDeleting()) {
                return;
            }

            $model->policies()->detach();
        });
    }

    public function getPolicyClass()
    {
        if (!isset($this->policyClass)) {
            $this->policyClass = app(PermissionRegistrar::class)->getPolicyClass();
        }

        return $this->policyClass;
    }

    /**
     * A model may have multiple policies.
     */
    public function policies(): BelongsToMany
    {
        return $this->morphToMany(
            \Fleetbase\Models\Policy::class,
            'model',
            'model_has_policies',
            'model_uuid',
            'policy_id'
        );
    }

    /**
     * Scope the model query to certain policies only.
     *
     * @param string|array|\Fleetbase\Contracts\Policy|\Illuminate\Support\Collection $policies
     * @param string                                                                  $guard
     */
    public function scopePolicy(Builder $query, $policies, $guard = null): Builder
    {
        if ($policies instanceof Collection) {
            $policies = $policies->all();
        }

        if (!is_array($policies)) {
            $policies = [$policies];
        }

        $policies = array_map(function ($role) use ($guard) {
            if ($role instanceof Policy) {
                return $role;
            }

            $method = is_numeric($role) ? 'findById' : 'findByName';
            $guard  = $guard ?: $this->getDefaultGuardName();

            return $this->getPolicyClass()->{$method}($role, $guard);
        }, $policies);

        return $query->whereHas('policies', function (Builder $subQuery) use ($policies) {
            $subQuery->whereIn(config('permission.table_names.policies') . '.id', \array_column($policies, 'id'));
        });
    }

    /**
     * Assign the given role to the model.
     *
     * @param array|string|\Fleetbase\Contracts\Policy ...$policies
     *
     * @return $this
     */
    public function assignPolicy(...$policies)
    {
        $policies = collect($policies)
            ->flatten()
            ->map(function ($role) {
                if (empty($role)) {
                    return false;
                }

                return $this->getStoredPolicy($role);
            })
            ->filter(function ($role) {
                return $role instanceof Policy;
            })
            ->each(function ($role) {
                $this->ensureModelSharesGuard($role);
            })
            ->map->id
            ->all();

        $model = $this->getModel();

        if ($model->exists) {
            $this->policies()->sync($policies, false);
            $model->load('policies');
        } else {
            $class = \get_class($model);

            $class::saved(
                function ($object) use ($policies, $model) {
                    $model->policies()->sync($policies, false);
                    $model->load('policies');
                }
            );
        }

        $this->forgetCachedPermissions();

        return $this;
    }

    /**
     * Revoke the given role from the model.
     *
     * @param string|\Fleebase\Contracts\Policy $role
     */
    public function removePolicy($role)
    {
        $this->policies()->detach($this->getStoredPolicy($role));

        $this->load('policies');

        $this->forgetCachedPermissions();

        return $this;
    }

    /**
     * Remove all current policies and set the given ones.
     *
     * @param array|\Fleebase\Contracts\Policy|string ...$policies
     *
     * @return $this
     */
    public function syncPolicies(...$policies)
    {
        $this->policies()->detach();

        return $this->assignPolicy($policies);
    }

    /**
     * Determine if the model has (one of) the given role(s).
     *
     * @param string|int|array|\Fleebase\Contracts\Policy|\Illuminate\Support\Collection $policies
     */
    public function hasPolicy($policies, string $guard = null): bool
    {
        if (is_string($policies) && false !== strpos($policies, '|')) {
            $policies = $this->_convertPipeToArray($policies);
        }

        if (is_string($policies)) {
            return $guard
                ? $this->policies->where('guard_name', $guard)->contains('name', $policies)
                : $this->policies->contains('name', $policies);
        }

        if (is_int($policies)) {
            return $guard
                ? $this->policies->where('guard_name', $guard)->contains('id', $policies)
                : $this->policies->contains('id', $policies);
        }

        if ($policies instanceof Policy) {
            return $this->policies->contains('id', $policies->id);
        }

        if (is_array($policies)) {
            foreach ($policies as $role) {
                if ($this->hasPolicy($role, $guard)) {
                    return true;
                }
            }

            return false;
        }

        return $policies->intersect($guard ? $this->policies->where('guard_name', $guard) : $this->policies)->isNotEmpty();
    }

    /**
     * Determine if the model has any of the given role(s).
     *
     * Alias to hasPolicy() but without Guard controls
     *
     * @param string|int|array|\Fleebase\Contracts\Policy|\Illuminate\Support\Collection $policies
     */
    public function hasAnyPolicy(...$policies): bool
    {
        return $this->hasPolicy($policies);
    }

    /**
     * Determine if the model has all of the given role(s).
     *
     * @param string|array|\Fleebase\Contracts\Policy|\Illuminate\Support\Collection $policies
     */
    public function hasAllPolicies($policies, string $guard = null): bool
    {
        if (is_string($policies) && false !== strpos($policies, '|')) {
            $policies = $this->_convertPipeToArray($policies);
        }

        if (is_string($policies)) {
            return $guard
                ? $this->policies->where('guard_name', $guard)->contains('name', $policies)
                : $this->policies->contains('name', $policies);
        }

        if ($policies instanceof Policy) {
            return $this->policies->contains('id', $policies->id);
        }

        $policies = collect()->make($policies)->map(function ($role) {
            return $role instanceof Policy ? $role->name : $role;
        });

        return $policies->intersect(
            $guard
                ? $this->policies->where('guard_name', $guard)->pluck('name')
                : $this->getPolicyNames()
        ) == $policies;
    }

    /**
     * Return all permissions directly coupled to the model.
     */
    public function getPolicyDirectPermissions(): Collection
    {
        return $this->permissions;
    }

    public function getPolicyNames(): Collection
    {
        return $this->policies->pluck('name');
    }

    protected function getStoredPolicy($role): Policy
    {
        $policyClass = $this->getPolicyClass();

        if (is_numeric($role)) {
            return $policyClass->findById($role, $this->getDefaultGuardName());
        }

        if (is_string($role)) {
            return $policyClass->findByName($role, $this->getDefaultGuardName());
        }

        return $role;
    }

    protected function _convertPipeToArray(string $pipeString)
    {
        $pipeString = trim($pipeString);

        if (strlen($pipeString) <= 2) {
            return $pipeString;
        }

        $quoteCharacter = substr($pipeString, 0, 1);
        $endCharacter   = substr($quoteCharacter, -1, 1);

        if ($quoteCharacter !== $endCharacter) {
            return explode('|', $pipeString);
        }

        if (!in_array($quoteCharacter, ["'", '"'])) {
            return explode('|', $pipeString);
        }

        return explode('|', trim($pipeString, $quoteCharacter));
    }
}
