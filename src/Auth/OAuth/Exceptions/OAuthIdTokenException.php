<?php

namespace Fleetbase\Auth\OAuth\Exceptions;

/**
 * An ID token failed verification: bad signature, unknown key, wrong audience,
 * wrong issuer, or expired.
 *
 * Which of those it was is recorded in the log, not in the message — the message
 * reaches the client.
 */
class OAuthIdTokenException extends OAuthException
{
}
