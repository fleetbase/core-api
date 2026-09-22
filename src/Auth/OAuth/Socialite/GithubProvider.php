<?php

namespace Fleetbase\Auth\OAuth\Socialite;

use Fleetbase\Auth\OAuth\Socialite\Concerns\ServerSidePkce;
use Laravel\Socialite\Two\GithubProvider as BaseGithubProvider;

/**
 * Socialite's GitHub provider, driven from server-side state instead of the session.
 *
 * No email handling is overridden: the base provider's getEmailByToken() already
 * returns an address only when GitHub reports it as both `primary` and `verified`,
 * and leaves `email` null otherwise. GithubDriver relies on exactly that property —
 * a present email means GitHub vouched for it.
 */
class GithubProvider extends BaseGithubProvider
{
    use ServerSidePkce;
}
