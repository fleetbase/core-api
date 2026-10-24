<?php

namespace Fleetbase\Models;

use Illuminate\Database\Eloquent\Model as EloquentModel;

/**
 * A refresh token. Rotated on every use; presenting a rotated one again revokes the grant.
 */
class OAuthRefreshToken extends EloquentModel
{
    protected $table = 'oauth_refresh_tokens';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'revoked'    => 'boolean',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /**
     * Get the correct current connection to use.
     */
    public function getConnectionName()
    {
        return $this->connection ?: config('fleetbase.connection.db', 'mysql');
    }
}
