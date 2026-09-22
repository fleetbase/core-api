<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Auth\OAuth\Exceptions\OAuthException;
use Fleetbase\Auth\OAuth\Exceptions\OAuthStateException;
use Fleetbase\Auth\OAuth\Exceptions\UnknownOAuthProviderException;
use Fleetbase\Auth\OAuth\OAuthProviderRegistry;
use Fleetbase\Auth\OAuth\OAuthUserProfile;
use Fleetbase\Events\OAuthIdentityLinked;
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
 * ONLY through an existing (provider, provider_user_id) row. An email match is never
 * itself a credential: it either creates that row under the strict conditions in
 * autoLinkCandidate() — after which sign-in runs every check a password sign-in
 * does — or tells the user "you already have an account, sign in and link it".
 */
class OAuthController extends Controller
{
    /**
     * The only account types an identity is ever linked to automatically.
     */
    public const AUTO_LINK_USER_TYPES = ['admin', 'user'];

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
     * Returns only {id, label, icon} per provider and whether sign-ups are open: no client
     * ids, no secrets, no redirect URIs.
     */
    public function providers()
    {
        return response()->json([
            'providers'          => $this->registry->toDiscoveryArray(),
            // So the sign-up page can leave its provider buttons out when sign-ups are
            // closed, instead of letting someone find out after the round trip.
            'allow_registration' => $this->config->allowsRegistration(),
        ]);
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

        // A link handoff is only ever completed by completeLink(), which checks it
        // against the signed-in user. Refusing it here keeps the public endpoint from
        // being a way to redeem one without that check.
        if (($consumed['payload']['intent'] ?? null) === OAuthFlowService::INTENT_LINK) {
            return response()->error('This sign-in session is no longer valid.', 400, ['code' => 'invalid_exchange_code']);
        }

        // Re-checked at redemption, not just at redirect: an administrator may have
        // switched the provider off while this handshake was in flight.
        if (!$this->providerIsEnabled($profile->provider)) {
            return response()->error('That sign-in provider is not available.', 403, ['code' => 'provider_disabled']);
        }

        // Someone who pressed a provider button on the SIGN-UP page but already has an
        // account is signed in, not signed up; the console tells them which happened.
        $existingAccount = ($consumed['payload']['intent'] ?? null) === OAuthFlowService::INTENT_SIGNUP ? ['existing_account' => true] : [];

        $user = $this->identities->findUserByProfile($profile);

        if ($user instanceof User) {
            return $this->authenticate($user, $profile, $state, $existingAccount);
        }

        $match = $this->autoLinkCandidate($profile);

        if ($match instanceof User) {
            try {
                $this->identities->link($match, $profile, OAuthIdentityLinked::METHOD_AUTOMATIC);
            } catch (OAuthException $e) {
                // Another account claimed this provider subject a moment ago.
                return $this->registrationOutcome($profile);
            }

            // Linking does not skip anything authenticate() enforces — two-factor
            // included. The link alone signs no one in.
            return $this->authenticate($match, $profile, $state, [
                'linked'       => $profile->provider,
                'linked_label' => OAuth::providerLabel($profile->provider),
            ] + $existingAccount);
        }

        return $this->registrationOutcome($profile);
    }

    /**
     * The signed-in user's linked identities, and the providers they could link.
     */
    public function identities(Request $request)
    {
        return response()->json($this->identitiesPayload($request->user()));
    }

    /**
     * Begin linking a provider to the signed-in user.
     *
     * Returns the provider URL rather than redirecting: this route is behind
     * auth:sanctum, and a top-level browser navigation cannot carry the bearer token,
     * so the console fetches the URL and then navigates to it.
     *
     * The authorization row is stamped with this user. The identity is NOT linked at
     * the callback — see completeLink() for why.
     */
    public function link(Request $request, string $provider)
    {
        $user = $request->user();

        if ($limited = $this->rateLimit('link:' . $user->uuid, 10)) {
            return $limited;
        }

        if ($this->identities->findBySubjectForUser($user, $provider) !== null) {
            return response()->error('That provider is already linked to your account.', 409, ['code' => 'already_linked']);
        }

        try {
            $url = $this->flow->startAuthorization(
                $provider,
                OAuthFlowService::INTENT_LINK,
                '/account/auth',
                $request->ip(),
                (string) $user->uuid
            );
        } catch (UnknownOAuthProviderException $e) {
            return response()->error('Unknown sign-in provider.', 404, ['code' => 'unknown_provider']);
        } catch (OAuthException $e) {
            return response()->error('That sign-in provider is not available.', 403, ['code' => $e->getMessage()]);
        }

        return response()->json(['redirect_url' => $url]);
    }

    /**
     * Finish linking, from the signed-in console.
     *
     * The identity is linked here, behind authentication, and only if the signed-in
     * user is the one who started the link. Linking at the provider callback instead
     * would allow account-linking CSRF: an attacker starts a link on their own account
     * and sends the victim the provider URL; the victim's provider identity is attached
     * to the attacker's account, and the victim's next "Sign in with <provider>" lands
     * them in the attacker's account. Here, the victim's browser would complete the link
     * as the victim, the user check fails, and nothing is linked.
     */
    public function completeLink(OAuthExchangeRequest $request)
    {
        $user = $request->user();

        if ($limited = $this->rateLimit('link:' . $user->uuid, 10)) {
            return $limited;
        }

        try {
            $consumed = $this->states->consume(OAuthState::PURPOSE_HANDOFF, $request->code(), $request->ip());
        } catch (OAuthStateException $e) {
            return response()->error('This link request is no longer valid.', 400, ['code' => 'invalid_exchange_code']);
        }

        /** @var OAuthState $state */
        $state   = $consumed['state'];
        $profile = $this->flow->profileFromPayload($consumed['payload']);

        $isLink       = ($consumed['payload']['intent'] ?? null) === OAuthFlowService::INTENT_LINK;
        $startedByYou = is_string($state->user_uuid) && hash_equals($state->user_uuid, (string) $user->uuid);

        if (!$isLink || !$startedByYou || $profile->providerUserId === '') {
            // Deliberately the same response as an expired code: telling the caller the
            // link belongs to someone else would confirm the attack worked as far as it did.
            return response()->error('This link request is no longer valid.', 400, ['code' => 'invalid_exchange_code']);
        }

        if (!$this->providerIsEnabled($profile->provider)) {
            return response()->error('That sign-in provider is not available.', 403, ['code' => 'provider_disabled']);
        }

        try {
            $this->identities->link($user, $profile);
        } catch (OAuthException $e) {
            // The provider account is already linked to a different Fleetbase user.
            return response()->error('That account is already linked to another user.', 409, ['code' => $e->getMessage()]);
        }

        return response()->json($this->identitiesPayload($user));
    }

    /**
     * Remove a linked provider from the signed-in user.
     *
     * Refused when it would leave the account with no way to sign in at all.
     */
    public function unlink(Request $request, string $provider)
    {
        $user = $request->user();

        if ($this->identities->findBySubjectForUser($user, $provider) === null) {
            return response()->error('That provider is not linked to your account.', 404, ['code' => 'not_linked']);
        }

        if ($this->identities->isLastCredential($user, $provider)) {
            return response()->error(
                'Set a password or link another provider before removing this one — otherwise you could not sign in.',
                409,
                ['code' => 'last_credential']
            );
        }

        $this->identities->unlink($user, $provider);

        return response()->json($this->identitiesPayload($user));
    }

    /**
     * @return array<string, mixed>
     */
    protected function identitiesPayload(User $user): array
    {
        $labels = [];

        foreach ($this->registry->definitions() as $definition) {
            $labels[$definition['id']] = ['label' => $definition['label'], 'icon' => $definition['icon']];
        }

        $identities = [];

        foreach ($this->identities->forUser($user) as $identity) {
            $identities[] = [
                'provider'       => $identity->provider,
                'label'          => $labels[$identity->provider]['label'] ?? ucfirst((string) $identity->provider),
                'icon'           => $labels[$identity->provider]['icon'] ?? null,
                'provider_email' => $identity->provider_email,
                'email_verified' => (bool) $identity->email_verified,
                'linked_at'      => $identity->created_at?->toIso8601String(),
                'last_login_at'  => $identity->last_login_at?->toIso8601String(),
            ];
        }

        $linked = array_column($identities, 'provider');

        return [
            'identities'   => $identities,
            'has_password' => !empty($user->password),
            // Enabled providers not yet linked — what the account page can offer.
            'available'    => array_values(array_filter(
                $this->registry->toDiscoveryArray(),
                fn (array $provider): bool => !in_array($provider['id'], $linked, true)
            )),
        ];
    }

    /**
     * An identity we already know: run the same gates password login runs.
     */
    /**
     * @param array<string, mixed> $notices what the console should tell the user about how
     *                                      they got here (`linked`, `existing_account`), added
     *                                      to whichever response sign-in ends with
     */
    protected function authenticate(User $user, OAuthUserProfile $profile, OAuthState $state, array $notices = [])
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
            ] + $notices);
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

        return response()->json(['token' => $token->plainTextToken, 'type' => $user->getType()] + $notices);
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
     * The existing account an unlinked identity may be linked to automatically, if any.
     *
     * Every condition is required:
     *
     *   - the administrator has not switched automatic linking off;
     *   - the provider vouched for the address (each driver decides that strictly —
     *     Microsoft, for one, only for domain-verified addresses) and it is not an
     *     Apple relay alias, which can never match a stored address;
     *   - exactly one account holds that address;
     *   - it is a console account (`admin` or `user`) — never a customer, contact or
     *     driver, whose sign-in is governed elsewhere;
     *   - the account's OWN email is confirmed. Without this, anyone could sign up
     *     with someone else's address, never confirm it, and wait for the real owner
     *     to sign in with a provider — landing the owner in an account whose
     *     password the attacker already knows;
     *   - no identity from this provider is linked to it yet: one provider account
     *     per user, and a different one already linked is not replaced silently.
     */
    protected function autoLinkCandidate(OAuthUserProfile $profile): ?User
    {
        if (!$this->config->autoLinksVerifiedEmail() || !$profile->hasVerifiedEmail() || $profile->meta('private_relay') === true) {
            return null;
        }

        $matches = User::where('email', $profile->email)->limit(2)->get();

        if ($matches->count() !== 1) {
            return null;
        }

        /** @var User $user */
        $user = $matches->first();

        if (!in_array($user->type, self::AUTO_LINK_USER_TYPES, true) || empty($user->email_verified_at)) {
            return null;
        }

        if ($this->identities->findBySubjectForUser($user, $profile->provider) !== null) {
            return null;
        }

        return $user;
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
