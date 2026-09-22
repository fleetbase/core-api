<?php

namespace Fleetbase\Auth\OAuth;

/**
 * What a provider said about a client id and secret.
 *
 * Produced by presenting the credentials to the provider's token endpoint with an
 * authorization code that cannot be valid. Providers authenticate the client before
 * they look at the code, so the error that comes back tells the two apart: a
 * rejected client means bad credentials, a rejected code means the credentials
 * were accepted. Nobody is signed in and no token is issued.
 */
enum CredentialCheck: string
{
    /** The provider accepted the client credentials. */
    case Verified = 'verified';

    /** The provider rejected the client id or secret. */
    case InvalidClient = 'invalid_client';

    /** The credentials may be fine, but the callback URL is not registered with the provider. */
    case RedirectUriMismatch = 'redirect_uri_mismatch';

    /** The provider could not be reached, or failed on its side. */
    case Unreachable = 'unreachable';

    /** The provider answered with an error this check does not recognise. */
    case Inconclusive = 'inconclusive';

    public function passed(): bool
    {
        return $this === self::Verified;
    }

    /**
     * Classify an OAuth token-endpoint error code.
     *
     * Covers the codes Google, Microsoft, Apple and GitHub actually return; GitHub
     * uses its own names and answers with HTTP 200, the others use RFC 6749 codes.
     */
    public static function fromTokenError(?string $error): self
    {
        return match ($error) {
            // The client authenticated; only the (deliberately bogus) code was refused.
            'invalid_grant', 'bad_verification_code' => self::Verified,
            // Unknown client id, wrong secret, or an app the provider will not serve.
            'invalid_client', 'unauthorized_client', 'incorrect_client_credentials' => self::InvalidClient,
            'redirect_uri_mismatch' => self::RedirectUriMismatch,
            default                 => self::Inconclusive,
        };
    }
}
