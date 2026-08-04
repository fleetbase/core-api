<?php

namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Services\TemplateRenderService;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

class TemplateQuery extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use TracksApiCredential;
    use SoftDeletes;

    /**
     * The database table used by the model.
     */
    protected $table = 'template_queries';

    /**
     * The HTTP resource to use for responses.
     */
    public $resource = \Fleetbase\Http\Resources\TemplateQuery::class;

    /**
     * The type of public Id to generate.
     */
    protected $publicIdType = 'tq';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'uuid',
        'public_id',
        'company_uuid',
        'template_uuid',
        'created_by_uuid',
        'model_type',
        'variable_name',
        'label',
        'conditions',
        'sort',
        'limit',
        'with',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'conditions' => Json::class,
        'sort'       => Json::class,
        'with'       => Json::class,
        'limit'      => 'integer',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = ['id'];

    /**
     * The template this query belongs to.
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'template_uuid', 'uuid');
    }

    /**
     * The company this query belongs to.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_uuid', 'uuid');
    }

    /**
     * The user who created this query.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_uuid', 'uuid');
    }

    /**
     * Execute this query and return the result collection.
     *
     * Conditions are evaluated against the model class. Each condition object:
     *   { field, operator, value, type }
     *
     * Supported operators: =, !=, >, >=, <, <=, like, not like, in, not in, null, not null
     */
    public function execute(): \Illuminate\Support\Collection
    {
        $modelClass = $this->model_type;

        if (!TemplateRenderService::isTemplateQueryModelAllowed($modelClass) || !class_exists($modelClass)) {
            return collect();
        }

        $model = new $modelClass();
        // @codeCoverageIgnoreStart
        // Allowed template query models are configured as Eloquent models.
        if (!$model instanceof EloquentModel) {
            return collect();
        }
        // @codeCoverageIgnoreEnd

        $query = $modelClass::query();

        if ($this->modelHasCompanyColumn($model)) {
            $companyUuid = $this->company_uuid ?: session('company');
            if (empty($companyUuid)) {
                return collect();
            }

            $query->where($model->qualifyColumn('company_uuid'), $companyUuid);
        } elseif (!TemplateRenderService::isTemplateQueryModelGloballyQueryable($modelClass)) {
            return collect();
        }

        // Apply filter conditions
        foreach ($this->conditions ?? [] as $condition) {
            $field    = data_get($condition, 'field');
            $operator = data_get($condition, 'operator', '=');
            $value    = data_get($condition, 'value');

            if (!$field) {
                continue;
            }

            switch (strtolower($operator)) {
                case 'in':
                    $query->whereIn($field, (array) $value);
                    break;
                case 'not in':
                    $query->whereNotIn($field, (array) $value);
                    break;
                case 'null':
                    $query->whereNull($field);
                    break;
                case 'not null':
                    $query->whereNotNull($field);
                    break;
                case 'like':
                    $query->where($field, 'LIKE', '%' . $value . '%');
                    break;
                case 'not like':
                    $query->where($field, 'NOT LIKE', '%' . $value . '%');
                    break;
                default:
                    $query->where($field, $operator, $value);
                    break;
            }
        }

        // Apply sort
        foreach ($this->sort ?? [] as $sortDirective) {
            $field     = data_get($sortDirective, 'field');
            $direction = data_get($sortDirective, 'direction', 'asc');
            if ($field) {
                $query->orderBy($field, $direction);
            }
        }

        // Apply limit
        if ($this->limit) {
            $query->limit($this->limit);
        }

        // Eager-load relationships
        $with = $this->getAttribute('with');
        if (!empty($with)) {
            $query->with($with);
        }

        return $query->get();
    }

    protected function modelHasCompanyColumn(EloquentModel $model): bool
    {
        $connection = $model->getConnectionName() ?: config('database.default');

        return Schema::connection($connection)->hasColumn($model->getTable(), 'company_uuid');
    }
}
