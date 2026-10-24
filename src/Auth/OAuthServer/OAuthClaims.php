<?php

namespace Fleetbase\Auth\OAuthServer;

use Fleetbase\Models\Company;
use Fleetbase\Models\User;

/**
 * The OpenID Connect claims about a user, by scope. Shared by the ID token and /oauth/userinfo.
 */
class OAuthClaims
{
    /**
     * @param array<int, string> $scopes
     *
     * @return array<string, mixed>
     */
    public static function forUser(User $user, array $scopes, ?Company $company = null): array
    {
        $claims = ['sub' => static::subject($user)];

        if (in_array('profile', $scopes, true)) {
            $claims['name']       = $user->name;
            $claims['picture']    = $user->avatar_url;
            $claims['zoneinfo']   = $user->timezone;
            $claims['updated_at'] = $user->updated_at?->getTimestamp();
        }

        if (in_array('email', $scopes, true)) {
            $claims['email']          = $user->email;
            $claims['email_verified'] = $user->email_verified_at !== null;
        }

        // The organization the tokens are bound to, so the app knows which one the user picked.
        if ($company && OAuthScopes::requiresOrganization($scopes)) {
            $claims['org_id']   = $company->public_id;
            $claims['org_name'] = $company->name;
        }

        return $claims;
    }

    /**
     * The stable identifier for the user: their public_id, which never changes or gets reused.
     */
    public static function subject(User $user): string
    {
        return (string) ($user->public_id ?: $user->uuid);
    }
}
