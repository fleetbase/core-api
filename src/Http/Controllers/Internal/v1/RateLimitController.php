<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Requests\AdminRequest;
use Fleetbase\Models\Company;
use Fleetbase\Support\ApiConsumerMetrics;
use Fleetbase\Support\ApiRateLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

/**
 * System administration of API rate limiting: the limits themselves, per-organization
 * overrides, and visibility into which consumers drive traffic or are being throttled.
 */
class RateLimitController extends Controller
{
    /**
     * The effective settings, the environment defaults beneath them, and the overrides
     * with their organizations resolved for display.
     */
    public function getSettings(AdminRequest $request): JsonResponse
    {
        return response()->json($this->settingsPayload(ApiRateLimits::settings()));
    }

    /**
     * Save the administrator's settings; they apply from the next request.
     */
    public function saveSettings(AdminRequest $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled'                   => ['required', 'boolean'],
            'max_attempts'              => ['required', 'integer', 'min:1', 'max:1000000'],
            'decay_minutes'             => ['required', 'integer', 'min:1', 'max:1440'],
            'track_consumers'           => ['sometimes', 'boolean'],
            'overrides'                 => ['sometimes', 'array'],
            'overrides.*.company_uuid'  => ['required', 'string', 'distinct', Rule::exists('companies', 'uuid')],
            'overrides.*.unlimited'     => ['sometimes', 'boolean'],
            'overrides.*.max_attempts'  => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'overrides.*.note'          => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($this->settingsPayload(ApiRateLimits::store($validated)));
    }

    /**
     * Discard the administrator's settings and fall back to the environment.
     */
    public function resetSettings(AdminRequest $request): JsonResponse
    {
        return response()->json($this->settingsPayload(ApiRateLimits::reset()));
    }

    /**
     * The busiest (or most throttled) API consumers over a recent window.
     */
    public function consumers(AdminRequest $request): JsonResponse
    {
        $request->validate([
            'window' => ['sometimes', 'integer', Rule::in(ApiConsumerMetrics::WINDOWS)],
            'sort'   => ['sometimes', Rule::in(['hits', 'throttled'])],
            'limit'  => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $settings = ApiRateLimits::settings();
        $metrics  = ApiConsumerMetrics::top(
            (int) $request->input('window', 15),
            (string) $request->input('sort', 'hits'),
            (int) $request->input('limit', 50)
        );

        $metrics['tracking']      = $settings['track_consumers'];
        $metrics['default_limit'] = $settings['max_attempts'];
        $metrics['decay_minutes'] = $settings['decay_minutes'];

        return response()->json($metrics);
    }

    /**
     * Clear a consumer's current limiter window so it can send requests again at once.
     */
    public function resetConsumer(AdminRequest $request, string $signature): JsonResponse
    {
        if (!preg_match('/^[a-f0-9]{40}$/', $signature)) {
            return response()->error('Invalid consumer.', 422);
        }

        RateLimiter::clear($signature);

        return response()->json(['status' => 'OK']);
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
            'settings'       => $settings,
            'defaults'       => ApiRateLimits::defaults(),
            'unlimited_keys' => count(config('api.throttle.unlimited_keys', [])),
        ];
    }
}
