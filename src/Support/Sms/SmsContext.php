<?php

namespace Fleetbase\Support\Sms;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Who is sending an SMS, to whom, from where and why.
 *
 * Built once per send and handed to the guard. `resolve()` fills in whatever the caller
 * did not supply from the request and the session: the storefront middleware puts
 * `company`, `storefront_store` and `storefront_network` in the session, the API
 * middleware puts `company`, and the request carries the client address and user agent.
 * That is what lets every existing sender be budgeted without passing anything new.
 */
final class SmsContext
{
    public const SOURCE_STOREFRONT = 'storefront';
    public const SOURCE_API        = 'api';
    public const SOURCE_PUBLIC     = 'public';
    public const SOURCE_CONSOLE    = 'console';
    public const SOURCE_SYSTEM     = 'system';

    public function __construct(
        public readonly string $purpose,
        public readonly string $destination,
        public readonly ?string $companyUuid = null,
        public readonly ?string $tenantType = null,
        public readonly ?string $tenantUuid = null,
        public readonly string $source = self::SOURCE_SYSTEM,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
        public readonly ?string $subjectType = null,
        public readonly ?string $subjectUuid = null,
    ) {
    }

    /**
     * A context for a send, taking anything not in `$overrides` from the session and request.
     *
     * Overrides: `purpose`, `company_uuid`, `tenant_type`, `tenant_uuid`, `source`, `ip`,
     * `user_agent`, `subject` (a model; its class and uuid are recorded).
     */
    public static function resolve(string $destination, array $overrides = []): self
    {
        $session = self::sessionValues();
        $request = self::currentRequest();
        $subject = $overrides['subject'] ?? null;

        $tenantType = $overrides['tenant_type'] ?? null;
        $tenantUuid = $overrides['tenant_uuid'] ?? null;
        if (!$tenantUuid) {
            if (!empty($session['storefront_store'])) {
                [$tenantType, $tenantUuid] = ['store', (string) $session['storefront_store']];
            } elseif (!empty($session['storefront_network'])) {
                [$tenantType, $tenantUuid] = ['network', (string) $session['storefront_network']];
            }
        }

        $companyUuid = $overrides['company_uuid'] ?? $session['company'] ?? (is_object($subject) ? data_get($subject, 'company_uuid') : null);

        return new self(
            purpose: (string) ($overrides['purpose'] ?? 'sms'),
            destination: $destination,
            companyUuid: $companyUuid ? (string) $companyUuid : null,
            tenantType: $tenantType ? (string) $tenantType : null,
            tenantUuid: $tenantUuid ? (string) $tenantUuid : null,
            source: (string) ($overrides['source'] ?? self::detectSource($request)),
            ip: $overrides['ip'] ?? $request?->ip(),
            userAgent: $overrides['user_agent'] ?? ($request && $request->userAgent() ? Str::limit((string) $request->userAgent(), 250, '') : null),
            subjectType: is_object($subject) ? get_class($subject) : null,
            subjectUuid: is_object($subject) && data_get($subject, 'uuid') ? (string) data_get($subject, 'uuid') : null,
        );
    }

    /**
     * The same context with the destination replaced, after the validator normalised it.
     */
    public function withDestination(string $destination): self
    {
        return new self(
            $this->purpose,
            $destination,
            $this->companyUuid,
            $this->tenantType,
            $this->tenantUuid,
            $this->source,
            $this->ip,
            $this->userAgent,
            $this->subjectType,
            $this->subjectUuid,
        );
    }

    /**
     * Whether an anonymous caller, or one holding only a public storefront key or an
     * API credential, triggered this send. These are the sends that fail closed when the
     * guard's counters are unreachable.
     */
    public function isPublic(): bool
    {
        return in_array($this->source, [self::SOURCE_STOREFRONT, self::SOURCE_API, self::SOURCE_PUBLIC], true);
    }

    public function companyScope(): ?string
    {
        return $this->companyUuid ? 'company:' . $this->companyUuid : null;
    }

    public function tenantScope(): ?string
    {
        return $this->tenantUuid ? 'tenant:' . ($this->tenantType ?: 'tenant') . ':' . $this->tenantUuid : null;
    }

    public function toArray(): array
    {
        return [
            'purpose'      => $this->purpose,
            'destination'  => $this->destination,
            'company_uuid' => $this->companyUuid,
            'tenant_type'  => $this->tenantType,
            'tenant_uuid'  => $this->tenantUuid,
            'source'       => $this->source,
            'ip'           => $this->ip,
            'user_agent'   => $this->userAgent,
            'subject_type' => $this->subjectType,
            'subject_uuid' => $this->subjectUuid,
        ];
    }

    /**
     * Which surface a request came in on, from its first path segment.
     *
     * Onboarding is anonymous even though it lives under the console prefix, so it counts
     * as public: a self-signup farm must not get the console's lenient treatment.
     */
    public static function detectSource(?Request $request): string
    {
        if (!$request) {
            return self::SOURCE_SYSTEM;
        }

        $path = trim((string) $request->path(), '/');
        if ($path === '') {
            return self::SOURCE_SYSTEM;
        }

        $first            = Str::before($path, '/');
        $storefrontPrefix = trim((string) config('storefront.api.routing.prefix', 'storefront'), '/');
        $internalPrefix   = trim((string) config('fleetbase.api.routing.internal_prefix', 'int'), '/');

        if ($storefrontPrefix !== '' && $first === $storefrontPrefix) {
            return self::SOURCE_STOREFRONT;
        }

        if ($internalPrefix !== '' && $first === $internalPrefix) {
            return Str::contains($path, '/onboard') ? self::SOURCE_PUBLIC : self::SOURCE_CONSOLE;
        }

        if ($first === 'public' || Str::contains($path, '/public/')) {
            return self::SOURCE_PUBLIC;
        }

        if ($first === 'v1' || Str::contains($path, '/v1/')) {
            return self::SOURCE_API;
        }

        return self::SOURCE_SYSTEM;
    }

    private static function sessionValues(): array
    {
        try {
            $session = session();

            return [
                'company'            => $session->get('company'),
                'storefront_store'   => $session->get('storefront_store'),
                'storefront_network' => $session->get('storefront_network'),
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function currentRequest(): ?Request
    {
        try {
            $request = request();

            return $request instanceof Request ? $request : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
