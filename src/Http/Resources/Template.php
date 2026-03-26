<?php

namespace Fleetbase\Http\Resources;

use Fleetbase\Support\Http;

class Template extends FleetbaseResource
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
            'id'                     => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                   => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'              => $this->when(Http::isInternalRequest(), $this->public_id),
            'company_uuid'           => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'created_by_uuid'        => $this->when(Http::isInternalRequest(), $this->created_by_uuid),
            'updated_by_uuid'        => $this->when(Http::isInternalRequest(), $this->updated_by_uuid),
            'background_image_uuid'  => $this->when(Http::isInternalRequest(), $this->background_image_uuid),
            'name'                   => $this->name,
            'description'            => $this->description,
            'context_type'           => $this->context_type,
            'unit'                   => $this->unit,
            'width'                  => $this->width,
            'height'                 => $this->height,
            'orientation'            => $this->orientation,
            'margins'                => $this->margins,
            'background_color'       => $this->background_color,
            'background_image'       => $this->whenLoaded('backgroundImage', fn () => new File($this->backgroundImage)),
            'content'                => $this->content ?? [],
            'element_schemas'        => $this->element_schemas ?? [],
            'queries'                => TemplateQuery::collection($this->whenLoaded('queries')),
            'is_default'             => $this->is_default,
            'is_system'              => $this->is_system,
            'is_public'              => $this->is_public,
            'updated_at'             => $this->updated_at,
            'created_at'             => $this->created_at,
        ];
    }
}
