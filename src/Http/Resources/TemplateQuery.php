<?php

namespace Fleetbase\Http\Resources;

use Fleetbase\Support\Http;

class TemplateQuery extends FleetbaseResource
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
            'id'            => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'          => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'     => $this->when(Http::isInternalRequest(), $this->public_id),
            'company_uuid'  => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'template_uuid' => $this->when(Http::isInternalRequest(), $this->template_uuid),
            'model_type'    => $this->model_type,
            'variable_name' => $this->variable_name,
            'label'         => $this->label,
            'conditions'    => $this->conditions ?? [],
            'sort'          => $this->sort ?? [],
            'limit'         => $this->limit,
            'with'          => $this->with ?? [],
            'updated_at'    => $this->updated_at,
            'created_at'    => $this->created_at,
        ];
    }
}
