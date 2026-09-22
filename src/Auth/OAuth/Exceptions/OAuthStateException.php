<?php

namespace Fleetbase\Auth\OAuth\Exceptions;

/**
 * A state, handoff or registration-intent token was absent, malformed, expired, already
 * consumed, or presented for the wrong purpose.
 *
 * These cases are deliberately NOT distinguished from one another in the message: telling a
 * caller which of them applies is an oracle that helps an attacker probe for live tokens.
 */
class OAuthStateException extends OAuthException
{
}
