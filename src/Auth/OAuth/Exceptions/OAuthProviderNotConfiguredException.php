<?php

namespace Fleetbase\Auth\OAuth\Exceptions;

/**
 * A provider was asked to do work but is missing a required credential.
 *
 * The message names the provider and the missing key, never the value of any key
 * that is present.
 */
class OAuthProviderNotConfiguredException extends OAuthException
{
}
