<?php

namespace Fleetbase\Auth\OAuth\Drivers;

use Fleetbase\Auth\OAuth\AbstractOAuthProviderDriver;
use Fleetbase\Auth\OAuth\Exceptions\OAuthException;
use Fleetbase\Auth\OAuth\OAuthUserProfile;
use Fleetbase\Auth\OAuth\Socialite\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * Google / Google Workspace.
 */
class GoogleDriver extends AbstractOAuthProviderDriver
{
    public static function id(): string
    {
        return 'google';
    }

    public static function label(): string
    {
        return 'Google';
    }

    public static function icon(): string
    {
        return 'google';
    }

    public static function configSchema(): array
    {
        return [
            'client_id' => [
                'label'       => 'Client ID',
                'placeholder' => '123456789012-abc123def456.apps.googleusercontent.com',
                'required'    => true,
            ],
            'client_secret' => [
                'label'       => 'Client Secret',
                'placeholder' => 'GOCSPX-…',
                'secret'      => true,
                'required'    => true,
            ],
            'hosted_domain' => [
                'label'       => 'Restrict to Workspace domain',
                'placeholder' => 'example.com',
                'help'        => 'Optional. Only accounts in this Google Workspace domain may sign in.',
            ],
        ];
    }

    protected function socialiteProviderClass(): string
    {
        return GoogleProvider::class;
    }

    protected function scopes(): array
    {
        return ['openid', 'email', 'profile'];
    }

    protected function additionalAuthorizationParameters(): array
    {
        $parameters = ['prompt' => 'select_account'];

        $hostedDomain = $this->config->get('hosted_domain');

        if (is_string($hostedDomain) && $hostedDomain !== '') {
            // A request hint only. Google documents that `hd` here is not a guarantee,
            // so the claim is re-checked in normalize() below.
            $parameters['hd'] = $hostedDomain;
        }

        return $parameters;
    }

    protected function normalize(SocialiteUser $user, array $callbackPayload): OAuthUserProfile
    {
        $hostedDomain = $this->config->get('hosted_domain');

        if (is_string($hostedDomain) && $hostedDomain !== '') {
            // Enforced server side against the verified claim. Without this, passing
            // `hd` on the authorization request is decorative — a user outside the
            // domain can still complete the flow.
            if ($this->rawClaim($user, 'hd') !== $hostedDomain) {
                throw new OAuthException('hosted_domain_mismatch');
            }
        }

        return new OAuthUserProfile(
            self::id(),
            (string) $user->getId(),
            $user->getEmail(),
            // Google states explicitly whether it verified the address. Anything other
            // than boolean true — including the string "true" — is not a verification.
            $this->rawClaim($user, 'email_verified') === true,
            $user->getName(),
            $user->getAvatar(),
            array_filter([
                'hd'     => $this->rawClaim($user, 'hd'),
                'locale' => $this->rawClaim($user, 'locale'),
            ], fn ($value) => $value !== null)
        );
    }
}
