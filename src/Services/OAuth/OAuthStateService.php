<?php

namespace Fleetbase\Services\OAuth;

use Fleetbase\Auth\OAuth\Exceptions\OAuthStateException;
use Fleetbase\Models\OAuthState;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Issues and redeems the short-lived, single-use tokens that hold the OAuth flow together.
 *
 * Three invariants this class exists to guarantee:
 *
 *   1. The raw token is returned to the caller exactly once and is never stored. Only its
 *      sha256 is persisted, so a database dump yields nothing usable.
 *   2. Redemption is atomic. Of two concurrent redemptions of the same token exactly one
 *      succeeds, enforced by a single conditional UPDATE under a row lock rather than a
 *      read-then-write.
 *   3. A token is only ever valid for the purpose it was issued for, so a handoff code can
 *      never be replayed as a registration intent.
 */
class OAuthStateService
{
    /**
     * Length of the random portion of every token.
     */
    private const TOKEN_LENGTH = 64;

    /**
     * Prefix applied to registration intents so they are recognisable in logs and can be
     * targeted by redaction rules. The prefix is part of the token and is hashed with it.
     */
    private const REGISTRATION_INTENT_PREFIX = 'rti_';

    public function __construct(private Encrypter $encrypter)
    {
    }

    /**
     * Issue a token for the given purpose and return it. The return value is the only time
     * the raw token exists outside the caller.
     *
     * @param array<string, mixed> $payload
     *
     * @throws OAuthStateException on an unknown purpose
     */
    public function issue(
        string $purpose,
        array $payload,
        int $ttlSeconds,
        ?string $provider = null,
        ?string $intent = null,
        ?string $userUuid = null,
        ?string $ip = null,
    ): string {
        $this->assertKnownPurpose($purpose);

        $token = $this->generateToken($purpose);

        OAuthState::create([
            'purpose'    => $purpose,
            'token_hash' => $this->hash($token),
            'provider'   => $provider,
            'intent'     => $intent,
            'user_uuid'  => $userUuid,
            'payload'    => $this->encodePayload($payload),
            'ip_hash'    => $ip === null ? null : $this->hashIp($ip),
            'expires_at' => now()->addSeconds($ttlSeconds),
        ]);

        return $token;
    }

    /**
     * Atomically redeem a token, returning the row and its decrypted payload.
     *
     * @return array{state: OAuthState, payload: array<string, mixed>}
     *
     * @throws OAuthStateException when the token is absent, malformed, expired, already
     *                             consumed, issued for another purpose, or (in strict mode)
     *                             presented from a different IP
     */
    public function consume(string $purpose, string $token, ?string $ip = null): array
    {
        $this->assertKnownPurpose($purpose);

        if ($token === '') {
            throw new OAuthStateException('invalid_or_expired');
        }

        $hash = $this->hash($token);

        // One conditional UPDATE, not a read-then-write: under InnoDB this takes a row lock,
        // so exactly one of two concurrent redemptions sees an affected count of 1. Correct
        // across application nodes and independent of the cache driver.
        $affected = OAuthState::query()
            ->where('token_hash', $hash)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->update(['consumed_at' => now()]);

        if ($affected !== 1) {
            throw new OAuthStateException('invalid_or_expired');
        }

        $state = OAuthState::query()
            ->where('token_hash', $hash)
            ->where('purpose', $purpose)
            ->first();

        if (!$state instanceof OAuthState) {
            // The row was pruned or deleted between the UPDATE and the read.
            throw new OAuthStateException('invalid_or_expired');
        }

        $this->verifyIp($state, $ip);

        return ['state' => $state, 'payload' => $this->decodePayload($state)];
    }

    /**
     * Read a token's payload without redeeming it.
     *
     * Used by validation rules, which must be able to report "this intent is still good"
     * without burning it — otherwise a failed validation pass on any other field would
     * destroy the user's sign-in session.
     *
     * @return array<string, mixed>|null null when absent, malformed, expired or consumed
     */
    public function inspect(string $purpose, ?string $token): ?array
    {
        if ($token === null || $token === '' || !$this->isKnownPurpose($purpose)) {
            return null;
        }

        $state = OAuthState::query()
            ->where('token_hash', $this->hash($token))
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->first();

        if (!$state instanceof OAuthState) {
            return null;
        }

        try {
            return $this->decodePayload($state);
        } catch (OAuthStateException $e) {
            return null;
        }
    }

    /**
     * Record which user a redeemed row resolved to, for the audit trail.
     */
    public function attachUser(OAuthState $state, string $userUuid): void
    {
        $state->user_uuid = $userUuid;
        $state->save();
    }

    /**
     * Hash a raw token. Exposed so callers can look a row up without holding the raw value.
     */
    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @return array<int, string>
     */
    public static function purposes(): array
    {
        return [
            OAuthState::PURPOSE_AUTHORIZATION,
            OAuthState::PURPOSE_HANDOFF,
            OAuthState::PURPOSE_REGISTRATION_INTENT,
        ];
    }

    private function generateToken(string $purpose): string
    {
        $random = Str::random(self::TOKEN_LENGTH);

        return $purpose === OAuthState::PURPOSE_REGISTRATION_INTENT
            ? self::REGISTRATION_INTENT_PREFIX . $random
            : $random;
    }

    /**
     * HMAC rather than a bare hash so the digest cannot be reversed with a rainbow table of
     * the IPv4 space. The raw address is never stored.
     */
    private function hashIp(string $ip): string
    {
        return hash_hmac('sha256', $ip, (string) config('app.key'));
    }

    /**
     * Compare the presenting IP against the issuing one.
     *
     * Soft by default — a phone that moves between wifi and cellular mid-flow legitimately
     * changes address, and failing those users closed would be worse than the marginal
     * binding this provides. Operators who can guarantee stable addressing can set
     * OAUTH_STRICT_IP_BINDING to make it a hard failure.
     *
     * @throws OAuthStateException
     */
    private function verifyIp(OAuthState $state, ?string $ip): void
    {
        if ($ip === null || empty($state->ip_hash)) {
            return;
        }

        if (hash_equals((string) $state->ip_hash, $this->hashIp($ip))) {
            return;
        }

        if (config('oauth.strict_ip_binding', false)) {
            throw new OAuthStateException('invalid_or_expired');
        }

        // No address, no token, no payload — just the fact and enough to correlate.
        Log::warning('[OAuth] State redeemed from a different IP than it was issued to.', [
            'purpose'  => $state->purpose,
            'provider' => $state->provider,
            'state'    => $state->uuid,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodePayload(array $payload): ?string
    {
        if ($payload === []) {
            return null;
        }

        // encrypt(..., false) skips PHP serialization: the payload is already JSON, and
        // decrypting into unserialize() would be an object-injection sink.
        return $this->encrypter->encrypt((string) json_encode($payload), false);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws OAuthStateException
     */
    private function decodePayload(OAuthState $state): array
    {
        if (empty($state->payload)) {
            return [];
        }

        try {
            $decrypted = $this->encrypter->decrypt((string) $state->payload, false);
        } catch (\Throwable $e) {
            // Almost always a rotated APP_KEY. Name the row, never the ciphertext.
            Log::error('[OAuth] Failed to decrypt state payload.', [
                'purpose' => $state->purpose,
                'state'   => $state->uuid,
            ]);

            throw new OAuthStateException('invalid_or_expired');
        }

        // decrypt(..., false) is typed mixed. Anything that is not a JSON string means the
        // row was written by something other than encodePayload(); treat it as empty rather
        // than coercing it.
        if (!is_string($decrypted)) {
            return [];
        }

        $decoded = json_decode($decrypted, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @throws OAuthStateException
     */
    private function assertKnownPurpose(string $purpose): void
    {
        if (!$this->isKnownPurpose($purpose)) {
            throw new OAuthStateException('unknown_purpose');
        }
    }

    private function isKnownPurpose(string $purpose): bool
    {
        return in_array($purpose, self::purposes(), true);
    }
}
