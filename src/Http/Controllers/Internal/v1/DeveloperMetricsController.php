<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\ApiEvent;
use Fleetbase\Models\ApiRequestLog;
use Fleetbase\Models\WebhookEndpoint;
use Fleetbase\Models\WebhookRequestLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DeveloperMetricsController extends Controller
{
    public function kpis(Request $request): JsonResponse
    {
        [$start, $end, $previousStart, $previousEnd] = $this->periods($request);
        $companyUuid                                 = session('company');
        $apiTotal                                    = $this->apiRequests($companyUuid, $start, $end)->count();
        $apiErrors                                   = $this->apiErrors($companyUuid, $start, $end)->count();
        $previousApiTotal                            = $this->apiRequests($companyUuid, $previousStart, $previousEnd)->count();
        $avgLatency                                  = (float) $this->apiRequests($companyUuid, $start, $end)->avg('duration');
        $previousLatency                             = (float) $this->apiRequests($companyUuid, $previousStart, $previousEnd)->avg('duration');
        $webhookTotal                                = $this->webhookRequests($companyUuid, $start, $end)->count();
        $webhookSuccess                              = $this->webhookSuccesses($companyUuid, $start, $end)->count();
        $previousWebhookTotal                        = $this->webhookRequests($companyUuid, $previousStart, $previousEnd)->count();
        $previousWebhookSuccess                      = $this->webhookSuccesses($companyUuid, $previousStart, $previousEnd)->count();
        $webhookFailures                             = max(0, $webhookTotal - $webhookSuccess);
        $previousWebhookFailures                     = max(0, $previousWebhookTotal - $previousWebhookSuccess);
        $eventsTotal                                 = ApiEvent::where('company_uuid', $companyUuid)->whereBetween('created_at', [$start, $end])->count();
        $previousEventsTotal                         = ApiEvent::where('company_uuid', $companyUuid)->whereBetween('created_at', [$previousStart, $previousEnd])->count();
        $currentApiErrorRate                         = $this->percent($apiErrors, $apiTotal);
        $previousApiErrorRate                        = $this->percent($this->apiErrors($companyUuid, $previousStart, $previousEnd)->count(), $previousApiTotal);
        $currentWebhookSuccessRate                   = $this->percent($webhookSuccess, $webhookTotal);
        $previousWebhookSuccessRate                  = $this->percent($previousWebhookSuccess, $previousWebhookTotal);

        return response()->json([
            'period'  => $this->periodPayload($start, $end),
            'metrics' => [
                'api_requests' => $this->metric('API Requests', $apiTotal, 'count', false, $this->deltaPercent($apiTotal, $previousApiTotal)),
                'api_error_rate' => $this->metric('API Error Rate', $currentApiErrorRate, 'percent', true, $this->deltaPercent($currentApiErrorRate, $previousApiErrorRate)),
                'avg_api_latency' => $this->metric('Avg API Latency', $this->milliseconds($avgLatency), 'duration', true, $this->deltaPercent($avgLatency, $previousLatency)),
                'webhook_success_rate' => $this->metric('Webhook Success Rate', $currentWebhookSuccessRate, 'percent', false, $this->deltaPercent($currentWebhookSuccessRate, $previousWebhookSuccessRate)),
                'active_api_keys' => $this->metric('Active API Keys', ApiCredential::where('company_uuid', $companyUuid)->whereNull('deleted_at')->count()),
                'active_webhooks' => $this->metric('Active Webhooks', WebhookEndpoint::where('company_uuid', $companyUuid)->whereNull('deleted_at')->where('status', 'enabled')->count()),
                'webhook_failures' => $this->metric('Webhook Failures', $webhookFailures, 'count', true, $this->deltaPercent($webhookFailures, $previousWebhookFailures)),
                'events_emitted' => $this->metric('Events Emitted', $eventsTotal, 'count', false, $this->deltaPercent($eventsTotal, $previousEventsTotal)),
            ],
        ]);
    }

    public function apiTraffic(Request $request): JsonResponse
    {
        [$start, $end] = $this->periods($request);
        $companyUuid   = session('company');
        $labels        = $this->labels($start, $end);
        $requests      = $this->dailyCounts($this->apiRequests($companyUuid, $start, $end), $labels);
        $errors        = $this->dailyCounts($this->apiErrors($companyUuid, $start, $end), $labels);

        return response()->json([
            'period'   => $this->periodPayload($start, $end),
            'labels'   => array_keys($labels),
            'datasets' => [
                ['label' => 'Requests', 'data' => $requests],
                ['label' => 'Success', 'data' => array_map(fn ($total, $failed) => max(0, $total - $failed), $requests, $errors)],
                ['label' => 'Errors', 'data' => $errors],
            ],
            'methods' => $this->apiRequests($companyUuid, $start, $end)
                ->selectRaw('method, COUNT(*) as count')
                ->groupBy('method')
                ->orderByDesc('count')
                ->limit(8)
                ->get()
                ->map(fn ($row) => ['label' => $row->method ?: 'UNKNOWN', 'value' => (int) $row->count]),
        ]);
    }

    public function webhookDelivery(Request $request): JsonResponse
    {
        [$start, $end] = $this->periods($request);
        $companyUuid   = session('company');
        $labels        = $this->labels($start, $end);
        $sent          = $this->dailyCounts($this->webhookRequests($companyUuid, $start, $end), $labels);
        $succeeded     = $this->dailyCounts($this->webhookSuccesses($companyUuid, $start, $end), $labels);
        $failed        = array_map(fn ($total, $ok) => max(0, $total - $ok), $sent, $succeeded);

        return response()->json([
            'period'  => $this->periodPayload($start, $end),
            'summary' => [
                'sent'                => array_sum($sent),
                'succeeded'           => array_sum($succeeded),
                'failed'              => array_sum($failed),
                'success_rate'        => $this->percent(array_sum($succeeded), array_sum($sent)),
                'average_attempts'    => round((float) $this->webhookRequests($companyUuid, $start, $end)->avg('attempt'), 2),
                'average_duration_ms' => $this->milliseconds((float) $this->webhookRequests($companyUuid, $start, $end)->avg('duration')),
            ],
            'labels' => array_keys($labels),
            'datasets' => [
                ['label' => 'Sent', 'data' => $sent],
                ['label' => 'Succeeded', 'data' => $succeeded],
                ['label' => 'Failed', 'data' => $failed],
            ],
        ]);
    }

    public function credentials(): JsonResponse
    {
        $credentials = ApiCredential::where('company_uuid', session('company'))->whereNull('deleted_at')->get(['uuid', 'name', 'key', 'test_mode', 'last_used_at', 'expires_at']);
        $now         = Carbon::now();

        return response()->json([
            'summary' => [
                'total'         => $credentials->count(),
                'live'          => $credentials->where('test_mode', false)->count(),
                'test'          => $credentials->where('test_mode', true)->count(),
                'recently_used' => $credentials->filter(fn ($credential) => $credential->last_used_at && Carbon::parse($credential->last_used_at)->gte($now->copy()->subDays(30)))->count(),
                'expiring_soon' => $credentials->filter(fn ($credential) => $credential->expires_at && Carbon::parse($credential->expires_at)->between($now, $now->copy()->addDays(30)))->count(),
            ],
            'items' => $credentials->sortByDesc('last_used_at')->take(8)->values()->map(fn ($credential) => [
                'id'           => $credential->uuid,
                'name'         => $credential->name ?: $credential->key,
                'environment'  => $credential->test_mode ? 'Test' : 'Live',
                'last_used_at' => optional($credential->last_used_at)->toISOString(),
                'expires_at'   => optional($credential->expires_at)->toISOString(),
            ]),
        ]);
    }

    public function events(Request $request): JsonResponse
    {
        [$start, $end] = $this->periods($request);
        $companyUuid   = session('company');
        $events        = ApiEvent::where('company_uuid', $companyUuid)->whereBetween('created_at', [$start, $end]);

        return response()->json([
            'period' => $this->periodPayload($start, $end),
            'total'  => (clone $events)->count(),
            'types'  => (clone $events)->selectRaw('event, COUNT(*) as count')->groupBy('event')->orderByDesc('count')->limit(10)->get()->map(fn ($row) => ['label' => $row->event ?: 'unknown', 'value' => (int) $row->count]),
            'sources' => (clone $events)->selectRaw('source, COUNT(*) as count')->groupBy('source')->orderByDesc('count')->limit(6)->get()->map(fn ($row) => ['label' => $row->source ?: 'unknown', 'value' => (int) $row->count]),
        ]);
    }

    public function endpointHealth(Request $request): JsonResponse
    {
        [$start, $end] = $this->periods($request);
        $companyUuid   = session('company');
        $stats         = WebhookRequestLog::where('company_uuid', $companyUuid)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('webhook_uuid, COUNT(*) as total, SUM(CASE WHEN CAST(status_code AS UNSIGNED) BETWEEN 200 AND 299 THEN 1 ELSE 0 END) as succeeded, AVG(duration) as duration, MAX(created_at) as last_delivery_at')
            ->groupBy('webhook_uuid')
            ->get()
            ->keyBy('webhook_uuid');

        return response()->json([
            'items' => WebhookEndpoint::where('company_uuid', $companyUuid)->whereNull('deleted_at')->orderByDesc('updated_at')->limit(20)->get(['uuid', 'url', 'status', 'mode'])->map(function ($endpoint) use ($stats) {
                $row       = $stats->get($endpoint->uuid);
                $total     = (int) ($row->total ?? 0);
                $succeeded = (int) ($row->succeeded ?? 0);

                return [
                    'id'                  => $endpoint->uuid,
                    'url'                 => $endpoint->url,
                    'status'              => $endpoint->status,
                    'mode'                => $endpoint->mode,
                    'success_rate'        => $this->percent($succeeded, $total),
                    'deliveries'          => $total,
                    'failures'            => max(0, $total - $succeeded),
                    'average_duration_ms' => $this->milliseconds((float) ($row->duration ?? 0)),
                    'last_delivery_at'    => $row?->last_delivery_at ? Carbon::parse($row->last_delivery_at)->toISOString() : null,
                ];
            }),
        ]);
    }

    public function activity(Request $request): JsonResponse
    {
        $limit       = min(max((int) $request->input('limit', 12), 1), 25);
        $companyUuid = session('company');
        $items       = collect();

        ApiRequestLog::where('company_uuid', $companyUuid)->orderByDesc('created_at')->limit($limit)->get(['public_id', 'method', 'path', 'status_code', 'duration', 'created_at'])->each(function ($log) use ($items) {
            $items->push(['id' => $log->public_id, 'type' => 'api_request', 'label' => trim(($log->method ?: 'API') . ' /' . ltrim($log->path ?? '', '/')), 'status' => $log->status_code, 'duration_ms' => $this->milliseconds((float) $log->duration), 'created_at' => optional($log->created_at)->toISOString()]);
        });

        WebhookRequestLog::where('company_uuid', $companyUuid)->orderByDesc('created_at')->limit($limit)->get(['public_id', 'url', 'status_code', 'duration', 'created_at'])->each(function ($log) use ($items) {
            $items->push(['id' => $log->public_id, 'type' => 'webhook', 'label' => $log->url, 'status' => $log->status_code, 'duration_ms' => $this->milliseconds((float) $log->duration), 'created_at' => optional($log->created_at)->toISOString()]);
        });

        ApiEvent::where('company_uuid', $companyUuid)->orderByDesc('created_at')->limit($limit)->get(['public_id', 'event', 'description', 'created_at'])->each(function ($event) use ($items) {
            $items->push(['id' => $event->public_id, 'type' => 'event', 'label' => $event->description ?: $event->event, 'status' => $event->event, 'created_at' => optional($event->created_at)->toISOString()]);
        });

        return response()->json(['items' => $items->sortByDesc('created_at')->take($limit)->values()]);
    }

    private function apiRequests(?string $companyUuid, Carbon $start, Carbon $end)
    {
        return ApiRequestLog::where('company_uuid', $companyUuid)->whereBetween('created_at', [$start, $end]);
    }

    private function apiErrors(?string $companyUuid, Carbon $start, Carbon $end)
    {
        return $this->apiRequests($companyUuid, $start, $end)->whereRaw('CAST(status_code AS UNSIGNED) >= 400');
    }

    private function webhookRequests(?string $companyUuid, Carbon $start, Carbon $end)
    {
        return WebhookRequestLog::where('company_uuid', $companyUuid)->whereBetween('created_at', [$start, $end]);
    }

    private function webhookSuccesses(?string $companyUuid, Carbon $start, Carbon $end)
    {
        return $this->webhookRequests($companyUuid, $start, $end)->whereRaw('CAST(status_code AS UNSIGNED) BETWEEN 200 AND 299');
    }

    private function periods(Request $request): array
    {
        $days = match ((string) $request->input('period', '30d')) {
            '7d' => 7,
            '90d' => 90,
            '180d' => 180,
            '365d' => 365,
            default => 30,
        };
        $end           = Carbon::now()->endOfDay();
        $start         = $end->copy()->subDays($days - 1)->startOfDay();
        $previousEnd   = $start->copy()->subSecond();
        $previousStart = $previousEnd->copy()->subDays($days - 1)->startOfDay();

        return [$start, $end, $previousStart, $previousEnd];
    }

    private function labels(Carbon $start, Carbon $end): array
    {
        $labels = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $labels[$cursor->format('M j')] = $cursor->toDateString();
            $cursor->addDay();
        }

        return $labels;
    }

    private function dailyCounts($query, array $labels): array
    {
        $counts = $query->selectRaw('DATE(created_at) as day, COUNT(*) as count')->groupBy('day')->pluck('count', 'day');

        return collect($labels)->map(fn ($date) => (int) ($counts[$date] ?? 0))->values()->all();
    }

    private function metric(string $label, mixed $value, string $format = 'count', bool $inverse = false, ?float $delta = null): array
    {
        return ['label' => $label, 'value' => $value, 'format' => $format, 'inverse' => $inverse, 'delta_percent' => $delta];
    }

    private function periodPayload(Carbon $start, Carbon $end): array
    {
        return ['start' => $start->toISOString(), 'end' => $end->toISOString()];
    }

    private function percent(int|float|null $value, int|float|null $total): int
    {
        return !$value || !$total ? 0 : (int) round(($value / $total) * 100);
    }

    private function deltaPercent(int|float|null $current, int|float|null $previous): ?float
    {
        if ($previous === null || (float) $previous === 0.0) {
            return null;
        }

        return round(((float) $current - (float) $previous) / abs((float) $previous) * 100, 1);
    }

    private function milliseconds(float $duration): int
    {
        return $duration <= 0 ? 0 : (int) round($duration * 1000);
    }
}
