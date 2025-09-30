<?php

namespace Fleetbase\Http\Requests;

/**
 * Request for updating an existing report.
 */
class UpdateReportRequest extends FleetbaseRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'                            => 'sometimes|required|string|max:255',
            'type'                             => 'sometimes|required|string|max:100',
            'query_config'                     => 'nullable|array',
            'query_config.select'              => 'required_with:query_config|array|min:1',
            'query_config.select.*.table'      => 'required|string',
            'query_config.select.*.column'     => 'required|string',
            'query_config.select.*.alias'      => 'nullable|string',
            'query_config.select.*.function'   => 'nullable|string|in:COUNT,SUM,AVG,MIN,MAX',
            'query_config.from'                => 'required_with:query_config|string',
            'query_config.joins'               => 'nullable|array',
            'query_config.joins.*.type'        => 'required|string|in:inner,left,right,full',
            'query_config.joins.*.table'       => 'required|string',
            'query_config.joins.*.on'          => 'required|array|min:1',
            'query_config.where'               => 'nullable|array',
            'query_config.where.*.column'      => 'required|string',
            'query_config.where.*.operator'    => 'required|string',
            'query_config.where.*.value'       => 'required',
            'query_config.where.*.logic'       => 'nullable|string|in:AND,OR',
            'query_config.groupBy'             => 'nullable|array',
            'query_config.having'              => 'nullable|array',
            'query_config.orderBy'             => 'nullable|array',
            'query_config.orderBy.*.column'    => 'required|string',
            'query_config.orderBy.*.direction' => 'required|string|in:asc,desc',
            'query_config.limit'               => 'nullable|integer|min:1|max:10000',
            'period_start'                     => 'nullable|date',
            'period_end'                       => 'nullable|date|after_or_equal:period_start',
            'is_scheduled'                     => 'nullable|boolean',
            'schedule_config'                  => 'nullable|array',
            'export_formats'                   => 'nullable|array',
            'status'                           => 'sometimes|string|in:pending,generating,complete,failed',
        ];
    }
}
