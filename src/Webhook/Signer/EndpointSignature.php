<?php

namespace Fleetbase\Webhook\Signer;

/**
 * Per-endpoint webhook signature, sent as `X-Fleetbase-Signature: t=<unix time>,v1=<hex hmac>`.
 *
 * The HMAC-SHA256 covers "<t>.<raw request body>" with the endpoint's signing secret, so a
 * receiver can verify the body it received byte for byte and reject old timestamps to stop
 * replays. Receivers recompute the HMAC over the raw body (never a re-encoded one) and compare in
 * constant time. The legacy `Signature` header, keyed on the API credential, is still sent.
 */
class EndpointSignature
{
    public const HEADER = 'X-Fleetbase-Signature';

    public static function header(string $body, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /**
     * Verify a signature header against a raw body. `$tolerance` is the accepted age in seconds.
     */
    public static function verify(string $body, ?string $header, string $secret, int $tolerance = 300, ?int $now = null): bool
    {
        if (!$header || $secret === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($key !== null && $value !== null) {
                $parts[$key][] = $value;
            }
        }

        $timestamp = isset($parts['t'][0]) && ctype_digit($parts['t'][0]) ? (int) $parts['t'][0] : null;
        if ($timestamp === null || abs(($now ?? time()) - $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        foreach ($parts['v1'] ?? [] as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
