<?php

namespace Fleetbase\Expansions;

use Fleetbase\Build\Expansion;
use Fleetbase\Models\Company;
use Illuminate\Support\Str;

/**
 * @mixin \Illuminate\Support\Facades\Request
 */
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
     * Extends Request to find the current organization/company.
     *
     * @return \Fleetbase\Models\Company|null
     */
    public function company()
    {
        return function () {
            /** @var \Illuminate\Http\Request $this */
            if ($this->session()->has('company')) {
                return Company::find($this->session()->get('company'));
            }

            return null;
        };
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
            /** @var \Illuminate\Http\Request $this */
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
            /** @var \Illuminate\Http\Request $this */
            if (is_string($this->input($key)) && Str::contains($this->input($key), ',')) {
                return explode(',', $this->input($key));
            }

            return (array) $this->input($key, []);
        };
    }

    /**
     * Check if param is array value.
     *
     * @return Closure
     */
    public function isArray()
    {
        return function ($param) {
            /**
             * Context.
             *
             * @var \Illuminate\Support\Facades\Request $this
             */
            return $this->has($param) && is_array($this->input($param));
        };
    }

    /**
     * Retrieve input from the request as a integer.
     *
     * @return Closure
     */
    public function integer()
    {
        /**
         * Retrieve input from the request as a integer.
         *
         * @param string $key
         * @return array
         */
        return function (string $key, $default = 0) {
            /** @var \Illuminate\Http\Request $this */
            return intval($this->input($key, $default));
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
                'without_relations',
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
            /** @var \Illuminate\Http\Request $this */
            return $this->except($filters);
        };
    }
}
