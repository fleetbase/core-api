<?php

namespace Fleetbase\Services\OAuth;

use Fleetbase\Auth\OAuth\Exceptions\OAuthException;
use Fleetbase\Auth\OAuth\OAuthUserProfile;
use Fleetbase\Events\OAuthIdentityLinked;
use Fleetbase\Events\OAuthIdentityUnlinked;
use Fleetbase\Models\OAuthIdentity;
use Fleetbase\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Creates, resolves and removes the links between Fleetbase users and provider identities.
 *
 * The rule this class enforces, and the reason it is the only place identities are written:
 * a Fleetbase user is resolved from an OAuth identity ONLY through an existing
 * (provider, provider_user_id) row. Never by email. An email match is a hint used to tell a
 * user "you already have an account, sign in and link it" — it is never itself a credential.
 */
class OAuthIdentityService
{
    /**
     * Legacy per-provider columns on `users`, kept in sync on a best-effort basis so any
     * downstream package still reading them keeps working. These are not authoritative and
     * are never used to resolve a user.
     *
     * @var array<string, string>
     */
    private const LEGACY_COLUMNS = [
        'google'   => 'google_user_id',
        'apple'    => 'apple_user_id',
        'facebook' => 'facebook_user_id',
    ];

    /**
     * Find the identity row for a provider subject, if one exists.
     */
    public function findByProfile(OAuthUserProfile $profile): ?OAuthIdentity
    {
        return $this->findBySubject($profile->provider, $profile->providerUserId);
    }

    /**
     * Find the identity row for a provider subject, if one exists.
     */
    public function findBySubject(string $provider, string $providerUserId): ?OAuthIdentity
    {
        return OAuthIdentity::query()
            ->where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();
    }

    /**
     * The identity a user has linked for a provider, if any.
     */
    public function findBySubjectForUser(User $user, string $provider): ?OAuthIdentity
    {
        return OAuthIdentity::query()
            ->where('user_uuid', $user->uuid)
            ->where('provider', $provider)
            ->first();
    }

    /**
     * Resolve the Fleetbase user behind a provider subject.
     *
     * Returns null for a soft-deleted user: `User` applies the SoftDeletes global scope, so a
     * trashed account resolves to null here and the caller reports the same generic failure it
     * would for an unknown identity. Distinguishing the two would be an enumeration oracle.
     */
    public function findUserByProfile(OAuthUserProfile $profile): ?User
    {
        $identity = $this->findByProfile($profile);

        if (!$identity instanceof OAuthIdentity) {
            return null;
        }

        $user = $identity->user()->first();

        return $user instanceof User ? $user : null;
    }

    /**
     * Link a provider identity to a user.
     *
     * Idempotent for the same (user, provider subject) pair. If the subject is already linked
     * to a DIFFERENT user this throws rather than re-pointing the row — silently moving an
     * identity between accounts is an account-takeover primitive.
     *
     * @throws OAuthException identity_already_linked
     */
    public function link(User $user, OAuthUserProfile $profile): OAuthIdentity
    {
        $existing = $this->findByProfile($profile);

        if ($existing instanceof OAuthIdentity) {
            return $this->resolveExisting($existing, $user);
        }

        try {
            $identity = OAuthIdentity::create($profile->toIdentityAttributes() + [
                'user_uuid'     => $user->uuid,
                'last_login_at' => now(),
            ]);
        } catch (QueryException $e) {
            // Lost a race against a concurrent link of the same subject. Re-read rather than
            // matching a driver error code: MySQL reports 1062 and SQLite 19 for a unique
            // violation, and re-querying identifies WHICH constraint fired far more precisely
            // than either code does — a foreign-key failure leaves no subject row behind and
            // so is correctly rethrown here.
            $raced = $this->findByProfile($profile);

            if (!$raced instanceof OAuthIdentity) {
                throw $e;
            }

            return $this->resolveExisting($raced, $user);
        }

        $this->backfillLegacyColumn($user, $profile);

        event(new OAuthIdentityLinked($user, $identity));

        return $identity;
    }

    /**
     * Remove a provider identity from a user.
     *
     * Hard delete by design — see the migration. Callers are responsible for refusing to
     * remove a user's last remaining sign-in method; see isLastCredential().
     *
     * @return bool whether an identity was actually removed
     */
    public function unlink(User $user, string $provider): bool
    {
        $identity = OAuthIdentity::query()
            ->where('user_uuid', $user->uuid)
            ->where('provider', $provider)
            ->first();

        if (!$identity instanceof OAuthIdentity) {
            return false;
        }

        $identity->delete();

        $this->clearLegacyColumn($user, $provider);

        event(new OAuthIdentityUnlinked($user, $provider));

        return true;
    }

    /**
     * Whether removing this provider would leave the user with no way to sign in at all.
     */
    public function isLastCredential(User $user, string $provider): bool
    {
        if (!empty($user->password)) {
            return false;
        }

        return OAuthIdentity::query()
            ->where('user_uuid', $user->uuid)
            ->where('provider', '!=', $provider)
            ->count() === 0;
    }

    /**
     * All identities linked to a user.
     *
     * @return Collection the identities linked to the user
     */
    public function forUser(User $user): Collection
    {
        return OAuthIdentity::query()
            ->where('user_uuid', $user->uuid)
            ->orderBy('provider')
            ->get();
    }

    /**
     * Record a successful sign-in, and refresh the provider-reported detail.
     *
     * The address is informational only. It is updated so an admin looking at a linked
     * identity sees the current one; it is never used to resolve a user, which is why a
     * changed provider email cannot lock anyone out.
     */
    public function touchLogin(OAuthIdentity $identity, ?OAuthUserProfile $profile = null): void
    {
        $identity->last_login_at = now();

        if ($profile instanceof OAuthUserProfile) {
            $identity->provider_email = $profile->email;
            $identity->email_verified = $profile->emailVerified;
            $identity->meta           = $profile->meta;
        }

        $identity->save();
    }

    /**
     * @throws OAuthException identity_already_linked
     */
    private function resolveExisting(OAuthIdentity $identity, User $user): OAuthIdentity
    {
        if ($identity->user_uuid !== $user->uuid) {
            throw new OAuthException('identity_already_linked');
        }

        return $identity;
    }

    /**
     * Mirror the subject id onto the legacy users.<provider>_user_id column.
     *
     * Best effort only, and written with a targeted UPDATE rather than by mutating the caller's
     * model, so a failure cannot leave that model dirty with an unknown attribute. Those
     * columns are individually unique, so a value already claimed by another account must be
     * skipped — a legacy-column collision can never be allowed to fail a sign-in or a signup.
     */
    private function backfillLegacyColumn(User $user, OAuthUserProfile $profile): void
    {
        $column = self::LEGACY_COLUMNS[$profile->provider] ?? null;

        if ($column === null || !empty($user->{$column})) {
            return;
        }

        $this->writeLegacyColumn($user, $column, $profile->providerUserId);
    }

    private function clearLegacyColumn(User $user, string $provider): void
    {
        $column = self::LEGACY_COLUMNS[$provider] ?? null;

        if ($column === null || empty($user->{$column})) {
            return;
        }

        $this->writeLegacyColumn($user, $column, null);
    }

    private function writeLegacyColumn(User $user, string $column, ?string $value): void
    {
        try {
            User::query()->where('uuid', $user->uuid)->update([$column => $value]);
            $user->setAttribute($column, $value);
            $user->syncOriginalAttribute($column);
        } catch (\Throwable $e) {
            // Column absent on this schema, or the value is already claimed by another
            // account. Neither is fatal. Never log the subject id itself.
            Log::info('[OAuth] Skipped legacy column sync.', [
                'column' => $column,
                'user'   => $user->uuid,
            ]);
        }
    }
}
