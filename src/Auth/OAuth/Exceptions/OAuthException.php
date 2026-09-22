<?php

namespace Fleetbase\Auth\OAuth\Exceptions;

/**
 * Base class for every failure in the OAuth flow.
 *
 * The message is a stable machine code (for example `identity_already_linked`), not prose,
 * so it can be returned to a client and matched in tests without string-sniffing. Never put
 * a token, a secret or a provider response body in one of these.
 */
class OAuthException extends \Exception
{
}
