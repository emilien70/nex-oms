<?php

namespace Modules\Integrations\Allegro\Models;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Invoices\Models\Invoice;

class AllegroInvoiceExport extends Model
{
    protected $attributes = ['document_type' => 'invoice'];

    protected $guarded = ['id'];

    protected $hidden = ['pdf_path'];

    protected $casts = [
        'manual' => 'boolean',
        'configuration_revision' => 'integer',
        'verification_attempts' => 'integer',
        'file_size' => 'integer',
        'upload_started_at' => 'immutable_datetime',
        'uploaded_at' => 'immutable_datetime',
        'confirmed_at' => 'immutable_datetime',
        'next_check_at' => 'immutable_datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(AllegroAccount::class, 'allegro_account_id');
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(AllegroOrderLink::class, 'allegro_order_link_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function label(): string
    {
        return match ($this->state) {
            'waiting' => $this->error ?: 'Oczekuje na wysłanie',
            'pending', 'uploading' => 'Oczekuje na wysłanie',
            'verifying' => $this->verification_attempts >= 10 && $this->next_check_at === null
                ? 'Przesłano, sprawdź weryfikację Allegro' : 'Przesłano, trwa weryfikacja Allegro',
            'synced' => 'Potwierdzono w Allegro',
            'uncertain' => 'Wynik wymaga sprawdzenia',
            'failed' => $this->document_type === 'correction' ? 'Błąd przekazania korekty' : 'Błąd przekazania faktury',
            default => 'Nie przekazano',
        };
    }
}
