<?php

namespace Modules\Invoices\Models;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Ksef\Models\KsefInvoiceSubmission;

class Invoice extends Model
{
    protected $fillable = [
        'order_id',
        'invoice_series_id',
        'document_type',
        'status',
        'number',
        'sequence_number',
        'numbering_period_key',
        'number_format_snapshot',
        'series_name_snapshot',
        'issue_date',
        'sale_date',
        'payment_due_date',
        'issued_at',
        'lock_version',
        'source_snapshot_hash',
        'last_refreshed_at',
        'proforma_superseded_at',
        'superseded_by_invoice_id',
        'corrected_invoice_id',
        'previous_correction_id',
        'correction_reason',
        'correction_totals_snapshot',
        'order_reference_snapshot',
        'seller_name_snapshot',
        'seller_tax_id_snapshot',
        'buyer_name_snapshot',
        'buyer_tax_id_snapshot',
        'recipient_name_snapshot',
        'seller_snapshot',
        'buyer_snapshot',
        'recipient_snapshot',
        'issuer_snapshot',
        'order_snapshot',
        'payment_snapshot',
        'shipping_snapshot',
        'series_settings_snapshot',
        'tax_summary_snapshot',
        'tax_metadata_snapshot',
        'additional_information_text',
        'currency',
        'total_net',
        'total_vat',
        'total_gross',
        'paid_amount',
        'amount_due',
    ];

    protected $casts = [
        'document_type' => InvoiceDocumentType::class,
        'status' => InvoiceDocumentStatus::class,
        'issue_date' => 'date',
        'sale_date' => 'date',
        'payment_due_date' => 'date',
        'issued_at' => 'datetime',
        'finalized_at' => 'datetime',
        'last_refreshed_at' => 'datetime',
        'proforma_superseded_at' => 'datetime',
        'lock_version' => 'integer',
        'sequence_number' => 'integer',
        'correction_totals_snapshot' => 'array',
        'seller_snapshot' => 'array',
        'buyer_snapshot' => 'array',
        'recipient_snapshot' => 'array',
        'issuer_snapshot' => 'array',
        'order_snapshot' => 'array',
        'payment_snapshot' => 'array',
        'shipping_snapshot' => 'array',
        'series_settings_snapshot' => 'array',
        'tax_summary_snapshot' => 'array',
        'tax_metadata_snapshot' => 'array',
        'total_net' => 'decimal:2',
        'total_vat' => 'decimal:2',
        'total_gross' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'amount_due' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(InvoiceSeries::class, 'invoice_series_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('position')->orderBy('id');
    }

    public function documentSlots(): HasMany
    {
        return $this->hasMany(OrderDocumentSlot::class);
    }

    public function supersededByInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_invoice_id');
    }

    public function supersededProformas(): HasMany
    {
        return $this->hasMany(self::class, 'superseded_by_invoice_id');
    }

    public function correctedInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrected_invoice_id');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(self::class, 'corrected_invoice_id')
            ->where('document_type', InvoiceDocumentType::Correction->value);
    }

    public function previousCorrection(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_correction_id');
    }

    public function nextCorrections(): HasMany
    {
        return $this->hasMany(self::class, 'previous_correction_id')
            ->where('document_type', InvoiceDocumentType::Correction->value);
    }

    public function ksefSubmissions(): HasMany
    {
        return $this->hasMany(KsefInvoiceSubmission::class);
    }

    public function isDraft(): bool
    {
        return $this->status === InvoiceDocumentStatus::Draft;
    }

    public function isIssued(): bool
    {
        return $this->status === InvoiceDocumentStatus::Issued;
    }

    public function isInvoice(): bool
    {
        return $this->document_type === InvoiceDocumentType::Invoice;
    }

    public function isProforma(): bool
    {
        return $this->document_type === InvoiceDocumentType::Proforma;
    }

    public function isCorrection(): bool
    {
        return $this->document_type === InvoiceDocumentType::Correction;
    }

    public function isProformaSuperseded(): bool
    {
        return $this->isProforma() && $this->proforma_superseded_at !== null;
    }

    public function isFinalized(): bool
    {
        return $this->finalized_at !== null;
    }
}
