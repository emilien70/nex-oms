<?php

namespace Modules\Integrations\MacroDroid\Models;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsMessage extends Model
{
    protected $table = 'order_sms_messages';

    protected $guarded = ['id'];

    protected $hidden = ['recipient', 'body', 'intent_hash', 'context_hash', 'claim_hash'];

    protected $casts = [
        'recipient' => 'encrypted', 'body' => 'encrypted', 'customer_recipient' => 'boolean',
        'uses_portal' => 'boolean', 'expires_at' => 'immutable_datetime',
        'claimed_at' => 'immutable_datetime', 'claim_expires_at' => 'immutable_datetime',
        'sending_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime',
        'device_generation' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function label(): string
    {
        return [
            'queued' => 'Oczekuje', 'claimed' => 'Pobrano przez telefon',
            'submitting' => 'Rozpoczeto wysylke w telefonie', 'submitted' => 'Telefon wykonal akcje SMS',
            'failed' => 'Blad', 'uncertain' => 'Niepotwierdzono', 'expired' => 'Wygaslo',
            'cancelled' => 'Anulowano',
        ][$this->status] ?? 'Nieznany stan';
    }

    public function terminal(): bool
    {
        return ! in_array($this->status, ['queued', 'claimed', 'submitting'], true);
    }
}
