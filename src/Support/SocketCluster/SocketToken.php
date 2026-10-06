<?php

namespace Fleetbase\Support\SocketCluster;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lcobucci\Clock\FrozenClock;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;

/**
 * Mints and verifies the HS256 tokens realtime clients present to the socket server.
 *
 * The signing key is SOCKETCLUSTER_AUTH_KEY, shared with the socket server. Without it
 * (or with one shorter than 32 bytes) the feature is off and nothing is minted.
 */
class SocketToken
{
    public const ISSUER = 'fleetbase-api';

    public const AUDIENCE = 'fleetbase-socket';

    public const ALGORITHM = 'HS256';

    public const MIN_KEY_LENGTH = 32;

    public const DEFAULT_TTL = 900;

    public const TRACKING_TTL = 1800;

    public const SYSTEM_TTL = 300;

    /**
     * The socket server refuses tokens that live longer than this.
     */
    public const MAX_TTL = 3600;

    /**
     * Length of the opaque id in a public tracking channel name.
     */
    public const TRACKING_ID_LENGTH = 26;

    public static function key(): ?string
    {
        $key = config('broadcasting.connections.socketcluster.auth_key');

        return is_string($key) && strlen($key) >= self::MIN_KEY_LENGTH ? $key : null;
    }

    public static function enabled(): bool
    {
        return static::key() !== null;
    }

    /**
     * Mint a token for the principal and return the token endpoint response body.
     *
     * @return array{token: string, expires_in: int, expires_at: string}
     */
    public static function issue(SocketPrincipal $principal, ?int $ttl = null): array
    {
        $configuration = static::configuration();
        $ttl           = max(1, min(self::MAX_TTL, $ttl ?? static::defaultTtl($principal->kind)));
        $now           = Carbon::now()->getTimestamp();
        $issuedAt      = new \DateTimeImmutable('@' . $now);
        $expiresAt     = new \DateTimeImmutable('@' . ($now + $ttl));

        $builder = $configuration->builder()
            ->issuedBy(self::ISSUER)
            ->permittedFor(self::AUDIENCE)
            ->identifiedBy((string) Str::uuid())
            ->relatedTo($principal->sub)
            ->issuedAt($issuedAt)
            ->canOnlyBeUsedAfter($issuedAt)
            ->expiresAt($expiresAt);

        $claims = $principal->toClaims();
        unset($claims['sub'], $claims['jti'], $claims['exp']);

        foreach ($claims as $name => $value) {
            $builder = $builder->withClaim($name, $value);
        }

        return [
            'token'      => $builder->getToken($configuration->signer(), $configuration->signingKey())->toString(),
            'expires_in' => $ttl,
            'expires_at' => $expiresAt->format(DATE_ATOM),
        ];
    }

    /**
     * The principal a token was minted for, or null when it is not one of ours or no longer valid.
     *
     * Rejects anything not signed HS256 with the configured key (including alg "none" and
     * asymmetric algorithms), a wrong issuer or audience, and missing or out-of-range iat/nbf/exp.
     */
    public static function verify(string $jwt): ?SocketPrincipal
    {
        if ($jwt === '' || !static::enabled()) {
            return null;
        }

        try {
            $configuration = static::configuration();
            $token         = $configuration->parser()->parse($jwt);

            if (!$token instanceof Plain || $token->headers()->get('alg') !== self::ALGORITHM) {
                return null;
            }

            $configuration->validator()->assert(
                $token,
                new SignedWith($configuration->signer(), $configuration->verificationKey()),
                new IssuedBy(self::ISSUER),
                new PermittedFor(self::AUDIENCE),
                new StrictValidAt(new FrozenClock(Carbon::now()->toDateTimeImmutable()))
            );

            return SocketPrincipal::fromClaims($token->claims()->all());
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * A short-lived token for the platform itself, which may subscribe to any channel.
     */
    public static function system(): string
    {
        return static::issue(SocketPrincipal::system())['token'];
    }

    /**
     * Mint the scoped token for one customer's public tracking channel.
     *
     * Accepts FleetOps' TrackingScope (order_uuid, customer_type, customer_uuid). The token
     * may subscribe to `tracking.{opaque}` and nothing else.
     *
     * @return array{token: string, expires_in: int, expires_at: string}
     */
    public static function forTracking(object $scope): array
    {
        $orderUuid  = (string) data_get($scope, 'order_uuid');
        $trackingId = static::trackingId($orderUuid, (string) data_get($scope, 'customer_type'), (string) data_get($scope, 'customer_uuid'));
        $companyId  = data_get($scope, 'company_uuid') ?: DB::table('orders')->where('uuid', $orderUuid)->value('company_uuid');

        return static::issue(new SocketPrincipal(
            kind: 'tracking',
            sub: $trackingId,
            cid: is_string($companyId) && $companyId !== '' ? $companyId : null,
            scp: ['tracking.' . $trackingId]
        ), self::TRACKING_TTL);
    }

    /**
     * The opaque id of a customer's public tracking channel: lowercase unpadded base32 of
     * HMAC-SHA256(tracking key, "{order_uuid}:{customer_type}:{customer_uuid}"), first 26 characters.
     */
    public static function trackingId(string $orderUuid, string $customerType, string $customerUuid): string
    {
        $digest = hash_hmac('sha256', $orderUuid . ':' . $customerType . ':' . $customerUuid, SocketSignature::deriveKey(SocketSignature::TRACKING), true);

        return substr(static::base32($digest), 0, self::TRACKING_ID_LENGTH);
    }

    /**
     * RFC 4648 base32, lowercase and without padding.
     */
    public static function base32(string $bytes): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
        $bits     = '';
        $encoded  = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= $alphabet[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $encoded;
    }

    public static function defaultTtl(string $kind): int
    {
        $configured = (int) config('broadcasting.connections.socketcluster.token_ttl', self::DEFAULT_TTL);

        return match ($kind) {
            'tracking' => self::TRACKING_TTL,
            'system'   => self::SYSTEM_TTL,
            default    => $configured > 0 ? $configured : self::DEFAULT_TTL,
        };
    }

    protected static function configuration(): Configuration
    {
        $key = static::key();

        if ($key === null) {
            throw new \RuntimeException('Socket authentication is not configured.');
        }

        return Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText($key));
    }
}
