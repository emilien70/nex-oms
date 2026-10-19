<?php

namespace Modules\Integrations\Prestashop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrestashopOrderPayment extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCOUNTED = 'accounted';

    public const STATUS_MIRRORED = 'mirrored';

    public const STATUS_REVIEW = 'review';

    protected $table = 'prestashop_order_payments';

    protected $fillable = [
        'prestashop_order_link_id',
        'external_payment_id',
        'transaction_id',
        'amount',
        'currency',
        'method',
        'paid_at',
        'accounting_status',
        'accounted_at',
        'needs_review',
        'safe_error_code',
        'safe_error_message',
        'matched_payment_source',
        'matched_payment_record_id',
    ];

    protected $casts = [
        'external_payment_id' => 'integer',
        'amount' => 'decimal:2',
        'paid_at' => 'immutable_datetime',
        'accounted_at' => 'immutable_datetime',
        'needs_review' => 'boolean',
    ];

    public function orderLink(): BelongsTo
    {
        return $this->belongsTo(PrestashopOrderLink::class, 'prestashop_order_link_id');
    }
}
