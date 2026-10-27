<?php

namespace Fleetbase\Auth\OAuthServer;

/**
 * The scopes an app can ask for when it signs someone in with Fleetbase.
 *
 * Core registers the identity and organization scopes; an extension registers the scopes for the
 * data it owns from its service provider's boot(), e.g. Storefront:
 *
 *     OAuthScopes::register('storefront:read', [
 *         'description'  => "See {organization}'s storefronts and networks, with their public keys",
 *         'organization' => true,
 *     ]);
 *
 * - description: the consent-screen line, in plain words. `{organization}` is replaced with the
 *   organization the user picked.
 * - organization: true when the scope reads or changes an organization's data. A request with
 *   any such scope makes the user pick an organization they administer, and binds the tokens to it.
 * - review_access_types: App Review subject types (Fleetbase\Support\AppReview) this scope lets
 *   the app turn on or off through /oauth/v1/review-access/{type}/{id}.
 */
class OAuthScopes
{
    /**
     * @var array<string, array{description: string, organization: bool, review_access_types: array<int, string>}>
     */
    protected static array $scopes = [];

    /**
     * Register (or replace) a scope.
     */
    public static function register(string $scope, array $definition): void
    {
        if (!preg_match('/^[a-z][a-z0-9_.\-]*(:[a-z0-9_.\-]+)*$/', $scope)) {
            throw new \InvalidArgumentException("Invalid OAuth scope name [{$scope}].");
        }

        static::$scopes[$scope] = [
            'description'         => (string) ($definition['description'] ?? $scope),
            'organization'        => (bool) ($definition['organization'] ?? false),
            'review_access_types' => array_values($definition['review_access_types'] ?? []),
        ];
    }

    /**
     * The scopes core registers. Called from CoreServiceProvider::boot().
     */
    public static function registerDefaults(): void
    {
        static::register('openid', ['description' => 'Sign you in with your Fleetbase account']);
        static::register('profile', ['description' => 'See your name and profile photo']);
        static::register('email', ['description' => 'See your email address']);
        static::register('organization:read', [
            'description'  => "See {organization}'s name, logo, currency and country",
            'organization' => true,
        ]);
        static::register('api-key:create', [
            'description'  => 'Create an API key for this app in {organization}',
            'organization' => true,
        ]);
    }

    public static function has(string $scope): bool
    {
        return isset(static::$scopes[$scope]);
    }

    /**
     * @return array<string, array{description: string, organization: bool, review_access_types: array<int, string>}>
     */
    public static function all(): array
    {
        return static::$scopes;
    }

    public static function get(string $scope): ?array
    {
        return static::$scopes[$scope] ?? null;
    }

    /**
     * Whether any of the scopes needs an organization.
     *
     * @param array<int, string> $scopes
     */
    public static function requiresOrganization(array $scopes): bool
    {
        foreach ($scopes as $scope) {
            if (static::$scopes[$scope]['organization'] ?? false) {
                return true;
            }
        }

        return false;
    }

    /**
     * The scope that covers App Review access for a subject type, if any.
     */
    public static function scopeForReviewAccessType(string $type): ?string
    {
        foreach (static::$scopes as $scope => $definition) {
            if (in_array($type, $definition['review_access_types'], true)) {
                return $scope;
            }
        }

        return null;
    }

    /**
     * The consent-screen lines for a set of scopes.
     *
     * @param array<int, string> $scopes
     *
     * @return array<int, array{scope: string, description: string, organization: bool}>
     */
    public static function describe(array $scopes, ?string $organizationName = null): array
    {
        $lines = [];
        foreach ($scopes as $scope) {
            $definition = static::$scopes[$scope] ?? null;
            if (!$definition) {
                continue;
            }

            $lines[] = [
                'scope'        => $scope,
                'description'  => str_replace('{organization}', $organizationName ?: 'your organization', $definition['description']),
                'organization' => $definition['organization'],
            ];
        }

        return $lines;
    }

    /**
     * Forget every registration. For tests.
     */
    public static function flush(): void
    {
        static::$scopes = [];
    }
}
