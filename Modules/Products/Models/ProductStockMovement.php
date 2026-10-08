<?php

namespace Modules\Products\Models;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Automation\Models\AutomationRun;
use Modules\Automation\Models\AutomationRunStep;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceItem;

class ProductStockMovement extends Model
{
    public const TYPE_INVOICE_ISSUED = 'invoice_issued';

    protected $fillable = [
        'product_id',
        'order_id',
        'order_item_id',
        'invoice_id',
        'invoice_item_id',
        'automation_run_id',
        'automation_run_step_id',
        'movement_type',
        'quantity_delta',
        'quantity_before',
        'quantity_after',
        'idempotency_key',
        'product_snapshot',
        'context',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity_delta' => 'integer',
            'quantity_before' => 'integer',
            'quantity_after' => 'integer',
            'product_snapshot' => 'array',
            'context' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
    }

    public function automationRun(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class);
    }

    public function automationRunStep(): BelongsTo
    {
        return $this->belongsTo(AutomationRunStep::class);
    }
}
