<?php

namespace Fleetbase\Support;

use Fleetbase\Models\Company;
use Fleetbase\Models\ReviewAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * App Review access: how Apple and Google reviewers sign in to an app built on Fleetbase.
 *
 * Fleetbase's apps sign people in with a texted code, which a reviewer cannot receive, and
 * Apple rejects apps its reviewers can't sign in to. An extension registers the things its
 * app signs reviewers in to (a Storefront store or network, an organization's Navigator
 * app) as a type, with a fixed review number from the range reserved for fiction. The
 * owner turns access on for one of them in the Console and gives the app store that number
 * and the code generated for it. Nothing is ever sent to a fictional number, and a code
 * only works for the subject that generated it.
 *
 *     AppReview::register('storefront-store', [
 *         'model'      => Store::class,
 *         'phone'      => '+12025550143',
 *         'permission' => 'storefront manage-app-review store',
 *     ]);
 *
 * Self-hosted installs can still allow review accounts instance-wide from env config; see
 * configuredAccountMatches().
 */
class AppReview
{
    /**
     * Digits in a generated code. Fleetbase's apps take six-digit codes.
     */
    public const CODE_LENGTH = 6;

    /**
     * North American numbers with the 555 exchange and a line from 0100 to 0199 are reserved
     * for fiction: they can never reach a real person.
     */
    public const FICTIONAL_PHONE_PATTERN = '/^\+1[2-9]\d{2}55501\d{2}$/';

    /**
     * @var array<string, array{type: string, model: class-string<Model>, phone: string, name: string, permission: ?string, view_permission: ?string, resolve: ?callable}>
     */
    protected static array $types = [];

    /**
     * Register a type of subject an app signs reviewers in to.
     *
     * - `model`: the subject's model class (required)
     * - `phone`: the review number, E.164 from the fictional range (required). Types whose
     *   accounts are the same kind of user may share one (a store and a network); types
     *   whose users differ (customers, drivers) need their own, since users are found by phone.
     * - `name`: what to call the app in messages, e.g. "Storefront"
     * - `permission`: the permission needed to change access; `view_permission` to read it
     * - `resolve`: callable(string $id, ?string $companyUuid): ?Model finding the subject a
     *   Console user may manage; by default the model by uuid or public_id in the company
     */
    public static function register(string $type, array $definition): void
    {
        $model = $definition['model'] ?? null;
        $phone = static::normalizePhone($definition['phone'] ?? null);

        if (!is_string($model) || !class_exists($model)) {
            throw new \InvalidArgumentException("App Review type [{$type}] needs a model class.");
        }

        if (!static::isFictionalPhone($phone)) {
            throw new \InvalidArgumentException("App Review type [{$type}] needs a review number from the fictional range +1 NXX 555 0100-0199.");
        }

        static::$types[$type] = [
            'type'            => $type,
            'model'           => $model,
            'phone'           => $phone,
            'name'            => $definition['name'] ?? $type,
            'permission'      => $definition['permission'] ?? null,
            'view_permission' => $definition['view_permission'] ?? null,
            'resolve'         => $definition['resolve'] ?? null,
        ];
    }

    public static function forget(string $type): void
    {
        unset(static::$types[$type]);
    }

    public static function types(): array
    {
        return static::$types;
    }

    public static function definition(string $type): ?array
    {
        return static::$types[$type] ?? null;
    }

    /**
     * The registered type of a subject: the first type whose model it is.
     */
    public static function typeOf(?Model $subject): ?string
    {
        if (!$subject) {
            return null;
        }

        foreach (static::$types as $type => $definition) {
            if ($subject instanceof $definition['model']) {
                return $type;
            }
        }

        return null;
    }

    public static function phoneFor(string $type): ?string
    {
        return static::$types[$type]['phone'] ?? null;
    }

    /**
     * Whether a number is in the range reserved for fiction, however it is formatted.
     */
    public static function isFictionalPhone(?string $phone): bool
    {
        $phone = static::normalizePhone($phone);

        return $phone !== null && preg_match(static::FICTIONAL_PHONE_PATTERN, $phone) === 1;
    }

    /**
     * Whether an identity is a registered review number: the given type's, or any type's.
     */
    public static function isReviewPhone(?string $identity, ?string $type = null): bool
    {
        $phone = static::normalizePhone($identity);
        if ($phone === null) {
            return false;
        }

        if ($type !== null) {
            return static::phoneFor($type) === $phone;
        }

        foreach (static::$types as $definition) {
            if ($definition['phone'] === $phone) {
                return true;
            }
        }

        return false;
    }

    /**
     * `+12025550143` as `+1 202 555 0143`.
     */
    public static function formatPhone(?string $phone): ?string
    {
        $phone = static::normalizePhone($phone);
        if ($phone === null || !preg_match('/^\+1(\d{3})(\d{3})(\d{4})$/', $phone, $parts)) {
            return $phone;
        }

        return "+1 {$parts[1]} {$parts[2]} {$parts[3]}";
    }

    /**
     * The subject of a type a Console user in this company may manage, by uuid or public id.
     */
    public static function resolveSubject(string $type, string $id, ?string $companyUuid): ?Model
    {
        $definition = static::definition($type);
        if (!$definition) {
            return null;
        }

        if (is_callable($definition['resolve'])) {
            return ($definition['resolve'])($id, $companyUuid);
        }

        $model = $definition['model'];
        $query = $model::query()->where(function ($query) use ($id, $model) {
            $query->where('uuid', $id);
            if (static::hasColumn($model, 'public_id')) {
                $query->orWhere('public_id', $id);
            }
        });

        if (is_a($model, Company::class, true)) {
            return $companyUuid ? $query->where('uuid', $companyUuid)->first() : null;
        }

        return $companyUuid ? $query->where('company_uuid', $companyUuid)->first() : null;
    }

    /**
     * The App Review access record of a subject, if it has one.
     */
    public static function accessFor(?Model $subject): ?ReviewAccess
    {
        $type = static::typeOf($subject);
        if (!$type || blank($subject->uuid)) {
            return null;
        }

        try {
            return ReviewAccess::where('subject_type', $type)->where('subject_uuid', $subject->uuid)->first();
        } catch (\Throwable $e) {
            // Before the migration has run there is no access, not an error.
            return null;
        }
    }

    public static function isEnabledFor(?Model $subject): bool
    {
        return (bool) static::accessFor($subject)?->isUsable();
    }

    /**
     * Whether a code signs the subject type's review number in to this subject: its access is
     * on and the code is the one it generated. Another subject's code never matches.
     */
    public static function acceptsCode(?Model $subject, ?string $identity, mixed $code): bool
    {
        $type = static::typeOf($subject);
        if (!$type || !static::isReviewPhone($identity, $type) || blank($code) || !is_scalar($code)) {
            return false;
        }

        $access = static::accessFor($subject);
        if (!$access || !$access->isUsable() || !$access->checkCode((string) $code)) {
            return false;
        }

        Log::info('[Fleetbase] App Review sign-in accepted.', [
            'type'    => $type,
            'subject' => $subject->public_id ?? $subject->uuid,
        ]);

        return true;
    }

    /**
     * Change a subject's access. Turning it on without a code generates one, so it is never
     * on with nothing to sign in with; `regenerate` replaces the code. Turning it off keeps
     * the code, so turning it back on restores the same one.
     *
     * @return array{0: ReviewAccess, 1: ?string} the access and the new plain code, if one was generated
     */
    public static function update(Model $subject, ?bool $enabled, bool $regenerate = false, ?string $updatedByUuid = null): array
    {
        $type = static::typeOf($subject);
        if (!$type) {
            throw new \InvalidArgumentException('No App Review type is registered for ' . get_class($subject) . '.');
        }

        $access = static::accessFor($subject) ?? new ReviewAccess([
            'company_uuid' => $subject instanceof Company ? $subject->uuid : $subject->company_uuid,
            'subject_type' => $type,
            'subject_uuid' => $subject->uuid,
            'enabled'      => false,
        ]);

        if ($enabled !== null) {
            if ($enabled && !$access->enabled) {
                $access->enabled_at = now();
            }
            $access->enabled = $enabled;
        }

        $code = null;
        if ($regenerate || ($access->enabled && !$access->hasCode())) {
            $code = static::generateCode();
            $access->setPlainCode($code);
        }

        $access->updated_by_uuid = $updatedByUuid;
        $access->save();

        return [$access, $code];
    }

    /**
     * What the Console and the API show about a subject's access. Never the code or its hash;
     * a caller adds `code` only to the response that generated it.
     */
    public static function status(Model $subject): array
    {
        $type   = static::typeOf($subject);
        $access = static::accessFor($subject);
        $phone  = $type ? static::phoneFor($type) : null;

        return [
            'type'              => $type,
            'enabled'           => (bool) $access?->enabled,
            'phone'             => $phone,
            'phone_display'     => static::formatPhone($phone),
            'has_code'          => (bool) $access?->hasCode(),
            'code_generated_at' => $access?->code_generated_at?->toIso8601String(),
            'enabled_at'        => $access?->enabled_at?->toIso8601String(),
        ];
    }

    public static function generateCode(): string
    {
        return str_pad((string) random_int(0, (10 ** static::CODE_LENGTH) - 1), static::CODE_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * Whether an identity is listed as an instance-wide review account in env config (for
     * self-hosted installs) and a code is configured for those accounts.
     *
     * @param string $codeKey     config key holding the code
     * @param string $accountsKey config key holding the listed identities
     */
    public static function isConfiguredAccount(string $codeKey, string $accountsKey, ?string $identity): bool
    {
        $configuredCode = config($codeKey);

        // `=== null || === ''` rather than blank(): a configured code of "0" is still a code.
        if ($configuredCode === null || $configuredCode === '' || $identity === null || trim($identity) === '') {
            return false;
        }

        $accounts = array_map(
            static fn ($account) => strtolower(trim((string) $account)),
            (array) config($accountsKey, [])
        );

        return in_array(strtolower(trim($identity)), $accounts, true);
    }

    /**
     * Whether a code is the env-configured code for a listed review account. Only listed
     * identities are accepted, so knowing the code is not enough to sign in as anyone else,
     * and the comparison is constant-time.
     */
    public static function configuredAccountMatches(string $codeKey, string $accountsKey, ?string $identity, mixed $code, string $channel = 'app'): bool
    {
        if ($code === null || $code === '' || !is_scalar($code) || !static::isConfiguredAccount($codeKey, $accountsKey, $identity)) {
            return false;
        }

        if (!hash_equals((string) config($codeKey), (string) $code)) {
            return false;
        }

        Log::warning('[Fleetbase] Verification bypass accepted for a review account.', [
            'channel'  => $channel,
            'identity' => $identity,
        ]);

        return true;
    }

    /**
     * Digits with a leading `+`, or null for nothing.
     */
    protected static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        return $digits === '' ? null : '+' . $digits;
    }

    protected static function hasColumn(string $model, string $column): bool
    {
        try {
            $instance = new $model();

            return $instance->getConnection()->getSchemaBuilder()->hasColumn($instance->getTable(), $column);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
