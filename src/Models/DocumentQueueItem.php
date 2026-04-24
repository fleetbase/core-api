<?php

namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentQueueItem extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use SoftDeletes;

    /**
     * Status constants — drive the safe-failure flow.
     */
    public const STATUS_RECEIVED      = 'received';
    public const STATUS_PROCESSING    = 'processing';
    public const STATUS_PARSED        = 'parsed';
    public const STATUS_MATCHED       = 'matched';
    public const STATUS_NEEDS_REVIEW  = 'needs_review';
    public const STATUS_FAILED        = 'failed';

    public const TYPE_CARRIER_INVOICE   = 'carrier_invoice';
    public const TYPE_BOL               = 'bol';
    public const TYPE_POD               = 'pod';
    public const TYPE_RATE_CONFIRMATION = 'rate_confirmation';
    public const TYPE_INSURANCE_CERT    = 'insurance_cert';
    public const TYPE_CUSTOMS           = 'customs';
    public const TYPE_OTHER             = 'other';
    public const TYPE_UNKNOWN           = 'unknown';

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_EMAIL  = 'email';
    public const SOURCE_EDI    = 'edi';
    public const SOURCE_API    = 'api';

    /** Minimum match confidence to auto-create CarrierInvoice */
    public const MIN_INVOICE_CREATION_CONFIDENCE = 0.85;

    protected $table = 'document_queue_items';
    protected $publicIdType = 'doc';

    protected $fillable = [
        'company_uuid', 'file_uuid',
        'source', 'document_type', 'status',
        'raw_content', 'parsed_data',
        'matched_order_uuid', 'matched_shipment_uuid', 'matched_carrier_invoice_uuid',
        'match_confidence', 'match_method',
        'error_message', 'processed_at', 'meta',
    ];

    protected $casts = [
        'parsed_data'      => Json::class,
        'meta'             => Json::class,
        'match_confidence' => 'decimal:2',
        'processed_at'     => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_uuid', 'uuid');
    }

    public function file()
    {
        return $this->belongsTo(File::class, 'file_uuid', 'uuid');
    }

    public function matchedOrder()
    {
        return $this->belongsTo(\Fleetbase\FleetOps\Models\Order::class, 'matched_order_uuid', 'uuid');
    }

    public function matchedShipment()
    {
        return $this->belongsTo(\Fleetbase\FleetOps\Models\Shipment::class, 'matched_shipment_uuid', 'uuid');
    }

    public function matchedCarrierInvoice()
    {
        return $this->belongsTo(\Fleetbase\Ledger\Models\CarrierInvoice::class, 'matched_carrier_invoice_uuid', 'uuid');
    }

    public function scopeNeedsReview($query)
    {
        return $query->where('status', self::STATUS_NEEDS_REVIEW);
    }

    public function scopeForReprocessing($query)
    {
        return $query->whereIn('status', [self::STATUS_RECEIVED, self::STATUS_NEEDS_REVIEW, self::STATUS_FAILED]);
    }
}
