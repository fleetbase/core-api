<?php

namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Str;

/**
 * One row of the SMS send ledger: a send the guard allowed (and how the provider
 * answered) or one it refused (and why). See SmsLedger for how rows are written.
 */
class SmsSend extends EloquentModel
{
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_SENT     = 'sent';
    public const STATUS_FAILED   = 'failed';
    public const STATUS_BLOCKED  = 'blocked';

    protected $table = 'sms_sends';

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'uuid',
        'company_uuid',
        'tenant_type',
        'tenant_uuid',
        'purpose',
        'source',
        'destination',
        'destination_hash',
        'destination_country',
        'destination_type',
        'status',
        'block_reason',
        'provider',
        'provider_message_id',
        'error',
        'subject_type',
        'subject_uuid',
        'verification_code_uuid',
        'ip_address',
        'user_agent',
        'meta',
    ];

    protected $casts = [
        'meta' => Json::class,
    ];

    public function getConnectionName()
    {
        return $this->connection ?: config('fleetbase.connection.db', 'mysql');
    }

    protected static function booted(): void
    {
        static::creating(function (self $send) {
            if (blank($send->uuid)) {
                $send->uuid = (string) Str::uuid();
            }
        });
    }
}
