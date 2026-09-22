<?php

namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A link between a Fleetbase user and an external OAuth/OIDC identity.
 *
 * This model extends Eloquent directly rather than Fleetbase\Models\Model because that base
 * class hard-uses SoftDeletes, and soft deletes are wrong here — see the migration for why.
 * For the same reason it does not use the HasUuid trait, whose generateUuid() calls
 * withTrashed() and would fatal on a model without SoftDeletes.
 */
class OAuthIdentity extends EloquentModel
{
    /**
     * Get the correct current connection to use.
     */
    public function getConnectionName()
    {
        return $this->connection ?: config('fleetbase.connection.db', 'mysql');
    }

    /**
     * The database table used by the model.
     *
     * Set explicitly: Eloquent's convention would derive `o_auth_identities` from the
     * class name, because Str::snake() treats the capital A in "OAuth" as a word boundary.
     *
     * @var string
     */
    protected $table = 'oauth_identities';

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
    protected $fillable = [
        'uuid',
        'user_uuid',
        'provider',
        'provider_user_id',
        'provider_email',
        'email_verified',
        'meta',
        'last_login_at',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * `provider_user_id` is a stable cross-application identifier for the person at that
     * provider. It is never needed by a client and must not leak into an API response.
     *
     * @var array<int, string>
     */
    protected $hidden = ['id', 'provider_user_id'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'meta'           => Json::class,
        'email_verified' => 'boolean',
        'last_login_at'  => 'datetime',
    ];

    /**
     * Generate the uuid on create.
     */
    protected static function booted(): void
    {
        static::creating(function (self $identity) {
            if (empty($identity->uuid)) {
                $identity->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * The user this identity belongs to.
     *
     * @return BelongsTo the BelongsTo relationship instance
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }
}
