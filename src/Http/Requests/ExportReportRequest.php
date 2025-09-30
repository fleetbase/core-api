<?php

namespace Fleetbase\Http\Requests;

/**
 * Request for exporting report data.
 */
class ExportReportRequest extends FleetbaseRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'query_config'                     => 'required|array',
            'query_config.select'              => 'required|array|min:1',
            'query_config.select.*.table'      => 'required|string',
            'query_config.select.*.column'     => 'required|string',
            'query_config.select.*.alias'      => 'nullable|string',
            'query_config.select.*.function'   => 'nullable|string|in:COUNT,SUM,AVG,MIN,MAX',
            'query_config.from'                => 'required|string',
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
            'format'                           => 'required|string|in:json,csv,xlsx',
            'title'                            => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'query_config.required'        => 'Query configuration is required',
            'query_config.select.required' => 'At least one column must be selected',
            'query_config.from.required'   => 'Primary table must be specified',
            'format.required'              => 'Export format is required',
            'format.in'                    => 'Export format must be one of: json, csv, xlsx',
        ];
    }
}
