<?php

namespace Modules\Integrations\NexPrinter\Models;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Integrations\NexPrinter\Enums\NexPrintJobStatus;
use Modules\Invoices\Models\Invoice;

class NexPrintJob extends Model
{
    public const ORIGIN_MANUAL = 'manual';

    public const ORIGIN_AUTOMATION = 'automation';

    protected $fillable = [
        'public_id',
        'agent_id',
        'printer_id',
        'order_id',
        'invoice_id',
        'document_type',
        'document_number',
        'printer_name_snapshot',
        'payload_disk',
        'payload_path',
        'payload_filename',
        'content_type',
        'payload_sha256',
        'payload_size',
        'copies',
        'status',
        'origin',
        'idempotency_key',
        'lease_token_hash',
        'claimed_at',
        'lease_expires_at',
        'downloaded_at',
        'spooled_at',
        'failed_at',
        'expires_at',
        'last_error_code',
        'last_error_message',
    ];

    protected $hidden = [
        'lease_token_hash',
    ];

    protected $casts = [
        'status' => NexPrintJobStatus::class,
        'payload_size' => 'integer',
        'copies' => 'integer',
        'claimed_at' => 'datetime',
        'lease_expires_at' => 'datetime',
        'downloaded_at' => 'datetime',
        'spooled_at' => 'datetime',
        'failed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(NexPrinterAgent::class, 'agent_id');
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(NexPrinter::class, 'printer_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(NexPrintJobEvent::class, 'print_job_id')
            ->orderBy('occurred_at')
            ->orderBy('id');
    }
}
