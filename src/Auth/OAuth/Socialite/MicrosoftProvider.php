<?php

namespace Fleetbase\Auth\OAuth\Socialite;

use Fleetbase\Auth\OAuth\IdTokenVerifier;
use Fleetbase\Auth\OAuth\Socialite\Concerns\ServerSidePkce;
use Illuminate\Support\Arr;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\ProviderInterface;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * Microsoft identity platform (Entra ID) v2.0 endpoints.
 *
 * Socialite core ships no Microsoft provider, so this implements the protocol.
 *
 * The profile is taken from the verified `id_token` rather than from Microsoft
 * Graph, for two reasons: it needs no extra network round trip, and the claims that
 * decide whether the address may be trusted — `tid` and `xms_edov` — exist only in
 * the token. A Graph /me response would tell us the address but not whether the
 * directory owns the domain it belongs to.
 */
class MicrosoftProvider extends AbstractProvider implements ProviderInterface
{
    use ServerSidePkce;

    public const AUTHORITY_BASE = 'https://login.microsoftonline.com/';

    /**
     * The separating character for the requested scopes.
     *
     * @var string
     */
    protected $scopeSeparator = ' ';

    /**
     * @var array<int, string>
     */
    protected $scopes = ['openid', 'profile', 'email'];

    /**
     * Tenant id, domain, or one of the Microsoft aliases (`common`,
     * `organizations`, `consumers`).
     */
    protected string $tenant = 'common';

    protected ?IdTokenVerifier $idTokenVerifier = null;

    public function withTenant(string $tenant): static
    {
        $this->tenant = $tenant !== '' ? $tenant : 'common';

        return $this;
    }

    public function withIdTokenVerifier(IdTokenVerifier $verifier): static
    {
        $this->idTokenVerifier = $verifier;

        return $this;
    }

    public function tenant(): string
    {
        return $this->tenant;
    }

    protected function getAuthUrl($state)
    {
        return $this->buildAuthUrlFromBase($this->authority() . '/oauth2/v2.0/authorize', $state);
    }

    protected function getTokenUrl()
    {
        return $this->authority() . '/oauth2/v2.0/token';
    }

    /**
     * Resolve the profile from the verified id_token.
     *
     * @param string $token the access token, unused — the profile lives in the id_token
     *
     * @return array<string, mixed>
     */
    protected function getUserByToken($token)
    {
        return $this->verifier()->verify(
            $this->serverIdToken(),
            $this->authority() . '/discovery/v2.0/keys',
            $this->audience(),
            // Multi-tenant tokens are issued by the user's HOME tenant, so the issuer
            // contains a tenant uuid rather than the literal configured value and
            // cannot be compared for equality.
            fn (string $issuer): bool => str_starts_with($issuer, self::AUTHORITY_BASE),
            'oauth.jwks.microsoft.' . sha1($this->tenant)
        );
    }

    /**
     * @param array<string, mixed> $user
     */
    protected function mapUserToObject(array $user)
    {
        return (new SocialiteUser())->setRaw($user)->map([
            // `oid` is the immutable object id for the user within the tenant, and is
            // the conventional stable identifier when a person may sign in to several
            // Microsoft applications. `sub` is pairwise per application.
            'id'       => Arr::get($user, 'oid'),
            'nickname' => Arr::get($user, 'preferred_username'),
            'name'     => Arr::get($user, 'name'),
            'email'    => Arr::get($user, 'email') ?: Arr::get($user, 'preferred_username'),
            'avatar'   => null,
        ]);
    }

    protected function authority(): string
    {
        return self::AUTHORITY_BASE . rawurlencode($this->tenant);
    }

    /**
     * The expected `aud` claim: this application's client id, narrowed to a string.
     */
    protected function audience(): string
    {
        return is_scalar($this->clientId) ? (string) $this->clientId : '';
    }

    protected function verifier(): IdTokenVerifier
    {
        return $this->idTokenVerifier ?? new IdTokenVerifier();
    }
}
