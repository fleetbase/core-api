<?php

namespace Fleetbase\Traits;

use Fleetbase\Support\Http;
use Fleetbase\Models\Model;
use Fleetbase\Support\Resolve;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Adds API Model Behavior 
 */
trait HasApiModelBehavior
{
    /**
     * The name of the database column used to store the public ID for this model.
     *
     * @var string
     */
    public static $publicIdColumn = 'public_id';

    /**
     * Get the fully qualified name of the database column used to store the public ID for this model.
     *
     * @return string The fully qualified name of the public ID column.
     */
    public function getQualifiedPublicId()
    {
        return static::$publicIdColumn;
    }

    /**
     * Get the plural name of this model, either from the `pluralName` property or by inflecting the table name.
     *
     * @return string The plural name of this model.
     */
    public function getPluralName(): string
    {
        if (isset($this->pluralName)) {
            return $this->pluralName;
        }

        return Str::plural($this->getTable());
    }

    /**
     * Get the singular name of this model, either from the `singularName` property or by inflecting the table name.
     *
     * @return string The singular name of this model.
     */
    public function getSingularName(): string
    {
        if (isset($this->singularName)) {
            return $this->singularName;
        }

        return Str::singular($this->getTable());
    }

    /**
     * Returns a list of fields that can be searched / filtered by. This includes
     * all fillable columns, the primary key column, and the created_at 
     * and updated_at columns
     * 
     * @return array
     */
    public function searcheableFields()
    {
        if ($this->searchableColumns) {
            return $this->searchableColumns;
        }

        return array_merge(
            $this->fillable,
            [
                $this->getKeyName(),
                $this->getCreatedAtColumn(),
                $this->getUpdatedAtColumn()
            ]
        );
    }

    /**
     * Retrieves all records based on request data passed in
     * 
     * @return \Illuminate\Database\Eloquent\Collection|\Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function queryFromRequest(Request $request)
    {
        $limit = $request->input('limit', 30);
        $columns = $request->input('columns', ['*']);

        /** 
         * @var \Illuminate\Database\Query\Builder $builder 
         */
        $builder = $this->searchBuilder($request);

        if (intval($limit) > 0) {
            $builder->limit($limit);
        }

        if (Http::isInternalRequest($request)) {
            return $builder->paginate($limit, $columns);
        }

        return $builder->get();
    }

    /**
     * Create a new record in the database based on the input data in the given request.
     *
     * @param  \Illuminate\Http\Request  $request The HTTP request containing the input data.
     * @param  callable|null  $onBefore An optional callback function to execute before creating the record.
     * @param  callable|null  $onAfter An optional callback function to execute after creating the record.
     * @param  array  $options An optional array of additional options.
     * @return mixed The newly created record, or a JSON response if the callbacks return one.
     */
    public function createRecordFromRequest($request, ?callable $onBefore = null, ?callable $onAfter = null, array $options = [])
    {
        $input = $request->input(Str::singular($this->getTable())) ?? $request->all();
        $input = $this->fillSessionAttributes($input);

        if (is_callable($onBefore)) {
            $before = $onBefore($request, $input);
            if ($before instanceof JsonResponse) {
                return $before;
            }
        }

        $record = static::create($input);

        if (isset($options['return_object']) && $options['return_object'] === true) {
            return $record;
        }

        $builder = $this->where($this->getQualifiedKeyName(), $record->uuid);
        $builder = $this->withRelationships($request, $builder);
        $builder = $this->withCounts($request, $builder);

        $record = $builder->first();

        if (is_callable($onAfter)) {
            $after = $onAfter($request, $record, $input);
            if ($after instanceof JsonResponse) {
                return $after;
            }
        }

        return static::mutateModelWithRequest($request, $record);
    }

    /**
     * Update an existing record in the database based on the input data in the given request.
     *
     * @param  \Illuminate\Http\Request  $request The HTTP request containing the input data.
     * @param  mixed  $id The ID of the record to update.
     * @param  callable|null  $onBefore An optional callback function to execute before updating the record.
     * @param  callable|null  $onAfter An optional callback function to execute after updating the record.
     * @param  array  $options An optional array of additional options.
     * @return mixed The updated record, or a JSON response if the callbacks return one.
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException If the record with the given ID is not found.
     * @throws \Exception If the input contains an invalid parameter that is not fillable.
     */
    public function updateRecordFromRequest(Request $request, $id, ?callable $onBefore = null, ?callable $onAfter = null, array $options = [])
    {
        $record = $this->where(function ($q) use ($id) {
            $q->where($this->getQualifiedKeyName(), $id);
            $q->orWhere($this->getQualifiedPublicId(), $id);
        })->first();

        if (!$record) {
            throw new NotFoundHttpException(Str::title(Str::singular($this->getTable())) . ' not found');
        }

        $input = $request->input(Str::singular($this->getTable())) ?? $request->all();
        $input = $this->fillSessionAttributes($input, [], ['updated_by_uuid']);

        if (is_callable($onBefore)) {
            $before = $onBefore($request, $input);
            if ($before instanceof JsonResponse) {
                return $before;
            }
        }

        $fillable = $record->getFillable();
        $keys = array_keys($input);

        foreach ($keys as $key) {
            if (!in_array($key, $fillable)) {
                throw new \Exception('Invalid param "' . $key . '" in update request!');
            }
        }

        $record->update($input);

        if (isset($options['return_object']) && $options['return_object'] === true) {
            return $record;
        }

        $builder = $this->where(
            function ($q) use ($id) {
                $q->where($this->getQualifiedKeyName(), $id);
                $q->orWhere($this->getQualifiedPublicId(), $id);
            }
        );
        $builder = $this->withRelationships($request, $builder);
        $builder = $this->withCounts($request, $builder);

        $record = $builder->first();

        if (is_callable($onAfter)) {
            $after = $onAfter($request, $record, $input);
            if ($after instanceof JsonResponse) {
                return $after;
            }
        }

        return static::mutateModelWithRequest($request, $record);
    }

    public function remove($id)
    {
        $record = $this->where(function ($q) use ($id) {
            $q->where($this->getQualifiedKeyName(), $id);
            $q->orWhere($this->getQualifiedPublicId(), $id);
        });

        if (!$record) {
            return false;
        }

        try {
            return $record->delete();
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function bulkRemove($ids = [])
    {
        $records = $this->where(function ($q) use ($ids) {
            $q->whereIn($this->getQualifiedKeyName(), $ids);
            $q->orWhereIn($this->getQualifiedPublicId(), $ids);
        });

        if (!$records) {
            return false;
        }

        $count = $records->count();

        try {
            $records->delete();
            return $count;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public static function mutateModelWithRequest(Request $request, Model $model)
    {
        $with = $request->or(['with', 'expand']);
        $without = $request->input('without', []);

        if ($with) {
            $model->load($with);
        }

        if ($without) {
            $model->setHidden($without);
        }

        return $model;
    }

    public function fillSessionAttributes($target, $except = [], $only = [])
    {
        $fill = [];
        $attributes = [
            'user_uuid' => 'user',
            'author_uuid' => 'user',
            'uploader_uuid' => 'user',
            'creator_uuid' => 'user',
            'created_by_uuid' => 'user',
            'updated_by_uuid' => 'user',
            'company_uuid' => 'company'
        ];

        foreach ($attributes as $attr => $key) {
            if (!empty($only) && !in_array($attr, $only)) {
                continue;
            }

            if ($this->isFillable($attr) && !in_array($except, array_keys($attributes))) {
                $fill[$attr] = session($key);
            }
        }

        return array_merge($target, $fill);
    }

    /**
     * Checks if request contains relationships.
     *
     * @param Request $request
     * @param \Illuminate\Database\Query\Builder $builder
     * 
     * @return \Illuminate\Database\Query\Builder
     */
    public function withRelationships(Request $request, $builder)
    {
        $with = $request->or(['with', 'expand']);
        $without = $request->input('without', []);

        if (!$with) {
            return $builder;
        }

        $contains = is_array($with) ? $with : explode(',', $with);

        foreach ($contains as $contain) {

            $camelVersion = Str::camel(trim($contain));
            if (\method_exists($this, $camelVersion)) {
                $builder->with($camelVersion);
                continue;
            }

            $snakeCase = Str::snake(trim($contain));
            if (\method_exists($this, $snakeCase)) {
                $builder->with(trim($snakeCase));
                continue;
            }

            if (strpos($contain, '.') !== false) {
                $parts = array_map(
                    function ($part) {
                        return Str::camel($part);
                    },
                    explode('.', $contain)
                );
                $contain = implode(".", $parts);

                $builder->with($contain);
                continue;
            }
        }

        if ($without) {
            $builder->without($without);
        }

        return $builder;
    }

    /**
     * Checks if request includes counts.
     *
     * @param Request $request
     * @param \Illuminate\Database\Query\Builder $builder
     * 
     * @return \Illuminate\Database\Query\Builder
     */
    public function withCounts($request, $builder)
    {
        $count = $request->or(['count', 'with_count']);

        if (!$count) {
            return $builder;
        }

        $counters = explode(",", $count);

        foreach ($counters as $counter) {
            if (\method_exists($this, $counter)) {
                $builder->withCount($counter);
                continue;
            }

            $camelVersion = Str::camel($counter);
            if (\method_exists($this, $camelVersion)) {
                $builder->withCount($camelVersion);
                continue;
            }
        }

        return $builder;
    }

    /**
     * Apply sorts to query.
     *
     * @param Request $request - HTTP Request
     * @param \Illuminate\Database\Query\Builder $builder - Query Builder
     * 
     * @return \Illuminate\Database\Query\Builder
     */
    public function applySorts($request, $builder)
    {
        $sorts = $request->sort ? explode(',', $request->sort) : null;

        if (!$sorts) {
            return $builder;
        }

        foreach ($sorts as $sort) {
            if (Schema::hasColumn($this->table, $this->getCreatedAtColumn())) {
                if (strtolower($sort) == 'latest') {
                    $builder->latest();
                    continue;
                }

                if (strtolower($sort) == 'oldest') {
                    $builder->oldest();
                    continue;
                }
            }

            if (strtolower($sort) == 'distance') {
                $builder->orderByDistance();
                continue;
            }

            if (is_array($sort) || Str::contains($sort, ',')) {
                $columns = !is_array($sort) ? explode(',', $sort) : $sort;

                foreach ($columns as $column) {
                    if (Str::startsWith($column, '-')) {
                        $direction = Str::startsWith($column, '-') ? 'desc' : 'asc';
                        $param = Str::startsWith($column, '-') ? substr($column, 1) : $column;

                        $builder->orderBy($column, $direction);
                        continue;
                    }

                    $sd = explode(":", $column);
                    if ($sd && count($sd) > 0) {
                        count($sd) == 2
                            ? $builder->orderBy(trim($sd[0]), trim($sd[1]))
                            : $builder->orderBy(trim($sd[0]), 'asc');
                    }
                }
            }

            if (Str::startsWith($sort, '-')) {
                list($param, $direction) = Http::useSort($request);

                $builder->orderBy($param, $direction);
                continue;
            }

            list($param, $direction) = Http::useSort($request);
            $builder->orderBy($param, $direction);
        }

        return $builder;
    }

    /**
     * Retrieves a record based on primary key id
     * 
     * @param string $id - The ID
     * @param \Illuminate\Http\Request $request - HTTP Request
     * 
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function getById($id, Request $request)
    {
        $builder = $this->where(function ($q) use ($id) {
            $q->where($this->getQualifiedKeyName(), $id);
            $q->orWhere($this->getQualifiedPublicId(), $id);
        });

        $builder = $this->withCounts($request, $builder);
        $builder = $this->withRelationships($request, $builder);
        $builder = $this->applySorts($request, $builder);

        return $builder->first();
    }

    /**
     * this returns key value pair for select options
     */
    public function getOptions()
    {
        $builder = $this->select($this->option_key, $this->option_label)
            ->orderBy($this->option_label, 'asc')
            ->get();

        //convert data to standard object {value:'', label:''}
        $arr = [];
        foreach ($builder as $x) {
            if ($x[$this->option_label]) {
                $arr[] = [
                    'value' => $x[$this->option_key],
                    'label' => $x[$this->option_label]
                ];
            }
        }

        return $arr;
    }

    public function searchRecordFromRequest(Request $request)
    {
        $limit = $request->limit ?? 30;
        $builder =  $this->searchBuilder($request);

        return $builder->paginate($limit);
    }

    public function searchBuilder(Request $request)
    {
        $builder = $this->buildSearchParams($request, self::query());
        $builder = $this->applyFilters($request, $builder);
        $builder = $this->applyCustomFilters($request, $builder);
        $builder = $this->withRelationships($request, $builder);
        $builder = $this->withCounts($request, $builder);
        $builder = $this->applySorts($request, $builder);

        return $builder;
    }

    public function applyCustomFilters(Request $request, $builder)
    {
        $resourceFilter = Resolve::httpFilterForModel($this, $request);

        if ($resourceFilter) {
            $builder->filter($resourceFilter);
        }

        return $builder;
    }

    public function applyFilters(Request $request, $builder)
    {
        $operators = $this->getQueryOperators();
        $filters = $request->input('filters', []);

        foreach ($filters as $column => $values) {
            if (!in_array($column, $this->searcheableFields())) {
                continue;
            }

            $valueParts = explode(":", $values);
            $operator = "eq";
            $operator_symbol = '=';
            $value = null;

            if (count($valueParts) > 1) {
                $operator = $valueParts[0];
                $operator_symbol = $operators[$operator] ?? '=';
                $value = $valueParts[1];
            } else {
                $value = $valueParts[0];
            }

            if ($this->prioritizedCustomColumnFilter($request, $builder, $column)) {
                continue;
            }

            $builder = $this->applyOperators($builder, $column, $operator, $operator_symbol, $value);
        }

        return $builder;
    }

    public function count(Request $request)
    {
        return $this->buildSearchParams($request, self::query())->count();
    }

    public function prioritizedCustomColumnFilter($request, $builder, $column)
    {
        $resourceFilter = Resolve::httpFilterForModel($this, $request);
        $camlizedColumnName = Str::camel($column);

        return method_exists($resourceFilter, $camlizedColumnName) || method_exists($resourceFilter, $column);
    }

    public function buildSearchParams(Request $request, $builder)
    {
        $operators = $this->getQueryOperators();

        foreach ($request->all() as $key => $value) {
            if ($this->prioritizedCustomColumnFilter($request, $builder, $key) || empty($value)) {
                continue;
            }

            if (in_array($key, $this->searcheableFields())) {
                switch ($key) {
                    default:
                        $builder->where($key, '=', $value);
                        break;
                }
            }

            // apply special operators based on the column name passed
            foreach ($operators as $op_key => $op_type) {
                $key = strtolower($key);
                $op_key = strtolower($op_key);
                $column = Str::replaceLast($op_key, '', $key);

                $fieldEndsWithOperator = Str::endsWith($key, $op_key);
                $columnIsSearchable = in_array($column, $this->searcheableFields());

                if (!$fieldEndsWithOperator || !$columnIsSearchable) {
                    continue;
                }

                $builder = $this->applyOperators($builder, $column, $op_key, $op_type, $value);
            }
        }

        return $builder;
    }

    private function getQueryOperators()
    {
        return [
            '_not' => '!=',
            '_gt' => '>',
            '_lt' => '<',
            '_gte' => '>=',
            '_lte' => '<=',
            '_like' => 'LIKE',
            '_in' => true,
            '_notIn' => true,
            '_isNull' => true,
            '_isNotNull' => true
        ];
    }

    private function applyOperators($builder, $column_name, $op_key, $op_type, $value)
    {
        $column_name = $this->shouldQualifyColumn($column_name)
            ? $this->qualifyColumn($column_name)
            : $column_name;

        if ($op_key == '_in') {
            $builder->whereIn($column_name, explode(',', $value));
        } else if ($op_key == strtolower('_notIn')) {
            $builder->whereNotIn($column_name, explode(',', $value));
        } else if ($op_key == strtolower('_isNull')) {
            $builder->whereNull($column_name);
        } else if ($op_key == strtolower('_isNotNull')) {
            $builder->whereNotNull($column_name);
        } else if ($op_key == '_like') {
            $builder->where($column_name, 'LIKE', "{$value}%");
        } else {
            $builder->where($column_name, $op_type, $value);
        }

        return $builder;
    }

    public function shouldQualifyColumn($column_name)
    {
        return in_array($column_name, [
            $this->getKey() ?? 'uuid',
            $this->getCreatedAtColumn() ?? 'created_at',
            $this->getUpdatedAtColumn() ?? 'updated_at',
            $this->getDeletedAtColumn() ?? 'deleted_at'
        ]);
    }
}
