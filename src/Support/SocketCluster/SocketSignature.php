<?php

namespace Fleetbase\Support\SocketCluster;

use Illuminate\Support\Carbon;

/**
 * HMAC signing for requests between the API and the socket server's internal endpoints.
 *
 * Each purpose uses its own key derived from SOCKETCLUSTER_AUTH_KEY, so a key that leaks
 * from one exchange cannot sign another. The signature covers "{timestamp}.{raw body}".
 */
class SocketSignature
{
    public const PUBLISH = 'publish';

    public const AUTHORIZE = 'authorize';

    public const TRACKING = 'tracking';

    public const HEADER_TIMESTAMP = 'X-Fleetbase-Timestamp';

    public const HEADER_SIGNATURE = 'X-Fleetbase-Signature';

    /**
     * Largest accepted difference, in seconds, between a request's timestamp and now.
     */
    public const TOLERANCE = 60;

    /**
     * The hex-encoded key for one purpose: HMAC-SHA256(auth key, "fleetbase-socket:{purpose}").
     */
    public static function deriveKey(string $purpose): string
    {
        $key = SocketToken::key();

        if ($key === null) {
            throw new \RuntimeException('Socket authentication is not configured.');
        }

        return hash_hmac('sha256', 'fleetbase-socket:' . $purpose, $key);
    }

    public static function sign(string $purpose, string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, static::deriveKey($purpose));
    }

    /**
     * The headers that sign the given raw body for the given purpose, timestamped now.
     */
    public static function headers(string $purpose, string $body): array
    {
        $timestamp = (string) Carbon::now()->getTimestamp();

        return [
            self::HEADER_TIMESTAMP => $timestamp,
            self::HEADER_SIGNATURE => static::sign($purpose, $timestamp, $body),
        ];
    }

    /**
     * Whether a request's timestamp and signature are valid for the raw body, compared in constant time.
     */
    public static function verify(string $purpose, ?string $timestamp, ?string $signature, string $body): bool
    {
        if (!SocketToken::enabled() || !is_string($timestamp) || !ctype_digit($timestamp) || !is_string($signature) || $signature === '') {
            return false;
        }

        if (abs(Carbon::now()->getTimestamp() - (int) $timestamp) > self::TOLERANCE) {
            return false;
        }

        return hash_equals(static::sign($purpose, $timestamp, $body), strtolower($signature));
    }
}
