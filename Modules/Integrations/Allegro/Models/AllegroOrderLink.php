<?php

namespace Modules\Integrations\Allegro\Models;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Integrations\Allegro\Support\AllegroOfferUrl;

class AllegroOrderLink extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'invoice_required' => 'boolean',
        'smart' => 'boolean',
        'declared_packages' => 'integer',
        'external_updated_at' => 'immutable_datetime',
        'imported_at' => 'immutable_datetime',
        'details_snapshot' => 'array',
        'details_refreshed_at' => 'immutable_datetime',
        'fees_snapshot' => 'array',
        'fees_refreshed_at' => 'immutable_datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(AllegroAccount::class, 'allegro_account_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(AllegroOrderPayment::class);
    }

    public function offerUrl(?string $offerId): ?string
    {
        return AllegroOfferUrl::make($this->environment, $offerId);
    }
}
