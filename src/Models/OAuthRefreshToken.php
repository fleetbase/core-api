<?php

namespace Fleetbase\Models;

use Illuminate\Database\Eloquent\Model as EloquentModel;

/**
 * A refresh token. Rotated on every use; presenting a rotated one again revokes the grant.
 */
class OAuthRefreshToken extends EloquentModel
{
    /**
     * Always the core database, also when the console is in sandbox (test) mode: an app's
     * access is never a test record.
     *
     * @var string
     */
    protected $connection = 'mysql';

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
}
