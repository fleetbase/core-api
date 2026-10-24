<?php

namespace Fleetbase\Auth\OAuthServer\Exceptions;

/**
 * An authorization request that can't go ahead.
 *
 * When the client and redirect URI checked out, the error goes back to the app on its redirect
 * URI (RFC 6749 §4.1.2.1). When they didn't, it must not: redirecting to an unverified URI is an
 * open redirect, so the user sees the error instead. `redirectUri` is null in that case.
 */
class AuthorizeError extends \RuntimeException
{
    public function __construct(
        public string $error,
        public ?string $description = null,
        public ?string $redirectUri = null,
        public ?string $state = null,
    ) {
        parent::__construct($description ?: $error);
    }

    public function canRedirect(): bool
    {
        return $this->redirectUri !== null;
    }

    /**
     * The URL to send the user back to the app with the error, including `iss` (RFC 9207).
     */
    public function redirectUrl(string $issuer): ?string
    {
        if (!$this->canRedirect()) {
            return null;
        }

        $params = array_filter([
            'error'             => $this->error,
            'error_description' => $this->description,
            'state'             => $this->state,
            'iss'               => $issuer,
        ], fn ($value) => $value !== null && $value !== '');

        $separator = str_contains($this->redirectUri, '?') ? '&' : '?';

        return $this->redirectUri . $separator . http_build_query($params);
    }
}
