<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Requests\AdminRequest;
use Fleetbase\Models\Company;
use Fleetbase\Models\SmsSend;
use Fleetbase\Support\Sms\SmsBreaker;
use Fleetbase\Support\Sms\SmsGuard;
use Fleetbase\Support\Sms\SmsLedger;
use Fleetbase\Support\Sms\SmsPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * System administration of the SMS guard: the policy (mode, budgets, blocklists, alert
 * recipients, per-organization exceptions), the pauses in force, and what the ledger
 * shows about who is sending.
 */
class SmsGuardController extends Controller
{
    /**
     * The effective policy, the environment defaults beneath it, and the current pauses.
     */
    public function getSettings(AdminRequest $request): JsonResponse
    {
        return response()->json($this->settingsPayload(SmsPolicy::settings()));
    }

    /**
     * Save the administrator's policy; it applies within a minute on every worker.
     */
    public function saveSettings(AdminRequest $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled'                                   => ['required', 'boolean'],
            'mode'                                      => ['required', Rule::in([SmsPolicy::MODE_ENFORCE, SmsPolicy::MODE_MONITOR])],
            'alert_emails'                              => ['sometimes', 'array'],
            'alert_emails.*'                            => ['email'],
            'validation'                                => ['sometimes', 'array'],
            'validation.enabled'                        => ['sometimes', 'boolean'],
            'validation.allowed_types'                  => ['sometimes', 'array'],
            'validation.allowed_types.*'                => ['string', 'max:32'],
            'validation.blocked_countries'              => ['sometimes', 'array'],
            'validation.blocked_countries.*'            => ['string', 'size:2'],
            'validation.blocked_prefixes'               => ['sometimes', 'array'],
            'validation.blocked_prefixes.*'             => ['string', 'max:8'],
            'limits'                                    => ['sometimes', 'array'],
            'limits.destination.cooldown_seconds'       => ['nullable', 'integer', 'min:0', 'max:86400'],
            'limits.destination.per_hour'               => ['nullable', 'integer', 'min:0', 'max:100000'],
            'limits.destination.per_day'                => ['nullable', 'integer', 'min:0', 'max:100000'],
            'limits.ip.per_15_minutes'                  => ['nullable', 'integer', 'min:0', 'max:100000'],
            'limits.ip.per_day'                         => ['nullable', 'integer', 'min:0', 'max:100000'],
            'limits.company.per_minute'                 => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'limits.company.per_hour'                   => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'limits.company.per_day'                    => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'limits.tenant.per_minute'                  => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'limits.tenant.per_hour'                    => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'limits.tenant.per_day'                     => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'limits.probation.days'                     => ['nullable', 'integer', 'min:0', 'max:365'],
            'limits.probation.per_day'                  => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'limits.platform.public.per_minute'         => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'limits.platform.public.per_hour'           => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'limits.platform.public.per_day'            => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'limits.platform.trusted.per_minute'        => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'limits.platform.trusted.per_hour'          => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'limits.platform.trusted.per_day'           => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'breaker'                                   => ['sometimes', 'array'],
            'breaker.pause_minutes'                     => ['nullable', 'integer', 'min:1', 'max:10080'],
            'breaker.alert_percentages'                 => ['sometimes', 'array'],
            'breaker.alert_percentages.*'               => ['integer', 'min:1', 'max:99'],
            'breaker.distinct_destinations_per_10m'     => ['nullable', 'integer', 'min:0', 'max:100000'],
            'breaker.auto_pauses_before_manual'         => ['nullable', 'integer', 'min:1', 'max:100'],
            'breaker.new_country_min_sends'             => ['nullable', 'integer', 'min:0', 'max:100000'],
            'breaker.new_country_min_history'           => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'breaker.conversion_min_issued'             => ['nullable', 'integer', 'min:0', 'max:100000'],
            'breaker.conversion_min_percent'            => ['nullable', 'integer', 'min:0', 'max:100'],
            'overrides'                                 => ['sometimes', 'array'],
            'overrides.*.company_uuid'                  => ['required', 'string', 'distinct', Rule::exists('companies', 'uuid')],
            'overrides.*.unlimited'                     => ['sometimes', 'boolean'],
            'overrides.*.per_minute'                    => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'overrides.*.per_hour'                      => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'overrides.*.per_day'                       => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'overrides.*.allowed_countries'             => ['sometimes', 'array'],
            'overrides.*.allowed_countries.*'           => ['string', 'size:2'],
            'overrides.*.note'                          => ['nullable', 'string', 'max:255'],
        ]);

        // Lists replace, maps merge: an override removed from the list is gone, while the
        // pauses and anything not on the page survive the save.
        $validated['overrides']    = array_values($validated['overrides'] ?? []);
        $validated['alert_emails'] = array_values($validated['alert_emails'] ?? []);

        return response()->json($this->settingsPayload(SmsPolicy::update($validated)));
    }

    /**
     * Discard the administrator's policy and pauses, back to the environment defaults.
     */
    public function resetSettings(AdminRequest $request): JsonResponse
    {
        return response()->json($this->settingsPayload(SmsPolicy::reset()));
    }

    /**
     * The pauses in force.
     */
    public function pauses(AdminRequest $request): JsonResponse
    {
        return response()->json(['pauses' => $this->pausesPayload()]);
    }

    /**
     * Pause a company, a store or network, or the platform.
     */
    public function pause(AdminRequest $request): JsonResponse
    {
        $validated = $request->validate([
            'scope'   => ['required', 'string', 'max:120'],
            'minutes' => ['nullable', 'integer', 'min:1', 'max:525600'],
            'reason'  => ['nullable', 'string', 'max:255'],
        ]);

        $scope = SmsBreaker::resolveScope($validated['scope']);
        if ($scope === null) {
            return response()->error('No organization matches that scope.', 422);
        }

        SmsGuard::make()->breaker()->pause(
            $scope,
            isset($validated['minutes']) ? (int) $validated['minutes'] : null,
            (string) ($validated['reason'] ?? 'manual'),
            false,
            SmsBreaker::companyUuidFromScope($scope)
        );

        return response()->json(['pauses' => $this->pausesPayload()]);
    }

    /**
     * Lift a pause.
     */
    public function unpause(AdminRequest $request, string $scope): JsonResponse
    {
        $resolved = SmsBreaker::resolveScope(urldecode($scope));
        if ($resolved === null) {
            return response()->error('No organization matches that scope.', 422);
        }

        SmsGuard::make()->breaker()->unpause($resolved);

        return response()->json(['pauses' => $this->pausesPayload()]);
    }

    /**
     * Sends per organization over a recent window, against their budgets.
     */
    public function usage(AdminRequest $request): JsonResponse
    {
        $request->validate([
            'hours' => ['sometimes', 'integer', 'min:1', 'max:720'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $hours    = (int) $request->input('hours', 24);
        $limit    = (int) $request->input('limit', 50);
        $settings = SmsPolicy::settings();
        $since    = Carbon::now()->subHours($hours);

        $rows = SmsSend::query()
            ->selectRaw('company_uuid, '
                . 'sum(case when status = ? then 1 else 0 end) as sent, '
                . 'sum(case when status = ? then 1 else 0 end) as failed, '
                . 'sum(case when status = ? then 1 else 0 end) as blocked, '
                . 'count(distinct destination_hash) as destinations, '
                . 'max(created_at) as last_send_at', [SmsSend::STATUS_SENT, SmsSend::STATUS_FAILED, SmsSend::STATUS_BLOCKED])
            ->where('created_at', '>=', $since)
            ->whereNotNull('company_uuid')
            ->groupBy('company_uuid')
            ->orderByDesc('sent')
            ->limit($limit)
            ->get();

        $companies = Company::whereIn('uuid', $rows->pluck('company_uuid')->all())->get(['uuid', 'public_id', 'name'])->keyBy('uuid');

        $usage = $rows->map(function ($row) use ($companies, $settings) {
            $company  = $companies->get($row->company_uuid);
            $override = SmsPolicy::overrideFor($row->company_uuid, $settings);
            $budget   = $override && $override['unlimited'] ? null : ($override['per_day'] ?? $settings['limits']['company']['per_day']);

            return [
                'company_uuid' => $row->company_uuid,
                'company_id'   => $company?->public_id,
                'company_name' => $company?->name,
                'sent'         => (int) $row->sent,
                'failed'       => (int) $row->failed,
                'blocked'      => (int) $row->blocked,
                'destinations' => (int) $row->destinations,
                'last_send_at' => $row->last_send_at,
                'daily_budget' => $budget,
                'paused'       => SmsGuard::make()->breaker()->pausedUntil('company:' . $row->company_uuid) !== null,
            ];
        })->values();

        return response()->json([
            'hours'            => $hours,
            'ledger_available' => SmsLedger::available(),
            'usage'            => $usage,
        ]);
    }

    /**
     * Recent ledger rows, newest first, filtered by organization, status, purpose or number.
     */
    public function ledger(AdminRequest $request): JsonResponse
    {
        $request->validate([
            'company_uuid' => ['sometimes', 'string', 'max:36'],
            'status'       => ['sometimes', Rule::in([SmsSend::STATUS_RESERVED, SmsSend::STATUS_SENT, SmsSend::STATUS_FAILED, SmsSend::STATUS_BLOCKED])],
            'purpose'      => ['sometimes', 'string', 'max:64'],
            'destination'  => ['sometimes', 'string', 'max:32'],
            'hours'        => ['sometimes', 'integer', 'min:1', 'max:2160'],
            'limit'        => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);

        $query = SmsSend::query()
            ->where('created_at', '>=', Carbon::now()->subHours((int) $request->input('hours', 24)))
            ->orderByDesc('created_at')
            ->limit((int) $request->input('limit', 100));

        if ($request->filled('company_uuid')) {
            $query->where('company_uuid', $request->input('company_uuid'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('purpose')) {
            $query->where('purpose', $request->input('purpose'));
        }
        if ($request->filled('destination')) {
            $query->where('destination_hash', SmsLedger::hash((string) $request->input('destination')));
        }

        return response()->json([
            'ledger_available' => SmsLedger::available(),
            'sends'            => $query->get(),
        ]);
    }

    protected function settingsPayload(array $settings): array
    {
        $companies = Company::whereIn('uuid', array_column($settings['overrides'], 'company_uuid'))
            ->get(['uuid', 'public_id', 'name'])
            ->keyBy('uuid');

        $settings['overrides'] = array_map(function (array $override) use ($companies) {
            $company = $companies->get($override['company_uuid']);

            return array_merge($override, [
                'company_id'   => $company?->public_id,
                'company_name' => $company?->name,
            ]);
        }, $settings['overrides']);

        return [
            'settings'         => $settings,
            'defaults'         => SmsPolicy::defaults(),
            'pauses'           => $this->pausesPayload(),
            'ledger_available' => SmsLedger::available(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function pausesPayload(): array
    {
        $pauses = collect(SmsGuard::make()->breaker()->pauses())->map(function (array $pause, string $scope) {
            $pause['company_uuid'] = $pause['company_uuid'] ?: SmsBreaker::companyUuidFromScope($scope);

            return $pause;
        });
        $uuids = $pauses->pluck('company_uuid')->filter()->unique()->values()->all();
        $names = $uuids ? Company::whereIn('uuid', $uuids)->get(['uuid', 'public_id', 'name'])->keyBy('uuid') : collect();

        return $pauses->map(function (array $pause, string $scope) use ($names) {
            $company = $pause['company_uuid'] ? $names->get($pause['company_uuid']) : null;

            return array_merge($pause, [
                'scope'        => $scope,
                'platform'     => $scope === SmsBreaker::SCOPE_PLATFORM,
                'company_id'   => $company?->public_id,
                'company_name' => $company?->name,
                'until_iso'    => $pause['until'] ? Carbon::createFromTimestamp($pause['until'])->toIso8601String() : null,
            ]);
        })->values()->all();
    }
}
