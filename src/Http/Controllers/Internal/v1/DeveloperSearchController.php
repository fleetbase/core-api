<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\ApiEvent;
use Fleetbase\Models\ApiRequestLog;
use Fleetbase\Models\WebhookEndpoint;
use Fleetbase\Support\Auth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DeveloperSearchController extends Controller
{
    private const SEARCH_TYPES = ['api_keys', 'webhooks', 'logs', 'events'];

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
            'api_keys' => 'developers see api-key',
            'webhooks' => 'developers see webhook',
            'logs'     => 'developers see log',
            'events'   => 'developers see event',
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
            'api_keys' => $this->searchApiKeys($query, $limit),
            'webhooks' => $this->searchWebhooks($query, $limit),
            'logs'     => $this->searchLogs($query, $limit),
            'events'   => $this->searchEvents($query, $limit),
            default    => collect(),
        };
    }

    private function searchApiKeys(string $query, int $limit): Collection
    {
        return ApiCredential::where('company_uuid', session('company'))
            ->where(function (Builder $builder) use ($query) {
                $this->whereLike($builder, ['name', 'key', 'uuid'], $query);
            })
            ->limit($limit)
            ->get(['uuid', 'name', 'key', 'test_mode'])
            ->map(fn (ApiCredential $apiKey) => [
                'label'       => $apiKey->name ?: $apiKey->key,
                'description' => trim(($apiKey->test_mode ? 'Test' : 'Live') . ' API key' . ($apiKey->key ? ' - ' . $apiKey->key : '')),
                'icon'        => 'key',
                'type'        => 'API Key',
                'route'       => 'console.developers.api-keys.index',
                'breadcrumb'  => 'Developers > API Keys',
                'queryParams' => [
                    'query'        => $query,
                    'view_api_key' => $apiKey->uuid,
                ],
            ]);
    }

    private function searchWebhooks(string $query, int $limit): Collection
    {
        return WebhookEndpoint::where('company_uuid', session('company'))
            ->where(function (Builder $builder) use ($query) {
                $this->whereLike($builder, ['url', 'description', 'uuid', 'status', 'mode', 'version'], $query);
            })
            ->limit($limit)
            ->get(['uuid', 'url', 'description', 'status', 'mode', 'version'])
            ->map(fn (WebhookEndpoint $webhook) => [
                'label'       => $webhook->url,
                'description' => $webhook->description ?: trim(implode(' ', array_filter([$webhook->mode, $webhook->status, $webhook->version]))),
                'icon'        => 'globe-asia',
                'type'        => 'Webhook',
                'route'       => 'console.developers.webhooks.view',
                'models'      => [$webhook->uuid],
                'breadcrumb'  => 'Developers > Webhooks',
            ]);
    }

    private function searchLogs(string $query, int $limit): Collection
    {
        return ApiRequestLog::where('company_uuid', session('company'))
            ->where(function (Builder $builder) use ($query) {
                $this->whereLike($builder, ['public_id', 'method', 'path', 'full_url', 'status_code', 'reason_phrase', 'ip_address', 'version', 'source'], $query);
                $builder->orWhereHas('apiCredential', function (Builder $apiCredentialQuery) use ($query) {
                    $this->whereLike($apiCredentialQuery, ['name', 'key', 'uuid', '_key'], $query);
                });
            })
            ->limit($limit)
            ->get(['public_id', 'method', 'path', 'full_url', 'status_code', 'reason_phrase'])
            ->map(fn (ApiRequestLog $log) => [
                'label'       => $log->public_id ?: trim($log->method . ' /' . $log->path),
                'description' => trim(implode(' ', array_filter([$log->method, $log->path ? '/' . $log->path : null, $log->status_code, $log->reason_phrase]))),
                'icon'        => 'file-lines',
                'type'        => 'Request Log',
                'route'       => 'console.developers.logs.view',
                'models'      => [$log->public_id],
                'breadcrumb'  => 'Developers > Logs',
            ]);
    }

    private function searchEvents(string $query, int $limit): Collection
    {
        return ApiEvent::where('company_uuid', session('company'))
            ->where(function (Builder $builder) use ($query) {
                $this->whereLike($builder, ['public_id', 'event', 'source', 'description', 'method'], $query);
            })
            ->limit($limit)
            ->get(['public_id', 'event', 'source', 'description', 'method'])
            ->map(fn (ApiEvent $event) => [
                'label'       => $event->event ?: $event->public_id,
                'description' => $event->description ?: trim(implode(' ', array_filter([$event->source, $event->method]))),
                'icon'        => 'calendar-day',
                'type'        => 'Event',
                'route'       => 'console.developers.events.view',
                'models'      => [$event->public_id],
                'breadcrumb'  => 'Developers > Events',
            ]);
    }

    private function whereLike(Builder $builder, array $columns, string $query): void
    {
        $like = '%' . Str::replace(['%', '_'], ['\\%', '\\_'], $query) . '%';

        foreach ($columns as $index => $column) {
            $method = $index === 0 ? 'where' : 'orWhere';
            $builder->{$method}($column, 'like', $like);
        }
    }
}
