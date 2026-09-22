<?php

namespace Fleetbase\Auth\OAuth\Contracts;

use Fleetbase\Auth\OAuth\OAuthUserProfile;

/**
 * The extension point for OAuth/OIDC providers.
 *
 * Adding a provider means implementing this (usually by extending
 * AbstractOAuthProviderDriver), plus a Socialite subclass if Socialite core does
 * not ship the protocol, plus one entry in config/oauth.php. Nothing else in the
 * system needs to change: the registry, the {provider} route segment and the admin
 * settings UI are all driven off this interface.
 */
interface OAuthProviderDriver
{
    /**
     * Stable identifier used in routes, settings keys and the oauth_identities
     * table. Changing it for a shipped provider orphans existing identities.
     */
    public static function id(): string;

    /**
     * Human label for the sign-in button and the admin UI.
     */
    public static function label(): string;

    /**
     * Font Awesome brand icon slug, for the console to render.
     */
    public static function icon(): string;

    /**
     * The credentials this provider accepts, for validation and the admin form.
     *
     * Keys marked `secret` are stored encrypted and are never returned to a client.
     * Every field carries a `placeholder` showing what a value looks like.
     *
     * @return array<string, array{label: string, placeholder: string, secret?: bool, required?: bool, help?: string}>
     */
    public static function configSchema(): array;

    /**
     * Whether every credential needed to actually run a handshake is present.
     */
    public function isConfigured(): bool;

    /**
     * Whether an administrator has switched this provider on AND it is configured.
     */
    public function isEnabled(): bool;

    /**
     * Whether the provider posts its callback instead of redirecting to it.
     *
     * Apple does when name/email scopes are requested; the callback route must
     * accept both verbs for it.
     */
    public function usesFormPostCallback(): bool;

    /**
     * Build the URL the browser is sent to in order to begin authorization.
     */
    public function authorizationUrl(string $state, string $codeVerifier, string $redirectUri): string;

    /**
     * Trade an authorization code for a normalized identity.
     *
     * @param array<string, mixed> $callbackPayload the raw callback query/body, for
     *                                              providers that carry profile data
     *                                              outside the token response
     */
    public function exchange(string $code, string $codeVerifier, string $redirectUri, array $callbackPayload = []): OAuthUserProfile;

    /**
     * Ask the provider whether it accepts these client credentials, without signing
     * anyone in. Makes one request to the provider's token endpoint.
     */
    public function verifyCredentials(string $redirectUri): \Fleetbase\Auth\OAuth\CredentialCheck;
}
