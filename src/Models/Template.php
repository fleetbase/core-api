<?php

namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Template extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use TracksApiCredential;
    use Searchable;
    use SoftDeletes;

    /**
     * The database table used by the model.
     */
    protected $table = 'templates';

    /**
     * The HTTP resource to use for responses.
     */
    public $resource = \Fleetbase\Http\Resources\Template::class;

    /**
     * The type of public Id to generate.
     */
    protected $publicIdType = 'template';

    /**
     * Columns that can be searched.
     */
    protected $searchableColumns = ['name', 'description', 'context_type'];

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'uuid',
        'public_id',
        'company_uuid',
        'created_by_uuid',
        'updated_by_uuid',
        'name',
        'description',
        'context_type',
        'unit',
        'width',
        'height',
        'orientation',
        'margins',
        'background_color',
        'background_image_uuid',
        'content',
        'element_schemas',
        'is_default',
        'is_system',
        'is_public',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'margins'         => Json::class,
        'content'         => Json::class,
        'element_schemas' => Json::class,
        'is_default'      => 'boolean',
        'is_system'       => 'boolean',
        'is_public'       => 'boolean',
        'width'           => 'float',
        'height'          => 'float',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = ['id'];

    /**
     * Dynamic attributes appended to the model.
     */
    protected $appends = [];

    /**
     * The company this template belongs to.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_uuid', 'uuid');
    }

    /**
     * The user who created this template.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_uuid', 'uuid');
    }

    /**
     * The user who last updated this template.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_uuid', 'uuid');
    }

    /**
     * The background image file for this template.
     */
    public function backgroundImage(): BelongsTo
    {
        return $this->belongsTo(File::class, 'background_image_uuid', 'uuid');
    }

    /**
     * The query data sources attached to this template.
     */
    public function queries(): HasMany
    {
        return $this->hasMany(TemplateQuery::class, 'template_uuid', 'uuid');
    }

    /**
     * Scope to filter templates by context type.
     */
    public function scopeForContext($query, string $contextType)
    {
        return $query->where('context_type', $contextType);
    }

    /**
     * Scope to include system and company templates.
     */
    public function scopeAvailableFor($query, string $companyUuid)
    {
        return $query->where(function ($q) use ($companyUuid) {
            $q->where('company_uuid', $companyUuid)
              ->orWhere('is_system', true)
              ->orWhere('is_public', true);
        });
    }
}
