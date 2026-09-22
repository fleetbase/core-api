<?php

namespace Fleetbase\Auth\OAuth\Socialite;

use Fleetbase\Auth\OAuth\IdTokenVerifier;
use Fleetbase\Auth\OAuth\Socialite\Concerns\ServerSidePkce;
use Illuminate\Support\Arr;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\ProviderInterface;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * Sign in with Apple.
 *
 * Socialite core ships no Apple provider, so this implements the protocol.
 *
 * Three things make Apple unlike the other providers:
 *
 *   1. There is no static client secret. One is minted per request as a short-lived
 *      ES256 JWT signed with the team's .p8 key — see AppleClientSecretFactory. The
 *      minted value arrives here as the ordinary $clientSecret.
 *   2. Requesting the `name` or `email` scope forces `response_mode=form_post`, so
 *      the callback arrives as a cross-site POST rather than a GET.
 *   3. The user's name is never in the id_token. Apple sends it once, in the POST
 *      body of the FIRST authorization only, and never again. AppleDriver lifts it
 *      from the callback payload.
 */
class AppleProvider extends AbstractProvider implements ProviderInterface
{
    use ServerSidePkce;

    public const ISSUER    = 'https://appleid.apple.com';
    public const JWKS_URL  = self::ISSUER . '/auth/keys';

    /**
     * The separating character for the requested scopes.
     *
     * @var string
     */
    protected $scopeSeparator = ' ';

    /**
     * @var array<int, string>
     */
    protected $scopes = ['name', 'email'];

    protected ?IdTokenVerifier $idTokenVerifier = null;

    public function withIdTokenVerifier(IdTokenVerifier $verifier): static
    {
        $this->idTokenVerifier = $verifier;

        return $this;
    }

    protected function getAuthUrl($state)
    {
        return $this->buildAuthUrlFromBase(self::ISSUER . '/auth/authorize', $state);
    }

    protected function getTokenUrl()
    {
        return self::ISSUER . '/auth/token';
    }

    /**
     * Resolve the profile from the verified id_token.
     *
     * @param string $token the access token, unused — Apple's is not usable for profile reads
     *
     * @return array<string, mixed>
     */
    protected function getUserByToken($token)
    {
        return $this->verifier()->verify(
            $this->serverIdToken(),
            self::JWKS_URL,
            $this->audience(),
            fn (string $issuer): bool => $issuer === self::ISSUER,
            'oauth.jwks.apple'
        );
    }

    /**
     * @param array<string, mixed> $user
     */
    protected function mapUserToObject(array $user)
    {
        return (new SocialiteUser())->setRaw($user)->map([
            'id'       => Arr::get($user, 'sub'),
            'nickname' => null,
            // Never present in the token; supplied by AppleDriver from the callback
            // body on first authorization only.
            'name'     => null,
            'email'    => Arr::get($user, 'email'),
            'avatar'   => null,
        ]);
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
