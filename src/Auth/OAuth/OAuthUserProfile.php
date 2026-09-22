<?php

namespace Fleetbase\Auth\OAuth;

/**
 * A normalized identity as asserted by an OAuth/OIDC provider.
 *
 * Every provider driver returns one of these, so nothing downstream of a driver has to know
 * which provider it is dealing with.
 *
 * `emailVerified` records whether the provider ASSERTED the address as verified. It defaults
 * to false and every driver must set it explicitly from that provider's own signal — an
 * unknown verification state is never "verified". It is used for exactly two decisions:
 * whether to promote users.email_verified_at, and whether an email collision with an existing
 * account reports link_required. It is never, on its own, sufficient to authenticate anyone.
 */
class OAuthUserProfile implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $meta             non-secret provider detail (locale, avatar, private-relay flag)
     * @param array<string, mixed> $rawTokenResponse held in memory for the life of the request only; never persisted or serialized
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $providerUserId,
        public readonly ?string $email = null,
        public readonly bool $emailVerified = false,
        public readonly ?string $name = null,
        public readonly ?string $avatar = null,
        public readonly array $meta = [],
        public readonly array $rawTokenResponse = [],
    ) {
    }

    /**
     * Return a copy carrying the provider's raw token response.
     *
     * The token response is kept only so a driver can read id_token claims during
     * normalization. It is dropped by both jsonSerialize() and toIdentityAttributes(), and
     * Fleetbase never stores provider access or refresh tokens.
     *
     * @param array<string, mixed> $tokenResponse
     */
    public function withRawTokenResponse(array $tokenResponse): self
    {
        return new self(
            $this->provider,
            $this->providerUserId,
            $this->email,
            $this->emailVerified,
            $this->name,
            $this->avatar,
            $this->meta,
            $tokenResponse,
        );
    }

    /**
     * Whether this profile carries an address the provider vouched for.
     */
    public function hasVerifiedEmail(): bool
    {
        return $this->emailVerified && !empty($this->email);
    }

    /**
     * Read a single non-secret meta value.
     */
    public function meta(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }

    /**
     * The subset of this profile that is persisted onto an oauth_identities row.
     *
     * @return array<string, mixed>
     */
    public function toIdentityAttributes(): array
    {
        return [
            'provider'         => $this->provider,
            'provider_user_id' => $this->providerUserId,
            'provider_email'   => $this->email,
            'email_verified'   => $this->emailVerified,
            'meta'             => $this->meta,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'provider'         => $this->provider,
            'provider_user_id' => $this->providerUserId,
            'email'            => $this->email,
            'email_verified'   => $this->emailVerified,
            'name'             => $this->name,
            'avatar'           => $this->avatar,
            'meta'             => $this->meta,
        ];
    }

    /**
     * Rebuild a profile from its serialized form.
     *
     * Used when a handoff or registration-intent payload is read back out of oauth_states.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) self::stringOrNull($data['provider'] ?? null),
            (string) self::stringOrNull($data['provider_user_id'] ?? null),
            self::stringOrNull($data['email'] ?? null),
            (bool) ($data['email_verified'] ?? false),
            self::stringOrNull($data['name'] ?? null),
            self::stringOrNull($data['avatar'] ?? null),
            is_array($data['meta'] ?? null) ? $data['meta'] : [],
        );
    }

    /**
     * Narrow a decoded JSON value to a string, discarding anything that is not scalar.
     *
     * Payloads are read back out of storage, so a nested array or object here would mean the
     * row was corrupted or tampered with; dropping it is safer than coercing it.
     */
    private static function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
