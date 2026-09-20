<?php

namespace Fleetbase\Support;

use Fleetbase\Auth\OAuth\Exceptions\OAuthException;
use Fleetbase\Auth\OAuth\Exceptions\OAuthStateException;
use Fleetbase\Auth\OAuth\OAuthProviderRegistry;
use Fleetbase\Auth\OAuth\OAuthUserProfile;
use Fleetbase\Models\OAuthIdentity;
use Fleetbase\Models\OAuthState;
use Fleetbase\Models\User;
use Fleetbase\Services\OAuth\OAuthConfigRepository;
use Fleetbase\Services\OAuth\OAuthIdentityService;
use Fleetbase\Services\OAuth\OAuthStateService;
use Illuminate\Support\Facades\Log;

/**
 * The stable surface downstream packages call for OAuth.
 *
 * Deliberately shaped like Fleetbase\Support\TwoFactorAuth and
 * Fleetbase\Support\PlatformApi — a static facade over container-resolved services —
 * because that is the established convention for "things Fleetbase Cloud internals
 * and extensions are allowed to depend on".
 *
 * The registration intent is the whole point of this class. OAuth never creates a
 * user or a company: on an unrecognised identity it issues a short-lived, single-use
 * intent, and whichever signup implementation is installed (core-api's OnboardController
 * on self-hosted, Fleetbase Cloud's InternalsOnboardController on Cloud) redeems it at
 * the end of its own unchanged flow. That keeps one registration pipeline rather than
 * a second one that would drift.
 *
 * Every method here is null-safe so a caller never needs a guard around it.
 */
class OAuth
{
    /**
     * Whether OAuth is switched on for this installation.
     */
    public static function isEnabled(): bool
    {
        return static::config()->isEnabled();
    }

    /**
     * Whether an unrecognised provider identity may start a signup.
     */
    public static function allowsRegistration(): bool
    {
        return static::config()->allowsRegistration();
    }

    /**
     * Providers the console should offer, as [{id, label, icon}].
     *
     * @return array<int, array{id: string, label: string, icon: string}>
     */
    public static function enabledProviders(): array
    {
        return static::registry()->toDiscoveryArray();
    }

    // -----------------------------------------------------------------------
    // Registration intent — the entire API a signup implementation needs
    // -----------------------------------------------------------------------

    /**
     * Issue an intent proving a verified provider identity.
     *
     * Returned once and never stored: only its sha256 is persisted.
     */
    public static function issueRegistrationIntent(OAuthUserProfile $profile): string
    {
        return static::states()->issue(
            OAuthState::PURPOSE_REGISTRATION_INTENT,
            ['profile' => $profile->jsonSerialize()],
            static::config()->ttl('registration_intent', 900),
            $profile->provider,
            'signup'
        );
    }

    /**
     * Read an intent without consuming it.
     *
     * Non-consuming by design: validation rules call this on every request, and
     * burning the intent there would destroy the user's sign-in session whenever any
     * unrelated field failed validation.
     *
     * @return array{provider: string, provider_user_id: string, email: ?string, email_verified: bool, name: ?string}|null
     */
    public static function inspectRegistrationIntent(?string $token): ?array
    {
        $profile = static::profileFromIntent($token);

        if (!$profile instanceof OAuthUserProfile) {
            return null;
        }

        return [
            'provider'         => $profile->provider,
            'provider_user_id' => $profile->providerUserId,
            'email'            => $profile->email,
            'email_verified'   => $profile->emailVerified,
            'name'             => $profile->name,
        ];
    }

    /**
     * Null-safe convenience for validation rules.
     */
    public static function isValidRegistrationIntent(?string $token): bool
    {
        return static::inspectRegistrationIntent($token) !== null;
    }

    /**
     * Consume an intent and link the identity it proves to a freshly created user.
     *
     * Call this at the END of an otherwise unchanged signup, immediately before the
     * AccountCreated event, so the account is built exactly as a password signup
     * builds it and this only attaches the provider identity.
     *
     * Returns null rather than throwing on every failure path. A signup that has
     * already created a user and a company must not be failed by a lost race on the
     * identity row — the account is valid, it simply has no linked provider yet.
     */
    public static function redeemRegistrationIntent(?string $token, User $user): ?OAuthIdentity
    {
        if ($token === null || $token === '') {
            return null;
        }

        try {
            $consumed = static::states()->consume(OAuthState::PURPOSE_REGISTRATION_INTENT, $token);
        } catch (OAuthStateException $e) {
            return null;
        }

        $profile = static::profileFromPayload($consumed['payload']);

        if (!$profile instanceof OAuthUserProfile) {
            return null;
        }

        try {
            $identity = static::identities()->link($user, $profile);
        } catch (OAuthException $e) {
            // identity_already_linked: another account claimed this provider subject
            // between the intent being issued and redeemed. The signup itself stands.
            Log::warning('[OAuth] Could not link the identity for a new account.', [
                'provider' => $profile->provider,
                'user'     => $user->uuid,
                'reason'   => $e->getMessage(),
            ]);

            return null;
        }

        static::states()->attachUser($consumed['state'], (string) $user->uuid);
        static::promoteVerifiedEmail($user, $profile);

        return $identity;
    }

    /**
     * Mark the account's email verified when the provider vouched for that exact
     * address.
     *
     * This is what lets an OAuth signup skip the emailed verification code. It is
     * deliberately narrow: the provider must have asserted the address as verified,
     * and it must be the address the account was actually created with. A user who
     * types a different email during signup than the one the provider returned still
     * has to verify it the normal way.
     */
    protected static function promoteVerifiedEmail(User $user, OAuthUserProfile $profile): void
    {
        if (!$profile->hasVerifiedEmail() || !empty($user->email_verified_at)) {
            return;
        }

        if (strcasecmp((string) $profile->email, (string) $user->email) !== 0) {
            return;
        }

        $user->email_verified_at = now();
        $user->save();
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected static function profileFromPayload(array $payload): ?OAuthUserProfile
    {
        $profile = $payload['profile'] ?? null;

        if (!is_array($profile)) {
            return null;
        }

        $profile = OAuthUserProfile::fromArray($profile);

        return $profile->providerUserId === '' ? null : $profile;
    }

    protected static function profileFromIntent(?string $token): ?OAuthUserProfile
    {
        if ($token === null || $token === '') {
            return null;
        }

        $payload = static::states()->inspect(OAuthState::PURPOSE_REGISTRATION_INTENT, $token);

        return is_array($payload) ? static::profileFromPayload($payload) : null;
    }

    protected static function states(): OAuthStateService
    {
        return app(OAuthStateService::class);
    }

    protected static function identities(): OAuthIdentityService
    {
        return app(OAuthIdentityService::class);
    }

    protected static function config(): OAuthConfigRepository
    {
        return app(OAuthConfigRepository::class);
    }

    protected static function registry(): OAuthProviderRegistry
    {
        return app(OAuthProviderRegistry::class);
    }
}
