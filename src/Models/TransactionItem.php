<?php

namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Casts\Money;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * TransactionItem
 *
 * A line item belonging to a Transaction. Stores the individual components
 * of a transaction's total — e.g. base fare, surcharges, taxes, discounts.
 *
 * All monetary values (amount, unit_price, tax_amount) are stored as integers
 * in the smallest currency unit (cents) and cast via Fleetbase\Casts\Money.
 */
class TransactionItem extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use HasMetaAttributes;
    use SoftDeletes;

    /**
     * The database table used by the model.
     */
    protected $table = 'transaction_items';

    /**
     * The type of public ID to generate.
     */
    protected $publicIdType = 'transaction_item';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'public_id',
        'transaction_uuid',
        'quantity',
        'unit_price',
        'amount',
        'currency',
        'tax_rate',
        'tax_amount',
        'details',
        'description',
        'code',
        'sort_order',
        'meta',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected $casts = [
        'amount'     => Money::class,
        'unit_price' => Money::class,
        'tax_amount' => Money::class,
        'quantity'   => 'integer',
        'tax_rate'   => 'decimal:2',
        'sort_order' => 'integer',
        'meta'       => Json::class,
    ];

    /**
     * Dynamic attributes appended to the model's JSON form.
     */
    protected $appends = [];

    /**
     * The attributes excluded from the model's JSON form.
     */
    protected $hidden = [];

    // =========================================================================
    // Relationships
    // =========================================================================

    /**
     * The transaction this item belongs to.
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_uuid', 'uuid');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Calculate the line total: quantity * unit_price (in cents).
     * Returns the stored amount if unit_price is not set.
     */
    public function getLineTotal(): int
    {
        if ($this->unit_price > 0 && $this->quantity > 0) {
            return $this->unit_price * $this->quantity;
        }

        return $this->amount;
    }

    /**
     * Calculate the tax amount for this line item based on tax_rate and line total.
     * Returns the stored tax_amount if tax_rate is not set.
     */
    public function calculateTax(): int
    {
        if ($this->tax_rate > 0) {
            return (int) round($this->getLineTotal() * ($this->tax_rate / 100));
        }

        return $this->tax_amount;
    }
}
