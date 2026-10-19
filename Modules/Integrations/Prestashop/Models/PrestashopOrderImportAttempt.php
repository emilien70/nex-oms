<?php

namespace Modules\Integrations\Prestashop\Models;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrestashopOrderImportAttempt extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_IMPORTED = 'imported';

    public const STATUS_FAILED = 'failed';

    protected $table = 'prestashop_order_import_attempts';

    protected $fillable = [
        'prestashop_account_id',
        'order_id',
        'external_order_id',
        'external_reference',
        'status',
        'attempts',
        'discovered_at',
        'external_created_at',
        'last_attempt_at',
        'imported_at',
        'last_error',
    ];

    protected $casts = [
        'external_order_id' => 'integer',
        'attempts' => 'integer',
        'discovered_at' => 'immutable_datetime',
        'external_created_at' => 'immutable_datetime',
        'last_attempt_at' => 'immutable_datetime',
        'imported_at' => 'immutable_datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(PrestashopAccount::class, 'prestashop_account_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }
}
