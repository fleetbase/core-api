<?php

namespace Fleetbase\Models;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An access token issued by Fleetbase's OAuth provider.
 *
 * The client holds a signed JWT whose `jti` is this row's id. The row is what makes the token
 * revocable and binds it to the grant and organization it was issued for.
 */
class OAuthAccessToken extends EloquentModel
{
    protected $table = 'oauth_access_tokens';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'scopes'     => 'array',
        'revoked'    => 'boolean',
        'expires_at' => 'datetime',
    ];

    /**
     * Get the correct current connection to use.
     */
    public function getConnectionName()
    {
        return $this->connection ?: config('fleetbase.connection.db', 'mysql');
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(OAuthGrant::class, 'grant_uuid', 'uuid');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(OAuthClient::class, 'client_id', 'id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_uuid', 'uuid');
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    public function isUsable(): bool
    {
        return !$this->revoked && $this->expires_at && $this->expires_at->isFuture();
    }
}
