<?php

namespace Fleetbase\Http\Filter;

use Fleetbase\Support\Http;
use Fleetbase\Traits\Expandable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

abstract class Filter
{
    /**
     * Make expandable.
     */
    use Expandable;

    /**
     * The request instance.
     *
     * @var Request
     */
    protected $request;

    /**
     * The session instance.
     *
     * @var \Illuminate\Contracts\Session\Session
     */
    protected $session;

    /**
     * The builder instance.
     *
     * @var Builder
     */
    protected $builder;

    /**
     * Cache for method existence checks to avoid repeated reflection.
     *
     * @var array
     */
    protected $methodCache = [];

    /**
     * Parameters to skip during filter application.
     * These are handled elsewhere or are not filter parameters.
     *
     * @var array
     */
    protected static $skipParams = [
        'limit',
        'offset',
        'page',
        'sort',
        'order',
        'with',
        'expand',
        'without',
        'with_count',
        'without_relations',
    ];

    /**
     * Cached range patterns for range filter detection.
     *
     * @var array|null
     */
    protected static $rangePatterns;

    /**
     * Initialize a new filter instance.
     *
     * @return void
     */
    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->session = $request->hasSession() ? $request->session() : session();
    }

    /**
     * Apply the filters on the builder.
     */
    public function apply(Builder $builder): Builder
    {
        $this->builder = $builder;

        // PERFORMANCE OPTIMIZATION: Filter out non-filter parameters early
        // This avoids iterating through pagination, sorting, and relationship params
        $filterParams = array_diff_key(
            $this->request->all(),
            array_flip(static::$skipParams)
        );

        foreach ($filterParams as $name => $value) {
            $this->applyFilter($name, $value);
        }

        $this->applyRangeFilters();

        // CRITICAL: Always apply queryForInternal/queryForPublic for data isolation
        if (Http::isInternalRequest($this->request) && method_exists($this, 'queryForInternal')) {
            call_user_func([$this, 'queryForInternal']);
        }

        if (Http::isPublicRequest($this->request) && method_exists($this, 'queryForPublic')) {
            call_user_func([$this, 'queryForPublic']);
        }

        return $this->builder;
    }

    /**
     * Find dynamically named column filters and apply them.
     *
     * PERFORMANCE OPTIMIZATIONS:
     * - Early return for empty values
     * - Cache method existence checks
     * - Direct method calls instead of call_user_func_array
     *
     * @param string $name
     *
     * @return void
     */
    private function applyFilter($name, $value)
    {
        // PERFORMANCE OPTIMIZATION: Skip empty values early
        if (empty($value)) {
            return;
        }

        // PERFORMANCE OPTIMIZATION: Check cache first to avoid repeated reflection
        $cacheKey = $name;
        if (!isset($this->methodCache[$cacheKey])) {
            $methodNames                  = [$name, Str::camel($name)];
            $this->methodCache[$cacheKey] = null;

            foreach ($methodNames as $methodName) {
                if (method_exists($this, $methodName)) {
                    $this->methodCache[$cacheKey] = $methodName;
                    break;
                }
            }

            // Check if it's an expansion (only if method not found)
            if (!$this->methodCache[$cacheKey] && static::isExpansion($name)) {
                $this->methodCache[$cacheKey] = $name;
            }
        }

        // PERFORMANCE OPTIMIZATION: Direct method call instead of call_user_func_array
        if ($this->methodCache[$cacheKey]) {
            $this->{$this->methodCache[$cacheKey]}($value);
        }
    }

    /**
     * Apply dynamically named range filters.
     *
     * PERFORMANCE OPTIMIZATION: Early return if no range parameters detected
     *
     * @return void
     */
    private function applyRangeFilters()
    {
        // PERFORMANCE OPTIMIZATION: Quick check if any range params exist
        // This avoids expensive processing when no range filters are present
        $hasRangeParams = false;
        foreach (array_keys($this->request->all()) as $key) {
            if (preg_match('/_(?:after|before|from|to|min|max|start|end|gte|lte|greater|less)$/', $key)) {
                $hasRangeParams = true;
                break;
            }
        }

        if (!$hasRangeParams) {
            return;
        }

        $ranges = $this->getRangeFilterCallbacks();

        if (!is_array($ranges)) {
            return;
        }

        foreach ($ranges as $method => $values) {
            if (method_exists($this, $method)) {
                call_user_func_array([$this, $method], $values);
            }
        }
    }

    /**
     * Find standard range filters methods.
     *
     * PERFORMANCE OPTIMIZATION: Initialize range patterns once
     */
    private function getRangeFilterCallbacks(): array
    {
        // PERFORMANCE OPTIMIZATION: Initialize patterns once as static
        if (static::$rangePatterns === null) {
            static::$rangePatterns = ['after:before', 'from:to', 'min:max', 'start:end', 'gte:lte', 'greater:less'];
        }

        $ranges = static::$rangePatterns;

        $prepositions = Arr::flatten(
            array_map(
                function ($range) {
                    return explode(':', $range);
                },
                $ranges
            )
        );

        $callbacks = collect($this->request->all())
            ->keys()
            ->filter(
                function ($param) use ($prepositions) {
                    return Str::endsWith($param, $prepositions);
                }
            )->mapWithKeys(
                function ($param) use ($prepositions, $ranges) {
                    $column      = Str::replaceLast('_', '', str_replace($prepositions, '', $param));
                    $preposition = Arr::last(explode('_', $param));

                    if (empty($column)) {
                        return [];
                    }

                    // find the range
                    $range = Arr::first(
                        $ranges,
                        function ($range) use ($preposition) {
                            return Str::contains($range, $preposition);
                        }
                    );

                    // get values
                    $values = $this->request->all(
                        array_map(
                            function ($preposition) use ($column) {
                                return $column . '_' . $preposition;
                            },
                            explode(':', $range)
                        )
                    );

                    // create callback fn name
                    $callback = Str::camel($column) . 'Between';

                    return [$callback => array_values($values)];
                }
            )
            ->toArray();

        return $callbacks;
    }
}
