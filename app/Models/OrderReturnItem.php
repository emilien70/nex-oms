<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Products\Models\Product;

class OrderReturnItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['quantity' => 'integer', 'unit_price' => 'decimal:2', 'total_value' => 'decimal:2'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function orderReturn(): BelongsTo
    {
        return $this->belongsTo(OrderReturn::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function reasonLabel(): string
    {
        return match ($this->reason) {
            'NONE' => 'Nie podano', 'MISTAKE' => 'Pomyłka przy zakupie', 'NOT_AS_DESCRIBED' => 'Niezgodny z opisem',
            'TRANSPORT' => 'Uszkodzony w transporcie', 'DAMAGED' => 'Uszkodzony', 'INCOMPLETE' => 'Niekompletny',
            'DONT_LIKE_IT' => 'Nie podoba się', 'OVERDUE_DELIVERY' => 'Opóźniona dostawa',
            'HIDDEN_FLAW' => 'Wada ukryta', 'OTHER_FLAW' => 'Inna wada', 'DIFFERENT' => 'Inny niż zamówiony',
            'COUNTERFEIT' => 'Wątpliwości co do oryginalności', 'NOT_NEW' => 'Nie jest nowy',
            'TOO_LARGE' => 'Za duży', 'TOO_SMALL' => 'Za mały', 'NOT_AS_EXPECTED' => 'Nie spełnia oczekiwań',
            'ORDERED_FOR_COMPARISON' => 'Zakup do porównania', default => $this->reason,
        };
    }
}
