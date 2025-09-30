<?php

namespace Fleetbase\Models;

use Fleetbase\Traits\HasUuid;

class ReportAuditLog extends Model
{
    use HasUuid;

    /**
     * The database table used by the model.
     */
    protected $table = 'report_audit_logs';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'uuid',
        'report_uuid',
        'user_uuid',
        'action',
        'execution_time',
        'result_count',
        'error_message',
        'query_config',
        'ip_address',
        'user_agent',
        'metadata',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected $casts = [
        'query_config'   => 'array',
        'metadata'       => 'array',
        'execution_time' => 'float',
        'result_count'   => 'integer',
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

    /**
     * Scope for specific actions.
     */
    public function scopeAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Scope for executions.
     */
    public function scopeExecutions($query)
    {
        return $query->where('action', 'execute');
    }

    /**
     * Scope for exports.
     */
    public function scopeExports($query)
    {
        return $query->where('action', 'export');
    }
}
