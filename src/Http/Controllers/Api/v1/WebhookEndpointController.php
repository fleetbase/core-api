<?php

namespace Fleetbase\Http\Controllers\Api\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Requests\Api\WebhookEndpointRequest;
use Fleetbase\Models\WebhookEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhook endpoints managed with an API key, so integrations (e-commerce connectors and the like)
 * can register where Fleetbase sends events without a console session. An endpoint belongs to the
 * key's company and environment (live or test). Its signing secret is returned once, on creation
 * and on rotation; deliveries carry `X-Fleetbase-Signature` signed with it.
 */
class WebhookEndpointController extends Controller
{
    public function create(WebhookEndpointRequest $request): JsonResponse
    {
        $endpoint = WebhookEndpoint::create([
            'company_uuid'        => session('company'),
            'api_credential_uuid' => session('api_credential'),
            'url'                 => $request->input('url'),
            'events'              => $request->input('events') ?: [],
            'description'         => $request->input('description'),
            'version'             => $request->input('version') ?: $this->defaultVersion(),
            'mode'                => $this->environment(),
            'status'              => $request->input('status', 'enabled'),
        ]);

        return response()->json($this->present($endpoint, true), 201);
    }

    public function query(Request $request): JsonResponse
    {
        $limit = max(1, min((int) $request->input('limit', 30), 100));

        $endpoints = $this->scoped()
            ->when($request->filled('url'), fn ($query) => $query->where('url', $request->input('url')))
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return response()->json($endpoints->map(fn (WebhookEndpoint $endpoint) => $this->present($endpoint))->values());
    }

    public function find(string $id): JsonResponse
    {
        $endpoint = $this->scoped()->where('uuid', $id)->first();
        if (!$endpoint) {
            return response()->json(['error' => 'Webhook endpoint not found.'], 404);
        }

        return response()->json($this->present($endpoint));
    }

    public function update(string $id, WebhookEndpointRequest $request): JsonResponse
    {
        $endpoint = $this->scoped()->where('uuid', $id)->first();
        if (!$endpoint) {
            return response()->json(['error' => 'Webhook endpoint not found.'], 404);
        }

        $endpoint->fill($request->only(['url', 'events', 'description', 'version', 'status']));
        $endpoint->save();

        return response()->json($this->present($endpoint));
    }

    public function rotateSecret(string $id): JsonResponse
    {
        $endpoint = $this->scoped()->where('uuid', $id)->first();
        if (!$endpoint) {
            return response()->json(['error' => 'Webhook endpoint not found.'], 404);
        }

        $endpoint->rotateSecret();

        return response()->json($this->present($endpoint, true));
    }

    public function delete(string $id): JsonResponse
    {
        $endpoint = $this->scoped()->where('uuid', $id)->first();
        if (!$endpoint) {
            return response()->json(['error' => 'Webhook endpoint not found.'], 404);
        }

        $endpoint->delete();

        return response()->json(['id' => $id, 'deleted' => true]);
    }

    /**
     * Endpoints of the key's company in the key's environment.
     *
     * @return \Illuminate\Database\Eloquent\Builder<WebhookEndpoint>
     */
    protected function scoped()
    {
        return WebhookEndpoint::query()
            ->where('company_uuid', session('company'))
            ->where('mode', $this->environment());
    }

    protected function environment(): string
    {
        return session('api_environment') === 'test' ? 'test' : 'live';
    }

    protected function defaultVersion(): ?string
    {
        $versions = config('api.versions');

        return is_array($versions) && count($versions) ? (string) end($versions) : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(WebhookEndpoint $endpoint, bool $withSecret = false): array
    {
        $data = [
            'id'          => $endpoint->uuid,
            'url'         => $endpoint->url,
            'events'      => $endpoint->events ?: [],
            'description' => $endpoint->description,
            'version'     => $endpoint->version,
            'mode'        => $endpoint->mode,
            'status'      => $endpoint->status,
            'created_at'  => $endpoint->created_at,
            'updated_at'  => $endpoint->updated_at,
        ];

        if ($withSecret) {
            $data['secret'] = $endpoint->signingSecret();
        }

        return $data;
    }
}
