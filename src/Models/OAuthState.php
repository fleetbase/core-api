<?php

namespace Fleetbase\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A short-lived, single-use token backing one leg of the OAuth flow.
 *
 * Rows are ephemeral and are never soft-deleted; expired rows are removed by `model:prune`.
 * The `Expirable` trait is deliberately NOT used: its global scope would filter expired rows
 * out of the atomic consume UPDATE, which would make "expired" indistinguishable from
 * "already consumed" at the SQL level and silently weaken replay detection.
 */
class OAuthState extends EloquentModel
{
    use Prunable;

    /**
     * CSRF state + PKCE verifier. Issued at /redirect, consumed at /callback.
     */
    public const PURPOSE_AUTHORIZATION = 'authorization';

    /**
     * Carries the resolved provider profile from /callback to /exchange.
     */
    public const PURPOSE_HANDOFF = 'handoff';

    /**
     * Proves a verified provider identity to the signup endpoints.
     */
    public const PURPOSE_REGISTRATION_INTENT = 'registration_intent';

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
     * @var string
     */
    protected $table = 'oauth_states';

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
        'purpose',
        'token_hash',
        'provider',
        'intent',
        'user_uuid',
        'payload',
        'ip_hash',
        'expires_at',
        'consumed_at',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * This model is never serialized to a client, but hiding the hash and payload means an
     * accidental dd()/toArray() in a log or an exception renderer cannot leak them.
     *
     * @var array<int, string>
     */
    protected $hidden = ['id', 'token_hash', 'payload', 'ip_hash'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'expires_at'  => 'datetime',
        'consumed_at' => 'datetime',
    ];

    /**
     * Generate the uuid on create.
     */
    protected static function booted(): void
    {
        static::creating(function (self $state) {
            if (empty($state->uuid)) {
                $state->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Rows eligible for pruning.
     *
     * A day's grace past expiry so a support investigation can still see whether a failed
     * sign-in used an expired token or a token that never existed.
     *
     * @return Builder the query matching prunable rows
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now()->subDay());
    }

    /**
     * The user this row is bound to, when it is bound to one.
     *
     * @return BelongsTo the BelongsTo relationship instance
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }
}
