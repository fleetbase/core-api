<?php

namespace Fleetbase\Support;

use Illuminate\Http\Request;

/**
 * Per-resolve scratch space shared between a transformer's `prepare()` and `transform()` calls.
 *
 * The registry creates one context for a single resolve (one resource, or every item of one
 * collection) and discards it afterwards, so transformer instances never carry per-request state.
 */
final class ResourceTransformerContext
{
    public const HTTP      = 'http';
    public const WEBHOOK   = 'webhook';
    public const BROADCAST = 'broadcast';

    public const CHANNELS = [self::HTTP, self::WEBHOOK, self::BROADCAST];

    /**
     * @var array<string, mixed>
     */
    private array $attributes = [];

    /**
     * Registration ids whose `prepare()` already ran for this context.
     *
     * @var array<string, bool>
     */
    private array $prepared = [];

    public function __construct(
        public readonly Request $request,
        public readonly string $channel = self::HTTP,
        public readonly bool $internal = false,
    ) {
    }

    public function isHttp(): bool
    {
        return $this->channel === self::HTTP;
    }

    public function isWebhook(): bool
    {
        return $this->channel === self::WEBHOOK;
    }

    public function isBroadcast(): bool
    {
        return $this->channel === self::BROADCAST;
    }

    public function isInternal(): bool
    {
        return $this->internal;
    }

    public function isPublic(): bool
    {
        return !$this->internal;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->attributes) ? $this->attributes[$key] : $default;
    }

    public function set(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    public function forget(string $key): self
    {
        unset($this->attributes[$key]);

        return $this;
    }

    /**
     * Get a value, computing and storing it on first access.
     */
    public function remember(string $key, \Closure $resolver): mixed
    {
        if (!$this->has($key)) {
            $this->set($key, $resolver($this));
        }

        return $this->get($key);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->attributes;
    }

    public function markPrepared(string $registrationId): void
    {
        $this->prepared[$registrationId] = true;
    }

    public function isPrepared(string $registrationId): bool
    {
        return isset($this->prepared[$registrationId]);
    }
}
