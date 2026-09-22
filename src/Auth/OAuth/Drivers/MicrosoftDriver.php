<?php

namespace Fleetbase\Auth\OAuth\Drivers;

use Fleetbase\Auth\OAuth\AbstractOAuthProviderDriver;
use Fleetbase\Auth\OAuth\OAuthUserProfile;
use Fleetbase\Auth\OAuth\Socialite\MicrosoftProvider;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Two\AbstractProvider as SocialiteProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * Microsoft identity platform (Entra ID / Azure AD), including personal accounts.
 */
class MicrosoftDriver extends AbstractOAuthProviderDriver
{
    /**
     * The fixed tenant id every personal (MSA) Microsoft account is issued under.
     */
    public const MSA_CONSUMER_TENANT = '9188040d-6c67-4c5b-b112-36a304b66dad';

    /**
     * Tenant values that mean "any tenant" rather than a specific directory.
     *
     * @var array<int, string>
     */
    public const MULTI_TENANT_ALIASES = ['common', 'organizations', 'consumers'];

    public static function id(): string
    {
        return 'microsoft';
    }

    public static function label(): string
    {
        return 'Microsoft';
    }

    public static function icon(): string
    {
        return 'microsoft';
    }

    public static function configSchema(): array
    {
        return [
            'client_id' => [
                'label'       => 'Application (client) ID',
                'placeholder' => '00000000-0000-0000-0000-000000000000',
                'required'    => true,
            ],
            'client_secret' => [
                'label'       => 'Client Secret',
                'placeholder' => 'Client secret value, not the secret ID',
                'secret'      => true,
                'required'    => true,
            ],
            'tenant' => [
                'label'       => 'Directory (tenant) ID',
                'placeholder' => 'common, or a tenant ID or domain such as contoso.onmicrosoft.com',
                'help'        => 'A tenant ID or domain restricts sign-in to that directory. "common" accepts any Microsoft account, but then only addresses Microsoft explicitly marks as domain-verified are trusted.',
            ],
        ];
    }

    protected function socialiteProviderClass(): string
    {
        return MicrosoftProvider::class;
    }

    protected function scopes(): array
    {
        return ['openid', 'profile', 'email'];
    }

    protected function configureProvider(SocialiteProvider $provider): SocialiteProvider
    {
        if ($provider instanceof MicrosoftProvider) {
            $provider->withTenant($this->tenant())->withIdTokenVerifier($this->idTokenVerifier);
        }

        return $provider;
    }

    protected function normalize(SocialiteUser $user, array $callbackPayload): OAuthUserProfile
    {
        $tenantId = $this->rawClaim($user, 'tid');

        return new OAuthUserProfile(
            self::id(),
            (string) $user->getId(),
            $user->getEmail(),
            $this->emailIsVerified($user),
            $user->getName(),
            null,
            array_filter([
                'tid'                => is_string($tenantId) ? $tenantId : null,
                'preferred_username' => $this->rawClaim($user, 'preferred_username'),
            ], fn ($value) => $value !== null)
        );
    }

    /**
     * Decide whether Microsoft actually vouched for this address.
     *
     * This is deliberately strict. A personal Microsoft account holder can set an
     * arbitrary `email` / `preferred_username`, and anyone can stand up their own
     * Entra tenant — so neither claim is evidence on its own.
     *
     * Two things count:
     *
     *   1. `xms_edov` — "email domain owner verified". Microsoft's own signal that the
     *      directory has proven ownership of the address's domain. Authoritative.
     *   2. A single-tenant deployment where the token came from the configured tenant.
     *      There the operator controls the directory, so its addresses are as
     *      trustworthy as the operator's own user list.
     *
     * A multi-tenant ("common") deployment without `xms_edov` is NOT verified, even
     * for a work account.
     */
    protected function emailIsVerified(SocialiteUser $user): bool
    {
        $edov = $this->rawClaim($user, 'xms_edov');

        if (self::claimIsTrue($edov)) {
            return true;
        }

        $configuredTenant = $this->tenant();

        if (in_array($configuredTenant, self::MULTI_TENANT_ALIASES, true)) {
            // Say why, without the token or the address: an operator who configured the
            // optional claim needs to know whether it arrived at all.
            Log::info('[OAuth] Microsoft email not treated as verified.', [
                'tenant'   => $configuredTenant,
                'xms_edov' => $edov === null ? 'absent' : (is_scalar($edov) ? var_export($edov, true) : gettype($edov)),
            ]);

            return false;
        }

        $tokenTenant = $this->rawClaim($user, 'tid');

        return is_string($tokenTenant)
            && $tokenTenant !== self::MSA_CONSUMER_TENANT
            && strcasecmp($tokenTenant, $configuredTenant) === 0;
    }

    /**
     * Whether a boolean claim is set. Microsoft documents `xms_edov` as a boolean but
     * does not always serialise it as one; the token is signed, so reading "true" or 1
     * as true trusts nothing Microsoft did not assert.
     */
    private static function claimIsTrue(mixed $value): bool
    {
        return $value === true || $value === 1 || (is_string($value) && in_array(strtolower($value), ['true', '1'], true));
    }

    protected function tenant(): string
    {
        $tenant = $this->config->get('tenant', 'common');

        return is_string($tenant) && $tenant !== '' ? $tenant : 'common';
    }
}
