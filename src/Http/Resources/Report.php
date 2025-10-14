<?php

namespace Fleetbase\Http\Resources;

use Fleetbase\Support\Http;

class Report extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'                           => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'public_id'                    => $this->when(Http::isInternalRequest(), $this->public_id),
            'uuid'                         => $this->uuid,
            'title'                        => $this->title,
            'type'                         => $this->type,
            'status'                       => $this->status,
            'subject_type'                 => $this->subject_type,
            'subject_uuid'                 => $this->subject_uuid,
            'subject_name'                 => $this->subject_name,
            'period_start'                 => $this->period_start?->toISOString(),
            'period_end'                   => $this->period_end?->toISOString(),
            'period_duration_days'         => $this->period_duration_days,
            'query_config'                 => $this->query_config,
            'result_columns'               => $this->result_columns,
            'last_executed_at'             => $this->last_executed_at?->toISOString(),
            'execution_time'               => $this->execution_time,
            'row_count'                    => $this->row_count,
            'is_scheduled'                 => $this->is_scheduled,
            'schedule_config'              => $this->schedule_config,
            'export_formats'               => $this->export_formats,
            'is_generated'                 => $this->is_generated,
            'tags'                         => $this->tags,
            'meta'                         => $this->meta,
            'options'                      => $this->options,
            'body'                         => $this->body,
            'data'                         => $this->data,
            'created_at'                   => $this->created_at?->toISOString(),
            'updated_at'                   => $this->updated_at?->toISOString(),
            'created_by'                   => $this->whenLoaded('createdBy', function () {
                return [
                    'uuid'  => $this->createdBy->uuid,
                    'name'  => $this->createdBy->name,
                    'email' => $this->createdBy->email,
                ];
            }),
            'updated_by' => $this->whenLoaded('updatedBy', function () {
                return [
                    'uuid'  => $this->updatedBy->uuid,
                    'name'  => $this->updatedBy->name,
                    'email' => $this->updatedBy->email,
                ];
            }),
            'subject' => $this->whenLoaded('subject', function () {
                return [
                    'type' => $this->subject_type,
                    'uuid' => $this->subject_uuid,
                    'name' => $this->subject_name,
                ];
            }),
        ];
    }

    /**
     * Get additional data that should be returned with the resource array.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return array
     */
    public function with($request)
    {
        return [
            'meta' => [
                'can_execute'   => $this->has_valid_query,
                'can_export'    => $this->is_generated,
                'can_schedule'  => $this->has_valid_query,
                'last_activity' => $this->updated_at?->toISOString(),
            ],
        ];
    }
}
