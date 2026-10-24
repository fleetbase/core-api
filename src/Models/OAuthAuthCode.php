<?php

namespace Fleetbase\Models;

use Illuminate\Database\Eloquent\Model as EloquentModel;

/**
 * An authorization code issued on the consent screen. Single use, ten minutes.
 */
class OAuthAuthCode extends EloquentModel
{
    protected $table = 'oauth_auth_codes';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'scopes'     => 'array',
        'revoked'    => 'boolean',
        'auth_time'  => 'datetime',
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
