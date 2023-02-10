<?php

namespace Fleetbase\Expansions;

use Fleetbase\Build\Expansion;

class Request implements Expansion
{
    /**
     * Get the target class to expand.
     *
     * @return string|Class
     */
    public static function target()
    {
        return \Illuminate\Support\Facades\Request::class;
    }

    /**
     * Iterates request params until a param is found.
     *
     * @return Closure
     */
    public function or()
    {
        /**
         * Iterates request params until a param is found.
         *
         * @param array $params
         * @param mixed $default
         * @return mixed
         */
        return function (array $params = [], $default = null) {
            foreach ($params as $param) {
                if ($this->has($param)) {
                    return $this->input($param);
                }
            }

            return $default;
        };
    }

    /**
     * Retrieve input from the request as a array.
     *
     * @return Closure
     */
    public function array()
    {
        /**
         * Retrieve input from the request as a array.
         *
         * @param string $key
         * @return array
         */
        return function (string $key) {
            return (array) $this->input($key, []);
        };
    }

    /**
     * Returns all Fleetbase global filters.
     *
     * @return Closure
     */
    public function getFilters()
    {
        return function (?array $additionalFilters = []) {
            $defaultFilters = [
                'within',
                'with',
                'without',
                'coords',
                'boundary',
                'page',
                'offset',
                'limit',
                'perPage',
                'per_page',
                'singleRecord',
                'single',
                'query',
                'searchQuery',
                'columns',
                'distinct',
                'sort',
                'before',
                'after',
                'on',
                'global',
            ];
            $filters = is_array($additionalFilters) ? array_merge($defaultFilters, $additionalFilters) : $defaultFilters;
            return $this->except($filters);
        };
    }
}
