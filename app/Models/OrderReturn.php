<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Integrations\Allegro\Models\AllegroAccount;
use Modules\Invoices\Models\Invoice;

class OrderReturn extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'total_value' => 'decimal:2', 'order_snapshot' => 'array',
        'submitted_at' => 'immutable_datetime', 'status_observed_at' => 'immutable_datetime',
        'synced_at' => 'immutable_datetime', 'next_sync_at' => 'immutable_datetime',
        'local_status_changed_at' => 'immutable_datetime', 'local_status_id' => 'integer', 'lock_version' => 'integer',
    ];

    public const STATUSES = [
        'CREATED' => 'Zgłoszony', 'DISPATCHED' => 'Nadany', 'IN_TRANSIT' => 'W drodze',
        'DELIVERED' => 'Dostarczony', 'FINISHED' => 'Zakończony', 'FINISHED_APT' => 'Zakończony przez Allegro',
        'REJECTED' => 'Odrzucony', 'COMMISSION_REFUND_CLAIMED' => 'Złożono wniosek o zwrot prowizji',
        'COMMISSION_REFUNDED' => 'Zwrócono prowizję', 'WAREHOUSE_DELIVERED' => 'W magazynie Allegro',
        'WAREHOUSE_VERIFICATION' => 'Weryfikacja w magazynie Allegro',
    ];

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? 'Nieznany status: '.$this->status;
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'CREATED' => '#ffad42', 'DISPATCHED', 'IN_TRANSIT' => '#429de0',
            'DELIVERED', 'FINISHED', 'FINISHED_APT', 'COMMISSION_REFUNDED' => '#147a49',
            'REJECTED' => '#d84738', default => '#747d89',
        };
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AllegroAccount::class, 'allegro_account_id');
    }

    public function localStatus(): BelongsTo
    {
        return $this->belongsTo(ReturnStatusSetting::class, 'local_status_id');
    }

    public function localStatusLabel(): string
    {
        return $this->localStatus?->name ?? 'Brak statusu';
    }

    public function sourceLabel(): string
    {
        return $this->account_name ?: match ($this->source) {
            'manual' => 'Ręczne', 'prestashop' => 'PrestaShop', 'allegro' => 'Allegro', default => $this->source ?: 'Inne'
        };
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderReturnItem::class)->orderBy('position');
    }

    public function parcels(): HasMany
    {
        return $this->hasMany(OrderReturnParcel::class)->orderBy('position');
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderReturnEvent::class)->orderByDesc('id');
    }

    public function refundAllocations(): HasMany
    {
        return $this->hasMany(OrderReturnRefundAllocation::class);
    }

    public function correction(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }
}
