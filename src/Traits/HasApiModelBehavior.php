<?php

namespace Fleetbase\Traits;

use Fleetbase\Support\Http;
use Fleetbase\Support\Utils;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Exception;
use Error;

/**
 * Adds API Model Behavior 
 */
trait HasApiModelBehavior
{

    public static $PUBLIC_ID_COLUMN = 'public_id';

    public function getQualifiedPublicId()
    {
        return static::$PUBLIC_ID_COLUMN;
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
        $builder =  $this->searchBuilder($request);

        if (intval($limit) > 0) {
            $builder->limit($limit);
        }

        if (Http::isInternalRequest($request)) {
            return $builder->paginate($limit, $columns);
        }

        return $builder->get();
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

    public function store(Request $request)
    {
        $data = $this->create($request->all());

        $builder = $this->where($this->getQualifiedKeyName(), $data->uuid);
        $builder = $this->withRelationships($request, $builder);
        $builder = $this->withCounts($request, $builder);

        return $builder->first();
    }


    public function modify(Request $request, $id)
    {
        $record = $this->where(function ($q) use ($id) {
            $q->where($this->getQualifiedKeyName(), $id);
            $q->orWhere($this->getQualifiedPublicId(), $id);
        })->first();

        if (!$record) {
            throw new NotFoundHttpException("Resource not found");
        }

        $input = $request->all();
        $fillable = $record->getFillable();
        $keys = array_keys($input);

        foreach ($keys as $key) {
            if (!in_array($key, $fillable)) {
                throw new Exception('Invalid param "' . $key . '" in update request!');
            }
        }

        $record->fill($input);
        $record->save();

        $builder = $this->where(function ($q) use ($id) {
            $q->where($this->getQualifiedKeyName(), $id);
            $q->orWhere($this->getQualifiedPublicId(), $id);
        });
        $builder = $this->withRelationships($request, $builder);
        $builder = $this->withCounts($request, $builder);

        // in case the returned model from the search is null, then use the initially found model
        return $builder->first() ?? $record;
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
        } catch (Exception $e) {
            throw $e;
        }
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

    public function search(Request $request)
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

    public function resolveFilter(Request $request, $namespace = '\\Fleetbase\\Http\\Filter')
    {
        if ($this->filter) {
            return $this->filter;
        }

        $filter = $namespace . '\\' . Str::studly(Str::singular($this->getTable()) . 'Filter');

        if (class_exists($filter)) {
            return new $filter($request);
        }

        return null;
    }

    public function applyCustomFilters(Request $request, $builder)
    {
        $resourceFilter = $this->resolveFilter($request);

        if ($resourceFilter) {
            $builder->filter($resourceFilter);
        }

        return $builder;
    }

    public function count(Request $request)
    {
        return $this->buildSearchParams($request, self::query())->count();
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

            $builder = $this->applyOperators($builder, $column, $operator, $operator_symbol, $value);
        }

        return $builder;
    }

    public function buildSearchParams(Request $request, $builder)
    {
        $operators = $this->getQueryOperators();

        foreach ($request->all() as $key => $value) {
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
                $column_name = Str::replaceLast($op_key, '', $key);

                $fieldEndsWithOperator = Str::endsWith($key, $op_key);
                $columnIsSearchable = in_array($column_name, $this->searcheableFields());

                if (!$fieldEndsWithOperator || !$columnIsSearchable) {
                    continue;
                }

                $builder = $this->applyOperators($builder, $column_name, $op_key, $op_type, $value);
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
