<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\Group;
use Fleetbase\Models\Policy;
use Fleetbase\Models\Role;
use Fleetbase\Models\User;
use Fleetbase\Support\Auth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class IamSearchController extends Controller
{
    private const SEARCH_TYPES = ['users', 'groups', 'roles', 'policies'];

    public function search(Request $request): JsonResponse
    {
        $query = trim((string) ($request->input('query') ?: $request->input('q')));
        $limit = max(1, min((int) $request->input('limit', 12), 24));

        if ($query === '') {
            return response()->json(['results' => []]);
        }

        $types        = $this->requestedTypes($request);
        $perTypeLimit = max(1, (int) ceil($limit / max(count($types), 1)));
        $results      = collect();

        foreach ($types as $type) {
            if (!$this->canSearchType($type)) {
                continue;
            }

            $results = $results->merge($this->searchType($type, $query, $perTypeLimit));
        }

        return response()->json([
            'results' => $results->take($limit)->values(),
        ]);
    }

    private function requestedTypes(Request $request): array
    {
        $types = $request->input('types', self::SEARCH_TYPES);

        if (is_string($types)) {
            $types = array_filter(array_map('trim', explode(',', $types)));
        }

        if (!is_array($types)) {
            return self::SEARCH_TYPES;
        }

        $types = array_values(array_intersect($types, self::SEARCH_TYPES));

        return empty($types) ? self::SEARCH_TYPES : $types;
    }

    private function canSearchType(string $type): bool
    {
        $permissions = [
            'users'    => 'iam see user',
            'groups'   => 'iam see group',
            'roles'    => 'iam see role',
            'policies' => 'iam see policy',
        ];

        $user = Auth::getUserFromSession();

        if ($user->isAdmin()) {
            return true;
        }

        return Auth::can($permissions[$type]);
    }

    private function searchType(string $type, string $query, int $limit): Collection
    {
        return match ($type) {
            'users'    => $this->searchUsers($query, $limit),
            'groups'   => $this->searchGroups($query, $limit),
            'roles'    => $this->searchRoles($query, $limit),
            'policies' => $this->searchPolicies($query, $limit),
            default    => collect(),
        };
    }

    private function searchUsers(string $query, int $limit): Collection
    {
        $companyUuid = session('company');
        $userUuids   = CompanyUser::where('company_uuid', $companyUuid)
            ->whereNull('deleted_at')
            ->pluck('user_uuid');

        if ($userUuids->isEmpty()) {
            return collect();
        }

        return User::whereIn('uuid', $userUuids)
            ->where(function (Builder $builder) use ($query) {
                $this->whereLike($builder, ['name', 'email', 'phone', 'public_id', 'uuid'], $query);
            })
            ->limit($limit)
            ->get(['uuid', 'public_id', 'name', 'email', 'phone'])
            ->map(fn (User $user) => $this->result(
                label: $user->name ?: $user->email ?: $user->public_id,
                description: $user->email ?: $user->phone ?: 'IAM user',
                icon: 'user',
                type: 'User',
                route: 'console.iam.users.index',
                breadcrumb: 'IAM > Users',
                query: $query,
                viewParam: 'view_user',
                viewId: $user->uuid
            ));
    }

    private function searchGroups(string $query, int $limit): Collection
    {
        return Group::where('company_uuid', session('company'))
            ->where(function (Builder $builder) use ($query) {
                $this->whereLike($builder, ['name', 'description', 'public_id', 'uuid'], $query);
            })
            ->limit($limit)
            ->get(['uuid', 'public_id', 'name', 'description'])
            ->map(fn (Group $group) => $this->result(
                label: $group->name ?: $group->public_id,
                description: $group->description ?: 'IAM group',
                icon: 'building',
                type: 'Group',
                route: 'console.iam.groups.index',
                breadcrumb: 'IAM > Groups',
                query: $query,
                viewParam: 'view_group',
                viewId: $group->uuid
            ));
    }

    private function searchRoles(string $query, int $limit): Collection
    {
        return Role::where(function (Builder $builder) {
            $builder->where('company_uuid', session('company'))->orWhereNull('company_uuid');
        })
            ->where(function (Builder $builder) use ($query) {
                $this->whereLike($builder, ['name', 'description', 'service', 'id'], $query);
            })
            ->limit($limit)
            ->get(['id', 'company_uuid', 'name', 'description', 'service'])
            ->map(fn (Role $role) => $this->result(
                label: $role->name,
                description: $role->description ?: $role->service ?: 'IAM role',
                icon: 'tag',
                type: 'Role',
                route: 'console.iam.roles.index',
                breadcrumb: 'IAM > Roles',
                query: $query,
                viewParam: 'view_role',
                viewId: $role->id
            ));
    }

    private function searchPolicies(string $query, int $limit): Collection
    {
        return Policy::where(function (Builder $builder) {
            $builder->where('company_uuid', session('company'))->orWhereNull('company_uuid');
        })
            ->where(function (Builder $builder) use ($query) {
                $this->whereLike($builder, ['name', 'description', 'service', 'id'], $query);
            })
            ->limit($limit)
            ->get(['id', 'company_uuid', 'name', 'description', 'service'])
            ->map(fn (Policy $policy) => $this->result(
                label: $policy->name,
                description: $policy->description ?: $policy->service ?: 'IAM policy',
                icon: 'shield',
                type: 'Policy',
                route: 'console.iam.policies.index',
                breadcrumb: 'IAM > Policies',
                query: $query,
                viewParam: 'view_policy',
                viewId: $policy->id
            ));
    }

    private function whereLike(Builder $builder, array $columns, string $query): void
    {
        $like = '%' . Str::replace(['%', '_'], ['\\%', '\\_'], $query) . '%';

        foreach ($columns as $index => $column) {
            $method = $index === 0 ? 'where' : 'orWhere';
            $builder->{$method}($column, 'like', $like);
        }
    }

    private function result(string $label, string $description, string $icon, string $type, string $route, string $breadcrumb, string $query, string $viewParam, string $viewId): array
    {
        return [
            'label'       => $label,
            'description' => $description,
            'icon'        => $icon,
            'type'        => $type,
            'route'       => $route,
            'breadcrumb'  => $breadcrumb,
            'queryParams' => [
                'query'    => $query,
                $viewParam => $viewId,
            ],
        ];
    }
}
