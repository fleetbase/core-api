<?php

namespace Fleetbase\Auth\OAuth\Socialite;

use Fleetbase\Auth\OAuth\Socialite\Concerns\ServerSidePkce;
use Laravel\Socialite\Two\GoogleProvider as BaseGoogleProvider;

/**
 * Socialite's Google provider, driven from server-side state instead of the session.
 *
 * Google's userinfo response already carries the `email_verified` claim that
 * GoogleDriver reads, so nothing else needs overriding.
 */
class GoogleProvider extends BaseGoogleProvider
{
    use ServerSidePkce;
}
