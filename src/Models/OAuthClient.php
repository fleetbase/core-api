<?php

namespace Fleetbase\Models;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * An app registered to sign people in with Fleetbase (Fleetbase as the OAuth provider).
 *
 * The primary key is the public `client_id`. The secret is stored only as a bcrypt hash; the
 * plain value exists once, in the response that created or rotated it.
 *
 * Extends Eloquent directly for the same reason as OAuthIdentity: Fleetbase\Models\Model forces
 * SoftDeletes, and a disabled client is recorded with `disabled_at` instead.
 */
class OAuthClient extends EloquentModel
{
    /**
     * Always the core database, also when the console is in sandbox (test) mode: an app's
     * access is never a test record.
     *
     * @var string
     */
    protected $connection = 'mysql';

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'oauth_clients';

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'description',
        'redirect_uris',
        'allowed_scopes',
        'logo_url',
        'website_url',
        'privacy_url',
        'terms_url',
        'official',
        'development',
        'created_by_uuid',
        'software_id',
        'registration_key',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array<int, string>
     */
    protected $hidden = ['secret'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'redirect_uris'     => 'array',
        'allowed_scopes'    => 'array',
        'official'          => 'boolean',
        'development'       => 'boolean',
        'secret_rotated_at' => 'datetime',
        'disabled_at'       => 'datetime',
    ];

    /**
     * Dynamic attributes appended to the model's JSON form.
     *
     * @var array<int, string>
     */
    protected $appends = ['confidential'];

    /**
     * Generate the client id on create.
     */
    protected static function booted(): void
    {
        static::creating(function (self $client) {
            if (empty($client->id)) {
                $client->id = (string) Str::uuid();
            }
        });
    }

    /**
     * The key that identifies a dynamically registered client: one per software and redirect URI.
     */
    public static function registrationKey(string $softwareId, string $redirectUri): string
    {
        return hash('sha256', $softwareId . '|' . $redirectUri);
    }

    /**
     * Whether the client registered itself through POST /oauth/register.
     */
    public function isRegistered(): bool
    {
        return !empty($this->software_id);
    }

    /**
     * The grants users have given this client.
     */
    public function grants(): HasMany
    {
        return $this->hasMany(OAuthGrant::class, 'client_id', 'id');
    }

    /**
     * Whether the client authenticates with a secret.
     */
    public function isConfidential(): bool
    {
        return !empty($this->secret);
    }

    /**
     * Accessor for `confidential` in the JSON form.
     */
    public function getConfidentialAttribute(): bool
    {
        return $this->isConfidential();
    }

    /**
     * Whether an admin turned the client off.
     */
    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /**
     * Generate, hash and store a new secret, returning the plain value.
     *
     * The caller shows the plain value once; it can never be read back.
     */
    public function issueSecret(): string
    {
        $plain                   = Str::random(64);
        $this->secret            = password_hash($plain, PASSWORD_BCRYPT);
        $this->secret_rotated_at = now();

        return $plain;
    }

    /**
     * Check a presented secret against the stored hash.
     */
    public function verifySecret(?string $plain): bool
    {
        if (!$this->isConfidential() || !is_string($plain) || $plain === '') {
            return false;
        }

        return password_verify($plain, $this->secret);
    }

    /**
     * Whether the client was registered for this scope.
     */
    public function allowsScope(string $scope): bool
    {
        return in_array($scope, $this->allowed_scopes ?? [], true);
    }

    /**
     * Whether a redirect URI is registered for this client.
     *
     * Matching is exact: scheme, host, port, path and query. The one exception is a development
     * client using a loopback address, where native-app guidance (RFC 8252 §7.3) says the port
     * must be allowed to vary.
     */
    public function allowsRedirectUri(?string $uri): bool
    {
        if (!is_string($uri) || $uri === '') {
            return false;
        }

        $registered = $this->redirect_uris ?? [];
        if (in_array($uri, $registered, true)) {
            return true;
        }

        if (!$this->development || !static::isLoopbackUri($uri)) {
            return false;
        }

        $withoutPort = static::withoutPort($uri);
        foreach ($registered as $candidate) {
            if (static::isLoopbackUri($candidate) && static::withoutPort($candidate) === $withoutPort) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a URI is an acceptable redirect URI to register.
     *
     * https everywhere; http only for loopback addresses; no fragments (RFC 6749 §3.1.2).
     */
    public static function isAcceptableRedirectUri(string $uri, bool $development = false): bool
    {
        $parts = parse_url($uri);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host']) || isset($parts['fragment'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme === 'https') {
            return true;
        }

        return $scheme === 'http' && static::isLoopbackUri($uri) && $development;
    }

    /**
     * Whether a URI points at the loopback interface over http.
     */
    public static function isLoopbackUri(string $uri): bool
    {
        $parts = parse_url($uri);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'http') {
            return false;
        }

        return in_array(strtolower($parts['host'] ?? ''), ['127.0.0.1', '[::1]', 'localhost'], true);
    }

    /**
     * The URI with its port removed, for loopback comparison.
     */
    protected static function withoutPort(string $uri): string
    {
        return preg_replace('#^(http://[^/:]+|http://\[::1\]):\d+#i', '$1', $uri);
    }
}
