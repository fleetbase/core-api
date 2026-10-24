<?php

namespace Fleetbase\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One user's consent for one app, optionally scoped to one organization.
 *
 * Every authorization code and token carries the grant it was issued under. Revoking the grant
 * (the user removing the app, an admin revoking it, a replayed refresh token) revokes every code
 * and token under it in one place.
 */
class OAuthGrant extends EloquentModel
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
    protected $table = 'oauth_grants';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'uuid';

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
    protected $fillable = ['uuid', 'user_uuid', 'client_id', 'company_uuid', 'scopes', 'last_used_at', 'revoked_at'];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array<int, string>
     */
    protected $hidden = ['id'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'scopes'       => 'array',
        'last_used_at' => 'datetime',
        'revoked_at'   => 'datetime',
    ];

    /**
     * Generate the uuid on create.
     */
    protected static function booted(): void
    {
        static::creating(function (self $grant) {
            if (empty($grant->uuid)) {
                $grant->uuid = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(OAuthClient::class, 'client_id', 'id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_uuid', 'uuid');
    }

    /**
     * Grants that haven't been revoked.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Revoke the grant and every code and token issued under it.
     */
    public function revoke(): void
    {
        $connection = $this->getConnectionName();

        DB::connection($connection)->transaction(function () use ($connection) {
            if (!$this->isRevoked()) {
                $this->revoked_at = now();
                $this->save();
            }

            static::revokeTokensFor([$this->uuid], $connection);
        });
    }

    /**
     * Revoke every code and token under the given grants.
     *
     * @param array<int, string> $grantUuids
     */
    public static function revokeTokensFor(array $grantUuids, ?string $connection = null): void
    {
        if (empty($grantUuids)) {
            return;
        }

        $db = DB::connection($connection ?? 'mysql');

        $db->table('oauth_auth_codes')->whereIn('grant_uuid', $grantUuids)->update(['revoked' => true]);
        $db->table('oauth_access_tokens')->whereIn('grant_uuid', $grantUuids)->update(['revoked' => true, 'updated_at' => now()]);
        $db->table('oauth_refresh_tokens')->whereIn('grant_uuid', $grantUuids)->update(['revoked' => true]);
    }

    /**
     * Record that the grant was used, at most once a minute so busy clients don't write per call.
     */
    public function touchLastUsed(): void
    {
        if ($this->last_used_at && $this->last_used_at->gt(now()->subMinute())) {
            return;
        }

        $this->forceFill(['last_used_at' => now()])->saveQuietly();
    }
}
