<?php

namespace Fleetbase\Expansions;

use Fleetbase\Build\Expansion;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class Builder implements Expansion
{
    /**
     * Get the target class to expand.
     *
     * @return string|Class
     */
    public static function target()
    {
        return \Illuminate\Database\Eloquent\Builder::class;
    }

    /**
     * Adds a universal query scope `searchWhere` which performs a case insensitive like on a column.
     * If `$strict` is true, then it will use a classic `where()` on the column.
     *
     * @return void
     */
    public function searchWhere()
    {
        return function ($column, $search, $strict = false) {
            /* @var \Illuminate\Database\Eloquent\Builder $this */
            if (is_array($column)) {
                return $this->where(
                    function ($query) use ($column, $search, $strict) {
                        if ($strict === true) {
                            foreach ($column as $c) {
                                $query->orWhere($c, $search);
                            }
                        } else {
                            foreach ($column as $c) {
                                $query->orWhere(DB::raw("lower($c)"), 'like', '%' . str_replace('.', '%', str_replace(',', '%', $search)) . '%');
                            }
                        }
                    }
                );
            }

            if ($strict === true) {
                return $this->where($column, $search);
            }

            return $this->where(DB::raw("lower($column)"), 'like', '%' . str_replace('.', '%', str_replace(',', '%', $search)) . '%');
        };
    }

    /**
     * Removes a where clause by column and value.
     *
     * Example:
     *      $query->removeWhereFromQuery('status', 'active');
     *      will remove any query = $query->where('status', 'active');
     *
     * @return void
     */
    public function removeWhereFromQuery()
    {
        return function (string $column, $value, string $operator = '=', string $type = 'Basic') {
            /** @var \Illuminate\Database\Eloquent\Builder $this */
            $underlyingQuery = $this->getQuery();
            $wheres          = $underlyingQuery->wheres;
            $bindings        = $underlyingQuery->bindings['where'];

            // find key to remove based on where clause match
            $removeKey = Arr::search(
                $wheres,
                function ($where) use ($column, $value, $operator, $type) {
                    $isColumn   = data_get($where, 'column') === $column;
                    $isValue    = data_get($where, 'value') === $value;
                    $isOperator = data_get($where, 'operator') === $operator;
                    $isType     = data_get($where, 'type') === $type;

                    return $isColumn && $isValue && $isOperator && $isType;
                }
            );

            // remove using key found
            if (is_int($removeKey)) {
                unset($wheres[$removeKey]);
                unset($bindings[$removeKey]);
            }

            $underlyingQuery->wheres            = $wheres;
            $underlyingQuery->bindings['where'] = $bindings;

            return $this;
        };
    }
}
