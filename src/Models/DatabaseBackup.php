<?php

namespace Fleetbase\Models;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Str;

/**
 * The record of one database's dump in one backup run.
 */
class DatabaseBackup extends EloquentModel
{
    use Prunable;

    public const STATUS_RUNNING   = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';

    public const TRIGGER_SCHEDULED = 'scheduled';
    public const TRIGGER_MANUAL    = 'manual';
    public const TRIGGER_CONSOLE   = 'console';

    protected $table = 'database_backups';

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'uuid',
        'connection_name',
        'database',
        'status',
        'trigger',
        'disk',
        'path',
        'size_bytes',
        'duration_ms',
        'error',
        'started_at',
        'completed_at',
        'pruned_at',
    ];

    protected $casts = [
        'size_bytes'   => 'integer',
        'duration_ms'  => 'integer',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
        'pruned_at'    => 'datetime',
    ];

    public function getConnectionName()
    {
        return $this->connection ?: config('fleetbase.connection.db', 'mysql');
    }

    protected static function booted(): void
    {
        static::creating(function (DatabaseBackup $backup) {
            $backup->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * Run records are kept for a year, long after their files have been trimmed.
     */
    public function prunable()
    {
        return static::where('started_at', '<', now()->subYear());
    }

    /**
     * The shape the admin console reads.
     */
    public function toAdminArray(): array
    {
        return [
            'id'           => $this->uuid,
            'connection'   => $this->connection_name,
            'database'     => $this->database,
            'status'       => $this->status,
            'trigger'      => $this->trigger,
            'disk'         => $this->disk,
            'path'         => $this->path,
            'size_bytes'   => $this->size_bytes,
            'duration_ms'  => $this->duration_ms,
            'error'        => $this->error,
            'started_at'   => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'pruned_at'    => $this->pruned_at?->toIso8601String(),
        ];
    }
}
