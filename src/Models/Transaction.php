<?php

namespace Fleetbase\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Casts\Money;
use Fleetbase\Casts\PolymorphicType;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasApiModelCache;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Transaction.
 *
 * The platform-wide financial transaction primitive. Every monetary movement
 * on the Fleetbase platform — dispatch charges, wallet operations, gateway
 * payments, refunds, earnings, adjustments — is recorded as a Transaction.
 *
 * Extensions (e.g. Ledger) extend this model to add domain-specific
 * relationships (journal entries, invoices) without altering this schema.
 *
 * Monetary values are always stored as integers in the smallest currency unit
 * (cents). For example, USD 10.50 is stored as 1050.
 *
 * Five polymorphic roles capture the full context of any transaction:
 *   - subject:   the primary owner of the transaction record
 *   - payer:     the entity funds flow FROM
 *   - payee:     the entity funds flow TO
 *   - initiator: what triggered or authorised the transaction
 *   - context:   the related business object (Order, Invoice, etc.)
 */
class Transaction extends Model
{
    use HasUuid;
    use HasPublicId;
    use HasApiModelBehavior;
    use HasApiModelCache;
    use HasMetaAttributes;
    use SoftDeletes;

    /**
     * The database table used by the model.
     */
    protected $table = 'transactions';

    /**
     * The type of public ID to generate.
     */
    protected $publicIdType = 'transaction';

    /**
     * The attributes that can be queried via search.
     */
    protected $searchableColumns = ['description', 'reference', 'gateway_transaction_id', 'type', 'status'];

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'public_id',
        'company_uuid',

        // Five polymorphic roles
        'subject_uuid',
        'subject_type',
        'payer_uuid',
        'payer_type',
        'payee_uuid',
        'payee_type',
        'initiator_uuid',
        'initiator_type',
        'context_uuid',
        'context_type',

        // Classification
        'type',
        'direction',
        'status',

        // Monetary (all in smallest currency unit / cents)
        'amount',
        'fee_amount',
        'tax_amount',
        'net_amount',
        'currency',
        'exchange_rate',
        'settled_currency',
        'settled_amount',
        'balance_after',

        // Gateway
        'gateway',
        'gateway_uuid',
        'gateway_transaction_id',
        'gateway_response',
        'payment_method',
        'payment_method_last4',
        'payment_method_brand',

        // Idempotency and linkage
        'reference',
        'parent_transaction_uuid',

        // Descriptive
        'description',
        'notes',

        // Failure info
        'failure_reason',
        'failure_code',

        // Reporting
        'period',
        'tags',

        // Traceability
        'ip_address',

        // Misc
        'meta',

        // Deprecated aliases (kept for backward compatibility)
        'owner_uuid',
        'owner_type',
        'customer_uuid',
        'customer_type',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected $casts = [
        'amount'           => Money::class,
        'fee_amount'       => Money::class,
        'tax_amount'       => Money::class,
        'net_amount'       => Money::class,
        'balance_after'    => Money::class,
        'settled_amount'   => Money::class,
        'exchange_rate'    => 'decimal:8',
        'gateway_response' => Json::class,
        'tags'             => Json::class,
        'meta'             => Json::class,
        'subject_type'     => PolymorphicType::class,
        'payer_type'       => PolymorphicType::class,
        'payee_type'       => PolymorphicType::class,
        'initiator_type'   => PolymorphicType::class,
        'context_type'     => PolymorphicType::class,
        'customer_type'    => PolymorphicType::class,
        'settled_at'       => 'datetime',
        'voided_at'        => 'datetime',
        'reversed_at'      => 'datetime',
        'expires_at'       => 'datetime',
    ];

    /**
     * Dynamic attributes appended to the model's JSON form.
     */
    protected $appends = [];

    /**
     * The attributes excluded from the model's JSON form.
     */
    protected $hidden = ['gateway_response'];

    // =========================================================================
    // Direction Constants
    // =========================================================================

    /** Money flowing into the subject's account. */
    public const DIRECTION_CREDIT = 'credit';

    /** Money flowing out of the subject's account. */
    public const DIRECTION_DEBIT = 'debit';

    // =========================================================================
    // Status Constants
    // =========================================================================

    public const STATUS_PENDING   = 'pending';
    public const STATUS_SUCCESS   = 'success';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_REVERSED  = 'reversed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_VOIDED    = 'voided';
    public const STATUS_EXPIRED   = 'expired';

    // =========================================================================
    // Type Constants — Platform-wide taxonomy
    // =========================================================================

    // FleetOps
    public const TYPE_DISPATCH      = 'dispatch';
    public const TYPE_SERVICE_QUOTE = 'service_quote';

    // Wallet operations
    public const TYPE_WALLET_DEPOSIT      = 'wallet_deposit';
    public const TYPE_WALLET_WITHDRAWAL   = 'wallet_withdrawal';
    public const TYPE_WALLET_TRANSFER_IN  = 'wallet_transfer_in';
    public const TYPE_WALLET_TRANSFER_OUT = 'wallet_transfer_out';
    public const TYPE_WALLET_EARNING      = 'wallet_earning';
    public const TYPE_WALLET_PAYOUT       = 'wallet_payout';
    public const TYPE_WALLET_FEE          = 'wallet_fee';
    public const TYPE_WALLET_ADJUSTMENT   = 'wallet_adjustment';
    public const TYPE_WALLET_REFUND       = 'wallet_refund';

    // Gateway payments
    public const TYPE_GATEWAY_CHARGE       = 'gateway_charge';
    public const TYPE_GATEWAY_REFUND       = 'gateway_refund';
    public const TYPE_GATEWAY_SETUP_INTENT = 'gateway_setup_intent';

    // Invoice
    public const TYPE_INVOICE_PAYMENT = 'invoice_payment';
    public const TYPE_INVOICE_REFUND  = 'invoice_refund';

    // System
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_REVERSAL   = 'reversal';
    public const TYPE_CORRECTION = 'correction';

    // =========================================================================
    // Relationships
    // =========================================================================

    /**
     * The primary owner of this transaction record.
     * Replaces the deprecated owner() relationship.
     */
    public function subject(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'subject_type', 'subject_uuid')->withoutGlobalScopes();
    }

    /**
     * The entity funds flow FROM.
     */
    public function payer(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'payer_type', 'payer_uuid')->withoutGlobalScopes();
    }

    /**
     * The entity funds flow TO.
     */
    public function payee(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'payee_type', 'payee_uuid')->withoutGlobalScopes();
    }

    /**
     * What triggered or authorised this transaction.
     */
    public function initiator(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'initiator_type', 'initiator_uuid')->withoutGlobalScopes();
    }

    /**
     * The related business object (Order, Invoice, PurchaseRate, etc.).
     */
    public function context(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'context_type', 'context_uuid')->withoutGlobalScopes();
    }

    /**
     * The parent transaction this record is a refund, reversal, or split of.
     */
    public function parentTransaction(): BelongsTo
    {
        return $this->belongsTo(static::class, 'parent_transaction_uuid', 'uuid');
    }

    /**
     * Child transactions (refunds, reversals, splits) of this transaction.
     */
    public function childTransactions(): HasMany
    {
        return $this->hasMany(static::class, 'parent_transaction_uuid', 'uuid');
    }

    /**
     * Line items belonging to this transaction.
     */
    public function items(): HasMany
    {
        return $this->hasMany(TransactionItem::class, 'transaction_uuid', 'uuid');
    }

    /**
     * @deprecated use subject() instead
     */
    public function owner(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'owner_type', 'owner_uuid')->withoutGlobalScopes();
    }

    /**
     * @deprecated Use payer() instead. The customer was semantically the payer.
     */
    public function customer(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'customer_type', 'customer_uuid')->withoutGlobalScopes();
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope to credit transactions (money in).
     */
    public function scopeCredits($query)
    {
        return $query->where('direction', self::DIRECTION_CREDIT);
    }

    /**
     * Scope to debit transactions (money out).
     */
    public function scopeDebits($query)
    {
        return $query->where('direction', self::DIRECTION_DEBIT);
    }

    /**
     * Scope to successful transactions.
     */
    public function scopeSuccessful($query)
    {
        return $query->where('status', self::STATUS_SUCCESS);
    }

    /**
     * Scope to pending transactions.
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope to failed transactions.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope to a specific transaction type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to transactions for a specific accounting period (YYYY-MM).
     */
    public function scopeForPeriod($query, string $period)
    {
        return $query->where('period', $period);
    }

    /**
     * Scope to transactions where the given model is the subject.
     */
    public function scopeForSubject($query, \Illuminate\Database\Eloquent\Model $subject)
    {
        return $query->where('subject_uuid', $subject->uuid)
                     ->where('subject_type', get_class($subject));
    }

    /**
     * Scope to transactions where the given model is the payer.
     */
    public function scopeForPayer($query, \Illuminate\Database\Eloquent\Model $payer)
    {
        return $query->where('payer_uuid', $payer->uuid)
                     ->where('payer_type', get_class($payer));
    }

    /**
     * Scope to transactions where the given model is the payee.
     */
    public function scopeForPayee($query, \Illuminate\Database\Eloquent\Model $payee)
    {
        return $query->where('payee_uuid', $payee->uuid)
                     ->where('payee_type', get_class($payee));
    }

    /**
     * Scope to transactions related to a specific business context object.
     */
    public function scopeForContext($query, \Illuminate\Database\Eloquent\Model $context)
    {
        return $query->where('context_uuid', $context->uuid)
                     ->where('context_type', get_class($context));
    }

    /**
     * Scope to refund/reversal transactions (children of another transaction).
     */
    public function scopeRefunds($query)
    {
        return $query->whereNotNull('parent_transaction_uuid');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Whether this transaction is a credit (money in to subject).
     */
    public function isCredit(): bool
    {
        return $this->direction === self::DIRECTION_CREDIT;
    }

    /**
     * Whether this transaction is a debit (money out from subject).
     */
    public function isDebit(): bool
    {
        return $this->direction === self::DIRECTION_DEBIT;
    }

    /**
     * Whether this transaction completed successfully.
     */
    public function isSuccessful(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /**
     * Whether this transaction is still pending.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Whether this transaction failed.
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Whether this transaction is a refund or reversal of another transaction.
     */
    public function isRefund(): bool
    {
        return $this->parent_transaction_uuid !== null;
    }

    /**
     * Whether this transaction has been voided.
     */
    public function isVoided(): bool
    {
        return $this->status === self::STATUS_VOIDED || $this->voided_at !== null;
    }

    /**
     * Whether this transaction has been reversed.
     */
    public function isReversed(): bool
    {
        return $this->status === self::STATUS_REVERSED || $this->reversed_at !== null;
    }

    /**
     * Whether this transaction has settled.
     */
    public function isSettled(): bool
    {
        return $this->settled_at !== null;
    }

    /**
     * Whether this transaction has expired (e.g. an uncaptured pre-auth).
     */
    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED
               || ($this->expires_at !== null && $this->expires_at->isPast());
    }

    // =========================================================================
    // Static Helpers
    // =========================================================================

    /**
     * Generate an internal transaction reference number.
     * Format: TR + N random digits.
     */
    public static function generateInternalNumber(int $length = 10): string
    {
        $number = 'TR';
        for ($i = 0; $i < $length; $i++) {
            $number .= mt_rand(0, 9);
        }

        return $number;
    }

    /**
     * Generate a unique transaction reference number.
     * Ensures uniqueness against the gateway_transaction_id column.
     */
    public static function generateNumber(int $length = 10): string
    {
        $n  = self::generateInternalNumber($length);
        $tr = self::where('gateway_transaction_id', $n)->withTrashed()->first();

        while ($tr !== null && $n === $tr->gateway_transaction_id) {
            $n  = self::generateInternalNumber($length);
            $tr = self::where('gateway_transaction_id', $n)->withTrashed()->first();
        }

        return $n;
    }
}
