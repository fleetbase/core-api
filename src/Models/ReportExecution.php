<?php

namespace Fleetbase\Models;

use Fleetbase\Traits\HasUuid;

class ReportExecution extends Model
{
    use HasUuid;

    /**
     * The database table used by the model.
     */
    protected $table = 'report_executions';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'uuid',
        'report_uuid',
        'user_uuid',
        'execution_time',
        'result_count',
        'query_config',
        'status',
        'error_message',
        'started_at',
        'completed_at',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected $casts = [
        'query_config'   => 'array',
        'execution_time' => 'float',
        'result_count'   => 'integer',
        'started_at'     => 'datetime',
        'completed_at'   => 'datetime',
    ];

    /**
     * Relationships.
     */
    public function report()
    {
        return $this->belongsTo(Report::class, 'report_uuid');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_uuid');
    }
}
