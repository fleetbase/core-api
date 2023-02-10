<?php

namespace Fleetbase\Http\Filter;

use Fleetbase\Support\Http;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;

abstract class Filter
{
    /**
     * The request instance.
     *
     * @var \Illuminate\Http\Request
     */
    protected $request;

    /**
     * The builder instance.
     *
     * @var \Illuminate\Database\Eloquent\Builder
     */
    protected $builder;

    /**
     * Initialize a new filter instance.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     */
    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * Apply the filters on the builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function apply(Builder $builder): Builder
    {
        $this->builder = $builder;

        foreach ($this->request->all() as $name => $value) {
            if (method_exists($this, $name)) {
                call_user_func_array([$this, $name], array_filter([$value]));
            }

            // try camelcase too ex: `include_tags` could be `includeTags`
            $camelcaseName = Str::camel($name);

            if (method_exists($this, $camelcaseName)) {
                call_user_func_array([$this, $camelcaseName], array_filter([$value]));
            }

            // attempt to find and check ranged methods ex: `created_from` and `created_to` could be queried by `createdBetween($start, $end)`
            $ranges = $this->getRangeFilterCallbacks();

            if (!is_array($ranges)) {
                continue;
            }

            foreach ($ranges as $method => $values) {
                if (method_exists($this, $method)) {
                    call_user_func_array([$this, $method], $values);
                }
            }
        }

        if (Http::isInternalRequest($this->request) && method_exists($this, 'queryForInternal')) {
            call_user_func([$this, 'queryForInternal']);
        }

        return $this->builder;
    }

    private function getRangeFilterCallbacks(): array
    {
        $ranges = ['after:before', 'from:to'];

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
                    $column = Str::replaceLast('_', '', str_replace($prepositions, '', $param));
                    $preposition = Arr::last(explode('_', $param));

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
