<?php

namespace Fleetbase\Auth\OAuth\Drivers;

use Fleetbase\Auth\OAuth\AbstractOAuthProviderDriver;
use Fleetbase\Auth\OAuth\AppleClientSecretFactory;
use Fleetbase\Auth\OAuth\IdTokenVerifier;
use Fleetbase\Auth\OAuth\OAuthProviderConfig;
use Fleetbase\Auth\OAuth\OAuthUserProfile;
use Fleetbase\Auth\OAuth\Socialite\AppleProvider;
use Illuminate\Http\Request;
use Laravel\Socialite\Two\AbstractProvider as SocialiteProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * Sign in with Apple.
 */
class AppleDriver extends AbstractOAuthProviderDriver
{
    /**
     * Apple's per-application relay domain. Addresses here are aliases that forward
     * to the user's real inbox and are unique per application.
     */
    public const PRIVATE_RELAY_DOMAIN = 'privaterelay.appleid.com';

    protected AppleClientSecretFactory $clientSecretFactory;

    public function __construct(
        OAuthProviderConfig $config,
        Request $request,
        IdTokenVerifier $idTokenVerifier,
        ?AppleClientSecretFactory $clientSecretFactory = null,
    ) {
        parent::__construct($config, $request, $idTokenVerifier);

        $this->clientSecretFactory = $clientSecretFactory ?? new AppleClientSecretFactory();
    }

    public static function id(): string
    {
        return 'apple';
    }

    public static function label(): string
    {
        return 'Apple';
    }

    public static function icon(): string
    {
        return 'apple';
    }

    public static function configSchema(): array
    {
        return [
            'client_id' => [
                'label'    => 'Services ID',
                'required' => true,
                'help'     => 'The Services identifier, for example io.fleetbase.console — not an app bundle ID.',
            ],
            'team_id' => [
                'label'    => 'Team ID',
                'required' => true,
            ],
            'key_id' => [
                'label'    => 'Key ID',
                'required' => true,
            ],
            'private_key' => [
                'label'    => 'Signing key (.p8)',
                'secret'   => true,
                'required' => true,
                'help'     => 'Contents of the AuthKey .p8 file. Apple has no static client secret; one is minted from this key for each token request.',
            ],
        ];
    }

    protected static function requiredKeys(): array
    {
        return ['client_id', 'team_id', 'key_id'];
    }

    protected static function requiredSecrets(): array
    {
        return ['private_key'];
    }

    public function usesFormPostCallback(): bool
    {
        return true;
    }

    protected function socialiteProviderClass(): string
    {
        return AppleProvider::class;
    }

    protected function scopes(): array
    {
        return ['name', 'email'];
    }

    /**
     * Apple issues no static client secret — mint a short-lived ES256 assertion.
     */
    protected function clientSecret(): string
    {
        return $this->clientSecretFactory->make($this->config);
    }

    protected function additionalAuthorizationParameters(): array
    {
        // Mandatory: Apple rejects the `name`/`email` scopes unless the response is
        // form-posted. This is why the callback route must accept POST as well as GET.
        return ['response_mode' => 'form_post'];
    }

    protected function configureProvider(SocialiteProvider $provider): SocialiteProvider
    {
        if ($provider instanceof AppleProvider) {
            $provider->withIdTokenVerifier($this->idTokenVerifier);
        }

        return $provider;
    }

    protected function normalize(SocialiteUser $user, array $callbackPayload): OAuthUserProfile
    {
        $email = $user->getEmail();

        return new OAuthUserProfile(
            self::id(),
            (string) $user->getId(),
            $email,
            $this->emailIsVerified($user),
            // Apple never puts the name in the id_token. It sends it exactly once, in
            // the body of the FIRST authorization, and never again — so if it is not
            // captured here it is gone for good. The signup form keeps the name field
            // editable for precisely this reason.
            $this->nameFromCallback($callbackPayload),
            null,
            array_filter([
                'private_relay' => $this->isPrivateRelay($email) ?: null,
            ], fn ($value) => $value !== null)
        );
    }

    /**
     * Apple reports `email_verified` as either a boolean or the STRING "true",
     * depending on the flow. Both mean verified; anything else does not.
     */
    protected function emailIsVerified(SocialiteUser $user): bool
    {
        $claim = $this->rawClaim($user, 'email_verified');

        if (is_bool($claim)) {
            return $claim;
        }

        if (is_string($claim)) {
            return filter_var($claim, FILTER_VALIDATE_BOOL);
        }

        return false;
    }

    /**
     * Whether this is a per-application relay alias rather than the user's real
     * address.
     *
     * Callers must not use a relay address to decide that an account already exists:
     * the same person gets a different alias for every application, so it can never
     * match an address Fleetbase already holds.
     */
    protected function isPrivateRelay(?string $email): bool
    {
        return is_string($email) && str_ends_with(strtolower($email), '@' . self::PRIVATE_RELAY_DOMAIN);
    }

    /**
     * @param array<string, mixed> $callbackPayload
     */
    protected function nameFromCallback(array $callbackPayload): ?string
    {
        $user = $callbackPayload['user'] ?? null;

        if (is_string($user)) {
            $user = json_decode($user, true);
        }

        if (!is_array($user) || !is_array($user['name'] ?? null)) {
            return null;
        }

        $name = trim(implode(' ', array_filter([
            $user['name']['firstName'] ?? null,
            $user['name']['lastName'] ?? null,
        ], 'is_string')));

        return $name !== '' ? $name : null;
    }
}
