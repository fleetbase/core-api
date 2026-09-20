<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Auth\OAuth\Exceptions\OAuthException;
use Fleetbase\Auth\OAuth\Exceptions\OAuthStateException;
use Fleetbase\Auth\OAuth\Exceptions\UnknownOAuthProviderException;
use Fleetbase\Auth\OAuth\OAuthProviderRegistry;
use Fleetbase\Auth\OAuth\OAuthUserProfile;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Requests\Internal\OAuthExchangeRequest;
use Fleetbase\Http\Requests\Internal\OAuthRedirectRequest;
use Fleetbase\Models\OAuthState;
use Fleetbase\Models\User;
use Fleetbase\Services\OAuth\OAuthConfigRepository;
use Fleetbase\Services\OAuth\OAuthFlowService;
use Fleetbase\Services\OAuth\OAuthIdentityService;
use Fleetbase\Services\OAuth\OAuthStateService;
use Fleetbase\Support\OAuth;
use Fleetbase\Support\TwoFactorAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The public OAuth surface: discovery, the two handshake legs, and the exchange
 * that turns a completed handshake into a Fleetbase session.
 *
 * The rule the exchange enforces, and the reason this controller exists rather than
 * folding into AuthController: a Fleetbase user is resolved from an OAuth identity
 * ONLY through an existing (provider, provider_user_id) row. Never by email address.
 * An email match is used to tell a user "you already have an account, sign in and
 * link it" — it is never itself a credential.
 */
class OAuthController extends Controller
{
    public function __construct(
        protected OAuthProviderRegistry $registry,
        protected OAuthFlowService $flow,
        protected OAuthStateService $states,
        protected OAuthIdentityService $identities,
        protected OAuthConfigRepository $config,
    ) {
    }

    /**
     * Providers the console should render sign-in buttons for.
     *
     * Unauthenticated by design — the console needs this before a session exists.
     * Returns only {id, label, icon}: no client ids, no secrets, no redirect URIs.
     */
    public function providers()
    {
        return response()->json(['providers' => $this->registry->toDiscoveryArray()]);
    }

    /**
     * Begin the handshake: 302 to the provider.
     */
    public function redirect(OAuthRedirectRequest $request, string $provider)
    {
        if ($limited = $this->rateLimit('redirect:' . $request->ip(), 30)) {
            return $limited;
        }

        try {
            $url = $this->flow->startAuthorization(
                $provider,
                $request->intent(),
                $request->returnTo(),
                $request->ip()
            );
        } catch (UnknownOAuthProviderException $e) {
            return response()->error('Unknown sign-in provider.', 404, ['code' => 'unknown_provider']);
        } catch (OAuthException $e) {
            return response()->error('That sign-in provider is not available.', 403, ['code' => $e->getMessage()]);
        }

        return redirect()->away($url);
    }

    /**
     * The provider lands here.
     *
     * Accepts GET and POST: Apple requires response_mode=form_post whenever the
     * name/email scopes are requested, so its callback arrives as a cross-site POST.
     *
     * Always redirects to the console — a failure becomes `#error=<code>` there
     * rather than an API error page, because this is a top-level browser navigation.
     */
    public function callback(Request $request, string $provider)
    {
        return redirect()->away($this->flow->handleCallback($provider, $request));
    }

    /**
     * Trade a one-time handoff code for one of four outcomes.
     *
     * Mirrors AuthController::login's gate order exactly, so an OAuth sign-in can
     * never reach a state a password sign-in could not.
     */
    public function exchange(OAuthExchangeRequest $request)
    {
        if ($limited = $this->rateLimit('exchange:' . $request->ip(), 20)) {
            return $limited;
        }

        try {
            $consumed = $this->states->consume(OAuthState::PURPOSE_HANDOFF, $request->code(), $request->ip());
        } catch (OAuthStateException $e) {
            // Unknown, expired, already redeemed, or presented for another purpose —
            // deliberately indistinguishable from one another.
            return response()->error('This sign-in session is no longer valid.', 400, ['code' => 'invalid_exchange_code']);
        }

        /** @var OAuthState $state */
        $state   = $consumed['state'];
        $profile = $this->flow->profileFromPayload($consumed['payload']);

        if ($profile->providerUserId === '') {
            return response()->error('This sign-in session is no longer valid.', 400, ['code' => 'invalid_exchange_code']);
        }

        // Re-checked at redemption, not just at redirect: an administrator may have
        // switched the provider off while this handshake was in flight.
        if (!$this->providerIsEnabled($profile->provider)) {
            return response()->error('That sign-in provider is not available.', 403, ['code' => 'provider_disabled']);
        }

        $user = $this->identities->findUserByProfile($profile);

        if ($user instanceof User) {
            return $this->authenticate($user, $profile, $state);
        }

        return $this->registrationOutcome($profile);
    }

    /**
     * An identity we already know: run the same gates password login runs.
     */
    protected function authenticate(User $user, OAuthUserProfile $profile, OAuthState $state)
    {
        // AuthController::login:86-88
        if ($user->type === 'customer') {
            return response()->error('Customer accounts must sign in through the customer portal.', 403, ['code' => 'customer_login_not_allowed']);
        }

        // AuthController::login:105-112 — OAuth does not exempt anyone from 2FA.
        if (TwoFactorAuth::isEnabled($user)) {
            return response()->json([
                'twoFaSession' => TwoFactorAuth::start($user),
                'isEnabled'    => true,
            ]);
        }

        // AuthController::login:114-116. An OAuth sign-in does not by itself verify a
        // Fleetbase account; promoting email_verified_at happens only when a verified
        // provider address matches, and that is part of linking, not of signing in.
        if ($user->isNotVerified() && $user->isNotAdmin()) {
            return response()->error('User is not verified.', 400, ['code' => 'not_verified']);
        }

        $identity = $this->identities->findByProfile($profile);

        if ($identity !== null) {
            $this->identities->touchLogin($identity, $profile);
        }

        $this->states->attachUser($state, (string) $user->uuid);

        $user->updateLastLogin();
        $token = $user->createToken($user->uuid);

        return response()->json(['token' => $token->plainTextToken, 'type' => $user->getType()]);
    }

    /**
     * An identity we do not know: either start a signup or tell the user to link.
     */
    protected function registrationOutcome(OAuthUserProfile $profile)
    {
        if (!$this->config->allowsRegistration()) {
            return response()->error('Sign-ups are not available.', 403, ['code' => 'registration_disabled']);
        }

        if ($this->collidesWithExistingAccount($profile)) {
            // The address belongs to an existing Fleetbase account. We do NOT sign
            // them in — that would be account takeover by email. They must sign in
            // with an existing method and link this provider from their account.
            return response()->error(
                'An account with this email already exists. Sign in and link this provider from your account settings.',
                409,
                ['code' => 'link_required']
            );
        }

        // Issued through the facade so the intent's shape lives in exactly one place —
        // the same place Fleetbase Cloud internals redeems it from.
        $intent = OAuth::issueRegistrationIntent($profile);

        return response()->json([
            'status'  => 'registration_required',
            'intent'  => $intent,
            'prefill' => [
                'name'           => $profile->name,
                'email'          => $profile->email,
                'email_verified' => $profile->emailVerified,
            ],
        ]);
    }

    /**
     * Whether this identity's address already belongs to a Fleetbase account.
     *
     * Only asked when the provider VOUCHED for the address. An unverified address is
     * not evidence of anything, and letting it produce `link_required` would leak
     * whether an arbitrary email has a Fleetbase account.
     *
     * Apple private-relay aliases are excluded: they are unique per application, so
     * one can never match an address Fleetbase already holds.
     */
    protected function collidesWithExistingAccount(OAuthUserProfile $profile): bool
    {
        if (!$profile->hasVerifiedEmail() || $profile->meta('private_relay') === true) {
            return false;
        }

        return User::where('email', $profile->email)->exists();
    }

    protected function providerIsEnabled(string $provider): bool
    {
        try {
            return $this->registry->driver($provider)->isEnabled();
        } catch (UnknownOAuthProviderException $e) {
            return false;
        }
    }

    /**
     * An explicit limiter on top of the route's ThrottleRequests.
     *
     * Necessary because Fleetbase\Http\Middleware\ThrottleRequests overwrites
     * maxAttempts/decayMinutes from config (ThrottleRequests.php:55-59), so route
     * parameters are silently ignored and the effective ceiling is the global
     * api.throttle limit. This is also independent of api.throttle.enabled, which
     * can switch that middleware off entirely.
     */
    protected function rateLimit(string $key, int $maxAttempts)
    {
        $limiterKey = 'oauth:' . sha1($key);

        if (RateLimiter::tooManyAttempts($limiterKey, $maxAttempts)) {
            return response()->error('Too many attempts. Please try again shortly.', 429, ['code' => 'rate_limited']);
        }

        RateLimiter::hit($limiterKey, 60);

        return null;
    }
}
