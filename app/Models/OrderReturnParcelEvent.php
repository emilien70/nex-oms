<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Modules\Shipments\Models\Shipment;

class OrderReturnParcelEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['occurred_at' => UtcDateTime::class, 'automation_pending' => 'boolean'];

    public function statusLabel(): string
    {
        if ($this->tracking_status === 'returned') {
            return $this->provider_status === 'returning_to_sender' ? 'Wraca do klienta' : 'Zwrócona klientowi';
        }

        return Shipment::omsStatusLabelFor($this->tracking_status);
    }
}
