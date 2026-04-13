<?php

namespace Fleetbase\Http\Resources;

use Fleetbase\Support\Http;

class DocumentQueueItem extends FleetbaseResource
{
    public function toArray($request)
    {
        return [
            'id'                            => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                          => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'                     => $this->when(Http::isInternalRequest(), $this->public_id),
            'source'                        => $this->source,
            'document_type'                 => $this->document_type,
            'status'                        => $this->status,
            'matched_order_uuid'            => $this->when(Http::isInternalRequest(), $this->matched_order_uuid),
            'matched_shipment_uuid'         => $this->when(Http::isInternalRequest(), $this->matched_shipment_uuid),
            'matched_carrier_invoice_uuid'  => $this->when(Http::isInternalRequest(), $this->matched_carrier_invoice_uuid),
            'match_confidence'              => $this->match_confidence,
            'match_method'                  => $this->match_method,
            'parsed_data'                   => $this->parsed_data,
            'error_message'                 => $this->error_message,
            'processed_at'                  => $this->processed_at,
            'file'                          => $this->whenLoaded('file'),
            'meta'                          => $this->meta,
            'created_at'                    => $this->created_at,
            'updated_at'                    => $this->updated_at,
        ];
    }
}
