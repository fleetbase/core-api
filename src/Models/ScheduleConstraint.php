<?php

namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Illuminate\Database\Eloquent\SoftDeletes;

class ScheduleConstraint extends Model
{
    use HasUuid;
    use HasApiModelBehavior;
    use HasMetaAttributes;
    use Searchable;
    use SoftDeletes;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'schedule_constraints';

    /**
     * These attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['name', 'description', 'constraint_key'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'company_uuid',
        'subject_uuid',
        'subject_type',
        'name',
        'description',
        'type',
        'category',
        'constraint_key',
        'constraint_value',
        'jurisdiction',
        'priority',
        'is_active',
        'meta',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'priority'  => 'integer',
        'is_active' => 'boolean',
        'meta'      => Json::class,
    ];

    /**
     * Attributes that is filterable on this model.
     *
     * @var array
     */
    protected $filterParams = [
        'company_uuid',
        'subject_type',
        'subject_uuid',
        'type',
        'category',
        'is_active',
        'jurisdiction',
    ];

    /**
     * Get the company that owns the constraint.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function company()
    {
        return $this->belongsTo(Company::class, 'company_uuid');
    }

    /**
     * Get the subject that this constraint belongs to (polymorphic).
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function subject()
    {
        return $this->morphTo(__FUNCTION__, 'subject_type', 'subject_uuid');
    }

    /**
     * Scope a query to only include active constraints.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include constraints by type.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string                                $type
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope a query to only include constraints by category.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string                                $category
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope a query to only include constraints for a specific subject.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string                                $type
     * @param string                                $uuid
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForSubject($query, $type, $uuid)
    {
        return $query->where('subject_type', $type)->where('subject_uuid', $uuid);
    }

    /**
     * Scope a query to order by priority (highest first).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOrderByPriority($query)
    {
        return $query->orderBy('priority', 'desc');
    }
}
