<?php

namespace Fleetbase\Models;

use Illuminate\Database\Eloquent\Model as EloquentModel;

/**
 * An authorization code issued on the consent screen. Single use, ten minutes.
 */
class OAuthAuthCode extends EloquentModel
{
    /**
     * Always the core database, also when the console is in sandbox (test) mode: an app's
     * access is never a test record.
     *
     * @var string
     */
    protected $connection = 'mysql';

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
}
