<?php

namespace Fleetbase\Http\Requests;

/**
 * Request for creating a new report.
 */
class CreateReportRequest extends FleetbaseRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'                            => 'required|string|max:255',
            'type'                             => 'required|string|max:100',
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
        ];
    }

    public function messages(): array
    {
        return [
            'title.required'                         => 'Report title is required',
            'type.required'                          => 'Report type is required',
            'query_config.select.required_with'      => 'At least one column must be selected',
            'query_config.from.required_with'        => 'Primary table must be specified',
            'query_config.joins.*.type.in'           => 'Join type must be one of: inner, left, right, full',
            'query_config.where.*.operator.required' => 'Where condition operator is required',
            'query_config.orderBy.*.direction.in'    => 'Order direction must be asc or desc',
            'query_config.limit.max'                 => 'Query limit cannot exceed 10,000 rows',
        ];
    }
}
