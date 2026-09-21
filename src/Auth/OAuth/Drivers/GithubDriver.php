<?php

namespace Fleetbase\Auth\OAuth\Drivers;

use Fleetbase\Auth\OAuth\AbstractOAuthProviderDriver;
use Fleetbase\Auth\OAuth\OAuthUserProfile;
use Fleetbase\Auth\OAuth\Socialite\GithubProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * GitHub.
 */
class GithubDriver extends AbstractOAuthProviderDriver
{
    public static function id(): string
    {
        return 'github';
    }

    public static function label(): string
    {
        return 'GitHub';
    }

    public static function icon(): string
    {
        return 'github';
    }

    public static function configSchema(): array
    {
        return [
            'client_id' => [
                'label'       => 'Client ID',
                'placeholder' => 'Ov23li…',
                'required'    => true,
            ],
            'client_secret' => [
                'label'       => 'Client Secret',
                'placeholder' => '40-character client secret',
                'secret'      => true,
                'required'    => true,
            ],
        ];
    }

    protected function socialiteProviderClass(): string
    {
        return GithubProvider::class;
    }

    protected function scopes(): array
    {
        // `user:email` is required: GitHub's /user endpoint returns only the PUBLIC
        // profile email, which the user can set to anything and which GitHub does not
        // vouch for. The verified address comes from /user/emails.
        return ['read:user', 'user:email'];
    }

    protected function normalize(SocialiteUser $user, array $callbackPayload): OAuthUserProfile
    {
        $email = $user->getEmail();

        return new OAuthUserProfile(
            self::id(),
            (string) $user->getId(),
            $email,
            // Socialite's GithubProvider::getEmailByToken() returns an address only
            // when GitHub reports it as both `primary` and `verified`, and overwrites
            // the public profile email with null otherwise. So a present address here
            // IS the verified one — there is no separate flag to read.
            $email !== null && $email !== '',
            $user->getName(),
            $user->getAvatar(),
            array_filter([
                'login' => $user->getNickname(),
            ], fn ($value) => $value !== null)
        );
    }
}
