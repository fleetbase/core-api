<?php

namespace Fleetbase\Expansions;

use Fleetbase\Build\Expansion;
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
     * @param string $column
     * @param string $search
     * @param boolean $strict
     * @return void
     */
    public function searchWhere()
    {
        return function ($column, $search, $strict = false) {
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
}
