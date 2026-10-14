<?php

namespace Fleetbase\Support\SocketCluster;

use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Illuminate\Support\Carbon;

/**
 * The identity a realtime socket connection acts as.
 *
 * Built from a verified socket token's claims, or directly from a user or API credential
 * when the API mints a token. Immutable: use with() to derive a changed copy.
 */
final class SocketPrincipal
{
    public const KINDS = ['user', 'api', 'driver', 'customer', 'checkout', 'system', 'tracking'];

    public const ENVIRONMENTS = ['live', 'test'];

    public function __construct(
        public readonly string $kind,
        public readonly string $sub,
        public readonly ?string $cid = null,
        public readonly ?string $cpid = null,
        public readonly string $env = 'live',
        public readonly array $ids = [],
        public readonly bool $adm = false,
        public readonly ?array $scp = null,
        public readonly ?string $sid = null,
        public readonly ?string $jti = null,
        public readonly ?int $exp = null,
    ) {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Unknown socket principal kind.');
        }

        if ($sub === '') {
            throw new \InvalidArgumentException('A socket principal requires a subject.');
        }

        if (!in_array($env, self::ENVIRONMENTS, true)) {
            throw new \InvalidArgumentException('Unknown socket principal environment.');
        }
    }

    /**
     * Rebuild a principal from token claims; registered date claims may be DateTimeInterface or unix seconds.
     */
    public static function fromClaims(array $claims): self
    {
        return new self(
            kind: (string) ($claims['kind'] ?? ''),
            sub: (string) ($claims['sub'] ?? ''),
            cid: self::stringOrNull($claims['cid'] ?? null),
            cpid: self::stringOrNull($claims['cpid'] ?? null),
            env: (string) ($claims['env'] ?? 'live'),
            ids: self::stringList($claims['ids'] ?? []),
            adm: (bool) ($claims['adm'] ?? false),
            scp: isset($claims['scp']) ? self::stringList($claims['scp']) : null,
            sid: self::stringOrNull($claims['sid'] ?? null),
            jti: self::stringOrNull($claims['jti'] ?? null),
            exp: self::timestamp($claims['exp'] ?? null)
        );
    }

    /**
     * A console or API user acting within one company.
     */
    public static function forUser(User $user, ?string $companyUuid = null): self
    {
        $cid = $companyUuid ?: session('company') ?: $user->company_uuid;

        return new self(
            kind: 'user',
            sub: (string) $user->uuid,
            cid: self::stringOrNull($cid),
            cpid: self::companyPublicId($cid),
            env: 'live',
            ids: self::stringList([$user->uuid, $user->public_id]),
            adm: $user->isAdmin()
        );
    }

    /**
     * An API credential; test-mode credentials act on the sandbox environment.
     */
    public static function forApiCredential(ApiCredential $credential): self
    {
        return new self(
            kind: 'api',
            sub: (string) $credential->uuid,
            cid: self::stringOrNull($credential->company_uuid),
            cpid: self::companyPublicId($credential->company_uuid, $credential->getConnectionName()),
            env: $credential->test_mode ? 'test' : 'live',
            ids: self::stringList([$credential->uuid])
        );
    }

    /**
     * The platform itself, which may subscribe to any channel.
     */
    public static function system(): self
    {
        return new self(kind: 'system', sub: 'system');
    }

    /**
     * The claims this principal contributes to a token, without the unset optional ones.
     */
    public function toClaims(): array
    {
        return array_filter([
            'kind' => $this->kind,
            'sub'  => $this->sub,
            'cid'  => $this->cid,
            'cpid' => $this->cpid,
            'env'  => $this->env,
            'ids'  => $this->ids,
            'adm'  => $this->adm,
            'scp'  => $this->scp,
            'sid'  => $this->sid,
            'jti'  => $this->jti,
            'exp'  => $this->exp,
        ], fn ($value) => $value !== null);
    }

    /**
     * A copy of this principal with the given properties replaced.
     */
    public function with(array $changes): self
    {
        return new self(...array_merge([
            'kind' => $this->kind,
            'sub'  => $this->sub,
            'cid'  => $this->cid,
            'cpid' => $this->cpid,
            'env'  => $this->env,
            'ids'  => $this->ids,
            'adm'  => $this->adm,
            'scp'  => $this->scp,
            'sid'  => $this->sid,
            'jti'  => $this->jti,
            'exp'  => $this->exp,
        ], $changes));
    }

    public function isSystem(): bool
    {
        return $this->kind === 'system';
    }

    public function isCompanyScoped(): bool
    {
        return $this->kind === 'user' || $this->kind === 'api';
    }

    public function owns(string $id): bool
    {
        return $id !== '' && in_array($id, $this->ids, true);
    }

    /**
     * Seconds until the token this principal came from expires, or null when it carries no expiry.
     */
    public function secondsRemaining(): ?int
    {
        return $this->exp === null ? null : $this->exp - Carbon::now()->getTimestamp();
    }

    private static function companyPublicId(?string $companyUuid, ?string $connection = null): ?string
    {
        if (!$companyUuid) {
            return null;
        }

        return self::stringOrNull(Company::on($connection)->where('uuid', $companyUuid)->value('public_id'));
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function stringList(mixed $values): array
    {
        return array_values(array_filter((array) $values, fn ($value) => is_string($value) && $value !== ''));
    }

    private static function timestamp(mixed $value): ?int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
