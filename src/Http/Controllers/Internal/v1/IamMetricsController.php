<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\Activity;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\Group;
use Fleetbase\Models\Permission;
use Fleetbase\Models\Policy;
use Fleetbase\Models\Role;
use Fleetbase\Models\Setting;
use Fleetbase\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class IamMetricsController extends Controller
{
    private const DORMANT_DAYS = 90;

    public function kpis(Request $request): JsonResponse
    {
        $companyUuid = session('company');
        $users       = $this->companyUsersQuery($companyUuid);
        $totalUsers  = (clone $users)->count();
        $mfaCoverage = $this->mfaCoverage($companyUuid, $totalUsers);

        return response()->json([
            'active_users' => $this->metric('Active Users', (clone $users)->where('company_users.status', 'active')->count(), 'users'),
            'pending_invites' => $this->metric('Pending Invites', (clone $users)->where(function ($query) {
                $query->where('company_users.status', 'pending')->orWhereNull('users.email_verified_at');
            })->count(), 'users'),
            'inactive_users' => $this->metric('Inactive Users', (clone $users)->where('company_users.status', 'inactive')->count(), 'users'),
            'dormant_users' => $this->metric('Dormant Users', $this->dormantUsersQuery($companyUuid)->count(), 'users', true),
            'verified_users' => $this->metric('Verified Users', (clone $users)->whereNotNull('users.email_verified_at')->count(), 'users'),
            'mfa_coverage' => $this->metric('MFA Coverage', $mfaCoverage['value'], $mfaCoverage['format'], false, ['available' => $mfaCoverage['available']]),
            'roles' => $this->metric('Roles', Role::where('company_uuid', $companyUuid)->count(), 'roles'),
            'policies' => $this->metric('Policies', Policy::where('company_uuid', $companyUuid)->count(), 'policies'),
        ]);
    }

    public function identityHealth(Request $request): JsonResponse
    {
        $companyUuid = session('company');
        $users       = $this->companyUsersQuery($companyUuid);
        $totalUsers  = (clone $users)->count();
        $mfaCoverage = $this->mfaCoverage($companyUuid, $totalUsers);

        return response()->json([
            'total_users' => $totalUsers,
            'status' => $this->statusCounts($companyUuid),
            'verification' => [
                'verified' => (clone $users)->whereNotNull('users.email_verified_at')->count(),
                'unverified' => (clone $users)->whereNull('users.email_verified_at')->count(),
            ],
            'mfa' => $mfaCoverage,
            'dormant' => [
                'count' => $this->dormantUsersQuery($companyUuid)->count(),
                'threshold_days' => self::DORMANT_DAYS,
            ],
        ]);
    }

    public function accessCoverage(Request $request): JsonResponse
    {
        $companyUuid      = session('company');
        $companyUsers     = CompanyUser::where('company_uuid', $companyUuid)->whereNull('deleted_at')->get(['uuid', 'user_uuid']);
        $userUuids        = $companyUsers->pluck('user_uuid');
        $companyUserUuids = $companyUsers->pluck('uuid');
        $roleUserUuids    = $this->modelAssignmentUserUuids('model_has_roles', $companyUsers, $companyUserUuids);
        $policyUserUuids  = $this->modelAssignmentUserUuids('model_has_policies', $companyUsers, $companyUserUuids);
        $directUserUuids  = $this->modelAssignmentUserUuids('model_has_permissions', $companyUsers, $companyUserUuids);
        $groupUserUuids   = $this->groupMembershipsQuery($companyUuid)->whereIn('group_users.user_uuid', $userUuids)->pluck('group_users.user_uuid')->unique()->values();
        $total            = $userUuids->count();
        $assigned         = $roleUserUuids->merge($policyUserUuids)->merge($directUserUuids)->merge($groupUserUuids)->unique()->count();

        return response()->json([
            'total_users' => $total,
            'with_roles' => $roleUserUuids->count(),
            'with_groups' => $groupUserUuids->count(),
            'with_policies' => $policyUserUuids->count(),
            'with_direct_permissions' => $directUserUuids->count(),
            'without_assignments' => max(0, $total - $assigned),
            'coverage' => $this->percent($assigned, $total),
        ]);
    }

    public function privilegedAccess(Request $request): JsonResponse
    {
        $companyUuid = session('company');

        $privilegedRoles = Role::where(function ($query) use ($companyUuid) {
            $query->where('company_uuid', $companyUuid)->orWhereNull('company_uuid');
        })
            ->where(function ($query) {
                $query->where('name', 'like', '%admin%')->orWhere('name', 'like', '%full%');
            })
            ->withCount('permissions')
            ->limit(10)
            ->get(['id', 'name', 'company_uuid'])
            ->map(fn ($role) => [
                'id' => $role->id,
                'name' => $role->name,
                'type' => empty($role->company_uuid) ? 'Fleetbase Managed' : 'Organization Managed',
                'permissions_count' => $role->permissions_count,
            ]);

        $wildcardPolicies = Policy::where(function ($query) use ($companyUuid) {
            $query->where('company_uuid', $companyUuid)->orWhereNull('company_uuid');
        })
            ->whereHas('permissions', fn ($query) => $query->where('name', 'like', '%*%'))
            ->withCount('permissions')
            ->limit(10)
            ->get(['id', 'name', 'company_uuid', 'service'])
            ->map(fn ($policy) => [
                'id' => $policy->id,
                'name' => $policy->name,
                'service' => $policy->service,
                'type' => empty($policy->company_uuid) ? 'Fleetbase Managed' : 'Organization Managed',
                'permissions_count' => $policy->permissions_count,
            ]);

        return response()->json([
            'privileged_roles_count' => $privilegedRoles->count(),
            'wildcard_policies_count' => $wildcardPolicies->count(),
            'direct_privileged_grants' => $this->directPrivilegedGrantCount($companyUuid),
            'roles' => $privilegedRoles,
            'policies' => $wildcardPolicies,
        ]);
    }

    public function policySurface(Request $request): JsonResponse
    {
        $companyUuid = session('company');

        $byService = Policy::where(function ($query) use ($companyUuid) {
            $query->where('company_uuid', $companyUuid)->orWhereNull('company_uuid');
        })
            ->selectRaw('COALESCE(service, "core") as service, COUNT(*) as count')
            ->groupBy('service')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => ['label' => $row->service ?: 'core', 'value' => (int) $row->count]);

        return response()->json([
            'total' => $byService->sum('value'),
            'organization_managed' => Policy::where('company_uuid', $companyUuid)->count(),
            'fleetbase_managed' => Policy::whereNull('company_uuid')->count(),
            'by_service' => $byService,
        ]);
    }

    public function groupCoverage(Request $request): JsonResponse
    {
        $companyUuid = session('company');
        $groups      = Group::where('company_uuid', $companyUuid)->withCount('users')->get(['uuid', 'name']);

        return response()->json([
            'total_groups' => $groups->count(),
            'empty_groups' => $groups->where('users_count', 0)->count(),
            'total_memberships' => $this->groupMembershipsQuery($companyUuid)->count(),
            'buckets' => [
                ['label' => 'Empty', 'value' => $groups->where('users_count', 0)->count()],
                ['label' => '1-5 members', 'value' => $groups->filter(fn ($group) => $group->users_count >= 1 && $group->users_count <= 5)->count()],
                ['label' => '6-20 members', 'value' => $groups->filter(fn ($group) => $group->users_count >= 6 && $group->users_count <= 20)->count()],
                ['label' => '20+ members', 'value' => $groups->filter(fn ($group) => $group->users_count > 20)->count()],
            ],
            'largest_groups' => $groups->sortByDesc('users_count')->take(6)->values()->map(fn ($group) => [
                'name' => $group->name,
                'members' => $group->users_count,
            ]),
        ]);
    }

    public function userLifecycle(Request $request): JsonResponse
    {
        [$start, $end] = $this->period($request);
        $companyUuid   = session('company');
        $labels        = [];
        $created       = [];
        $pending       = [];
        $inactive      = [];
        $cursor        = $start->copy();

        while ($cursor->lte($end)) {
            $dayStart = $cursor->copy()->startOfDay();
            $dayEnd   = $cursor->copy()->endOfDay();
            $labels[] = $cursor->format('M j');
            $created[] = (clone $this->companyUsersQuery($companyUuid))->whereBetween('company_users.created_at', [$dayStart, $dayEnd])->count();
            $pending[] = (clone $this->companyUsersQuery($companyUuid))->where('company_users.status', 'pending')->whereBetween('company_users.created_at', [$dayStart, $dayEnd])->count();
            $inactive[] = (clone $this->companyUsersQuery($companyUuid))->where('company_users.status', 'inactive')->whereBetween('company_users.updated_at', [$dayStart, $dayEnd])->count();
            $cursor->addDay();
        }

        return response()->json([
            'labels' => $labels,
            'datasets' => [
                ['label' => 'Created', 'data' => $created],
                ['label' => 'Pending', 'data' => $pending],
                ['label' => 'Inactive', 'data' => $inactive],
            ],
        ]);
    }

    public function activity(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->input('limit', 12), 1), 25);
        $types = [
            User::class,
            CompanyUser::class,
            Group::class,
            Role::class,
            Policy::class,
            Permission::class,
        ];

        $items = Activity::whereIn('subject_type', $types)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($activity) => [
                'id' => $activity->id,
                'description' => $activity->description,
                'event' => $activity->event,
                'subject_type' => $activity->humanized_subject_type,
                'causer_name' => data_get($activity, 'causer.name'),
                'created_at' => optional($activity->created_at)->toISOString(),
            ]);

        return response()->json(['items' => $items]);
    }

    private function companyUsersQuery(string $companyUuid)
    {
        return CompanyUser::query()
            ->join('users', 'company_users.user_uuid', '=', 'users.uuid')
            ->where('company_users.company_uuid', $companyUuid)
            ->whereNull('company_users.deleted_at')
            ->whereNull('users.deleted_at');
    }

    private function dormantUsersQuery(string $companyUuid)
    {
        return $this->companyUsersQuery($companyUuid)
            ->where(function ($query) {
                $query->whereNull('users.last_login')->orWhere('users.last_login', '<', now()->subDays(self::DORMANT_DAYS));
            });
    }

    private function companyUserIds(string $companyUuid): Collection
    {
        return CompanyUser::where('company_uuid', $companyUuid)->whereNull('deleted_at')->pluck('uuid');
    }

    private function modelAssignmentUserUuids(string $table, Collection $companyUsers, Collection $modelUuids): Collection
    {
        if ($modelUuids->isEmpty()) {
            return collect();
        }

        $assignedCompanyUserUuids = DB::table($table)->whereIn('model_uuid', $modelUuids)->distinct()->pluck('model_uuid');

        return $companyUsers->whereIn('uuid', $assignedCompanyUserUuids)->pluck('user_uuid')->unique()->values();
    }

    private function groupMembershipsQuery(string $companyUuid)
    {
        return DB::table('group_users')
            ->join('groups', 'group_users.group_uuid', '=', 'groups.uuid')
            ->where('groups.company_uuid', $companyUuid)
            ->whereNull('group_users.deleted_at')
            ->whereNull('groups.deleted_at');
    }

    private function directPrivilegedGrantCount(string $companyUuid): int
    {
        $companyUserUuids = $this->companyUserIds($companyUuid);
        if ($companyUserUuids->isEmpty()) {
            return 0;
        }

        return DB::table('model_has_permissions')
            ->join('permissions', 'model_has_permissions.permission_id', '=', 'permissions.id')
            ->whereIn('model_has_permissions.model_uuid', $companyUserUuids)
            ->where(function ($query) {
                $query->where('permissions.name', 'like', '%*%')->orWhere('permissions.name', 'like', '%admin%');
            })
            ->distinct('model_has_permissions.model_uuid')
            ->count('model_has_permissions.model_uuid');
    }

    private function statusCounts(string $companyUuid): array
    {
        $counts = CompanyUser::where('company_uuid', $companyUuid)
            ->whereNull('deleted_at')
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return [
            'active' => (int) ($counts['active'] ?? 0),
            'pending' => (int) ($counts['pending'] ?? 0),
            'inactive' => (int) ($counts['inactive'] ?? 0),
        ];
    }

    private function mfaCoverage(string $companyUuid, int $totalUsers): array
    {
        $system       = Setting::where('key', 'system.2fa')->first();
        $company      = Setting::where('key', 'company.' . $companyUuid . '.2fa')->first();
        $userUuids    = CompanyUser::where('company_uuid', $companyUuid)->whereNull('deleted_at')->pluck('user_uuid');
        $enabledUsers = Setting::where('key', 'like', 'user.%.2fa')
            ->get(['key', 'value'])
            ->filter(function ($setting) use ($userUuids) {
                $uuid = str_replace(['user.', '.2fa'], '', $setting->key);

                return $userUuids->contains($uuid) && data_get($setting->value, 'enabled') === true;
            })
            ->count();

        $available = $enabledUsers > 0;

        return [
            'available' => $available,
            'enabled_users' => $enabledUsers,
            'total_users' => $totalUsers,
            'value' => $available ? $this->percent($enabledUsers, $totalUsers) : null,
            'format' => $available ? 'percent' : 'unavailable',
            'system_enabled' => (bool) data_get($system?->value, 'enabled', false),
            'system_enforced' => (bool) data_get($system?->value, 'enforced', false),
            'company_enabled' => (bool) data_get($company?->value, 'enabled', false),
            'company_enforced' => (bool) data_get($company?->value, 'enforced', false),
        ];
    }

    private function metric(string $label, mixed $value, string $format = 'count', bool $inverse = false, array $extra = []): array
    {
        return [
            'label' => $label,
            'value' => $value,
            'format' => $format,
            'inverse' => $inverse,
        ] + $extra;
    }

    private function percent(int|float|null $value, int|float|null $total): int
    {
        if (!$value || !$total) {
            return 0;
        }

        return (int) round(($value / $total) * 100);
    }

    private function period(Request $request): array
    {
        $days = match ($request->string('period')->toString()) {
            '7d' => 7,
            '90d' => 90,
            '180d' => 180,
            '365d' => 365,
            default => 30,
        };

        return [Carbon::now()->subDays($days - 1)->startOfDay(), Carbon::now()->endOfDay()];
    }
}
