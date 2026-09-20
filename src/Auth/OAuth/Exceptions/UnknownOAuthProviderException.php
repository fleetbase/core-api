<?php

namespace Fleetbase\Auth\OAuth\Exceptions;

/**
 * A provider id was requested that this installation does not define.
 *
 * Raised from the registry when the {provider} route segment does not match a
 * configured provider, so a caller cannot probe for which providers exist by
 * watching for different failure modes.
 */
class UnknownOAuthProviderException extends OAuthException
{
}
