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
        /*
         * Iterates request params until a param is found.
         *
         * @param array $params
         * @param mixed $default
         * @return mixed
         */
        return function (array $params = [], $default = null) {
            /* @var \Illuminate\Http\Request $this */
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
        /*
         * Retrieve input from the request as a array.
         *
         * @param string $param
         * @return array
         */
        return function (string $param) {
            /** @var \Illuminate\Http\Request $this */
            if (is_string($this->input($param)) && Str::contains($this->input($param), ',')) {
                return explode(',', $this->input($param));
            }

            return (array) $this->input($param, []);
        };
    }

    /**
     * Check if param is string value.
     *
     * @return Closure
     */
    public function isString()
    {
        return function ($param) {
            /*
             * Context.
             *
             * @var \Illuminate\Support\Facades\Request $this
             */
            return $this->has($param) && is_string($this->input($param));
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
            /*
             * Context.
             *
             * @var \Illuminate\Support\Facades\Request $this
             */
            return $this->has($param) && is_array($this->input($param));
        };
    }

    /**
     * Check value exists in request array param.
     *
     * @return Closure
     */
    public function inArray()
    {
        return function ($param, $needle) {
            /**
             * Context.
             *
             * @var \Illuminate\Support\Facades\Request $this
             */
            $haystack = (array) $this->input($param, []);

            if (is_array($haystack)) {
                return in_array($needle, $haystack);
            }

            return false;
        };
    }

    /**
     * Retrieve input from the request as a integer.
     *
     * @return Closure
     */
    public function integer()
    {
        /*
         * Retrieve input from the request as a integer.
         *
         * @param string $key
         * @return array
         */
        return function (string $key, $default = 0) {
            /* @var \Illuminate\Http\Request $this */
            return intval($this->input($key, $default));
        };
    }

    /**
     * Removes a param from the request.
     *
     * @return Closure
     */
    public function removeParam()
    {
        /*
         * Retrieve input from the request as a integer.
         *
         * @param string $key
         * @return array
         */
        return function (string $key) {
            /* @var \Illuminate\Http\Request $this */
            return $this->request->remove($key);
        };
    }

    /**
     * Retrieves the search query parameter.
     *
     * @return Closure
     */
    public function searchQuery()
    {
        /*
         * Retrieve the search query parameter.
         *
         * @return string
         */
        return function () {
            /** @var \Illuminate\Http\Request $this */
            $searchQueryParam = $this->or(['query', 'searchQuery']);

            if (is_string($searchQueryParam)) {
                return urldecode(strtolower($searchQueryParam));
            }

            return $searchQueryParam;
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

            /* @var \Illuminate\Http\Request $this */
            return $this->except($filters);
        };
    }
}
