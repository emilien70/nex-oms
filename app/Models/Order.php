<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Automation\Models\AutomationRun;
use Modules\Shipments\Models\Shipment;
use Modules\Shipments\Models\ShipmentCreationAttempt;

class Order extends Model
{
    use SoftDeletes;

    public const STATUS_NEW = 'new';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'source',
        'external_id',
        'status',
        'status_changed_at',
        'star_color',
        'customer_login',
        'customer_email',
        'customer_phone',
        'shipping_name',
        'shipping_company_name',
        'shipping_street',
        'shipping_building_number',
        'shipping_apartment_number',
        'shipping_postal_code',
        'shipping_city',
        'shipping_province',
        'shipping_country_code',
        'shipping_phone',
        'shipping_email',
        'billing_name',
        'billing_company_name',
        'billing_tax_id',
        'billing_street',
        'billing_building_number',
        'billing_apartment_number',
        'billing_postal_code',
        'billing_city',
        'billing_province',
        'billing_country_code',
        'billing_phone',
        'billing_email',
        'currency',
        'total_gross',
        'paid_amount',
        'delivery_cost_gross',
        'shipping_method',
        'pickup_point_name',
        'pickup_point_id',
        'pickup_point_address',
        'pickup_point_postal_code',
        'pickup_point_city',
        'cash_on_delivery',
        'payment_status',
        'payment_method',
        'purchased_at',
        'paid_at',
        'notes',
    ];

    protected $casts = [
        'total_gross' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'delivery_cost_gross' => 'decimal:2',
        'cash_on_delivery' => 'boolean',
        'status_changed_at' => 'datetime',
        'purchased_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public static function statuses(): array
    {
        return [
            self::STATUS_NEW => 'Nowe',
            self::STATUS_PENDING => html_entity_decode('Oczekuj&#261;ce', ENT_QUOTES, 'UTF-8'),
            self::STATUS_SHIPPED => html_entity_decode('Wys&#322;ane', ENT_QUOTES, 'UTF-8'),
            self::STATUS_CANCELLED => 'Anulowane',
        ];
    }

    public function statusLabel(): string
    {
        return OrderStatusSetting::labelFor($this->status) ?? self::statuses()[$this->status] ?? $this->status;
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_NEW => 'bg-secondary',
            self::STATUS_PENDING => 'bg-warning text-dark',
            self::STATUS_SHIPPED => 'bg-success',
            self::STATUS_CANCELLED => 'bg-dark',
            default => 'bg-secondary',
        };
    }

    public function statusColor(): string
    {
        return OrderStatusSetting::colorFor($this->status);
    }

    public function statusTextColor(): string
    {
        return OrderStatusSetting::textColorFor($this->status);
    }

    public function shippingAddressData(): object
    {
        return (object) [
            'name' => $this->shipping_name,
            'company_name' => $this->shipping_company_name,
            'tax_id' => null,
            'street' => $this->shipping_street,
            'building_number' => $this->shipping_building_number,
            'apartment_number' => $this->shipping_apartment_number,
            'postal_code' => $this->shipping_postal_code,
            'city' => $this->shipping_city,
            'province' => $this->shipping_province,
            'country_code' => $this->shipping_country_code,
            'phone' => $this->shipping_phone,
            'email' => $this->shipping_email,
        ];
    }

    public function billingAddressData(): object
    {
        return (object) [
            'name' => $this->billing_name,
            'company_name' => $this->billing_company_name,
            'tax_id' => $this->billing_tax_id,
            'street' => $this->billing_street,
            'building_number' => $this->billing_building_number,
            'apartment_number' => $this->billing_apartment_number,
            'postal_code' => $this->billing_postal_code,
            'city' => $this->billing_city,
            'province' => $this->billing_province,
            'country_code' => $this->billing_country_code,
            'phone' => $this->billing_phone,
            'email' => $this->billing_email,
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function visibleShipments(): HasMany
    {
        return $this->hasMany(Shipment::class)->whereNotNull('tracking_number');
    }

    public function shipmentCreationAttempts(): HasMany
    {
        return $this->hasMany(ShipmentCreationAttempt::class);
    }

    public function automationRuns(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    public function serialNumbers(): HasMany
    {
        return $this->hasMany(SerialNumber::class);
    }
}
