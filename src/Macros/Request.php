<?php

namespace Fleetbase\Macros;

class Request
{
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

    public static function getFacade()
    {
        return \Illuminate\Support\Facades\Request::class;
    }
}
