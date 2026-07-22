<?php

namespace Modules\Shipments\Models;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Shipment extends Model
{
    public const SERVICE_INPOST_LOCKER_STANDARD = 'inpost_locker_standard';

    public const SERVICE_INPOST_LOCKER_ALLEGRO = 'inpost_locker_allegro';

    public const SERVICE_INPOST_COURIER_STANDARD = 'inpost_courier_standard';

    public const SERVICE_INPOST_COURIER_EXPRESS_1000 = 'inpost_courier_express_1000';

    public const SERVICE_INPOST_COURIER_EXPRESS_1200 = 'inpost_courier_express_1200';

    public const SERVICE_INPOST_COURIER_EXPRESS_1700 = 'inpost_courier_express_1700';

    public const SERVICE_DPD_DOMESTIC = 'dpd_domestic';

    public const SERVICE_DPD_NEXT_DAY = 'dpd_next_day';

    public const SERVICE_DPD_TIME_0930 = 'dpd_time_0930';

    public const SERVICE_DPD_TIME_1200 = 'dpd_time_1200';

    public const SERVICE_ALLEGRO_DELIVERY = 'allegro_delivery';

    public const ADDITIONAL_SERVICE_WEEKEND = 'weekend_delivery';

    public const ADDITIONAL_SERVICE_RETURN_LABEL = 'return_label';

    public const ADDITIONAL_SERVICE_SMS = 'sms';

    public const ADDITIONAL_SERVICE_EMAIL = 'email';

    public const ADDITIONAL_SERVICE_SATURDAY = 'saturday';

    public const ADDITIONAL_SERVICE_RETURN_DOCUMENTS = 'rod';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_CREATED = 'created';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CREATION_FAILED = 'creation_failed';

    public const STATUS_CREATION_UNKNOWN = 'creation_unknown';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_CANCELLED_LOCALLY = 'cancelled_locally';

    public const STATUS_ERROR = 'error';

    public const OMS_STATUS_CREATED = 'created';

    public const OMS_STATUS_DISPATCHED = 'dispatched';

    public const OMS_STATUS_OUT_FOR_DELIVERY = 'out_for_delivery';

    public const OMS_STATUS_READY_FOR_PICKUP = 'ready_for_pickup';

    public const OMS_STATUS_DELIVERED = 'delivered';

    public const OMS_STATUS_PROBLEM = 'problem';

    public const OMS_STATUS_RETURNED = 'returned';

    protected $fillable = [
        'order_id',
        'courier_account_id',
        'creation_attempt_id',
        'provider',
        'carrier_code',
        'external_id',
        'tracking_number',
        'service',
        'parcel_template',
        'status',
        'oms_status',
        'status_changed_at',
        'oms_status_changed_at',
        'target_point_id',
        'dropoff_point_id',
        'sending_method',
        'content_description',
        'reference_number',
        'swap_sender_receiver',
        'cod_amount',
        'insurance_amount',
        'additional_services',
        'currency',
        'label_format',
        'label_type',
        'request_uuid',
        'error_message',
        'confirmed_at',
        'cancelled_at',
        'last_synced_at',
    ];

    protected $casts = [
        'cod_amount' => 'decimal:2',
        'insurance_amount' => 'decimal:2',
        'additional_services' => 'array',
        'swap_sender_receiver' => 'boolean',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'status_changed_at' => 'datetime',
        'oms_status_changed_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function courierAccount(): BelongsTo
    {
        return $this->belongsTo(CourierAccount::class);
    }

    public function creationAttempt(): BelongsTo
    {
        return $this->belongsTo(ShipmentCreationAttempt::class, 'creation_attempt_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class);
    }

    public function apiLogs(): HasMany
    {
        return $this->hasMany(IntegrationApiLog::class);
    }

    public function latestCreateApiLog(): HasOne
    {
        return $this->hasOne(IntegrationApiLog::class)
            ->ofMany(
                ['id' => 'max'],
                fn ($query) => $query->where('operation', 'create_shipment'),
            );
    }

    public function parcels(): HasMany
    {
        return $this->hasMany(ShipmentParcel::class)->orderBy('position');
    }

    public function statusLabel(): string
    {
        return self::omsStatusLabelFor($this->oms_status);
    }

    public function hasAdditionalService(string $service): bool
    {
        return in_array($service, $this->additional_services ?? [], true);
    }

    public static function omsStatuses(): array
    {
        return [
            self::OMS_STATUS_CREATED => html_entity_decode('Przesy&#322;ka utworzona', ENT_QUOTES, 'UTF-8'),
            self::OMS_STATUS_DISPATCHED => html_entity_decode('Przesy&#322;ka nadana', ENT_QUOTES, 'UTF-8'),
            self::OMS_STATUS_OUT_FOR_DELIVERY => html_entity_decode('Wydano do dor&#281;czenia', ENT_QUOTES, 'UTF-8'),
            self::OMS_STATUS_READY_FOR_PICKUP => 'Oczekuje w punkcie',
            self::OMS_STATUS_DELIVERED => html_entity_decode('Dor&#281;czono', ENT_QUOTES, 'UTF-8'),
            self::OMS_STATUS_PROBLEM => html_entity_decode('Wyst&#261;pi&#322; problem', ENT_QUOTES, 'UTF-8'),
            self::OMS_STATUS_RETURNED => 'Zwrot',
        ];
    }

    public static function omsStatusLabelFor(?string $status): string
    {
        return self::omsStatuses()[$status]
            ?? html_entity_decode('Wyst&#261;pi&#322; problem', ENT_QUOTES, 'UTF-8');
    }

    public static function terminalOmsStatuses(): array
    {
        return [self::OMS_STATUS_DELIVERED, self::OMS_STATUS_RETURNED];
    }

    public function hasTerminalOmsStatus(): bool
    {
        return in_array($this->oms_status, self::terminalOmsStatuses(), true);
    }

    public function omsStatusProgress(): int
    {
        return match ($this->oms_status) {
            self::OMS_STATUS_CREATED => 0,
            self::OMS_STATUS_DISPATCHED => 33,
            self::OMS_STATUS_OUT_FOR_DELIVERY => 66,
            self::OMS_STATUS_READY_FOR_PICKUP => 90,
            self::OMS_STATUS_DELIVERED, self::OMS_STATUS_RETURNED => 100,
            self::OMS_STATUS_PROBLEM => 50,
            default => 50,
        };
    }

    public function omsStatusProgressClass(): string
    {
        return match ($this->oms_status) {
            self::OMS_STATUS_DELIVERED => 'is-success',
            self::OMS_STATUS_PROBLEM, self::OMS_STATUS_RETURNED => 'is-error',
            default => '',
        };
    }

    public static function statusLabelFor(?string $status): string
    {
        return match ($status) {
            self::STATUS_QUEUED => 'Oczekuje na wyslanie',
            self::STATUS_CREATED => 'Tworzenie w InPost',
            self::STATUS_CONFIRMED => 'Utworzona',
            self::STATUS_CREATION_FAILED => 'Blad tworzenia',
            self::STATUS_CREATION_UNKNOWN => 'Wynik nadania niepewny',
            self::STATUS_CANCELLED, 'canceled' => 'Anulowana',
            self::STATUS_CANCELLED_LOCALLY => 'Anulowana lokalnie',
            self::STATUS_ERROR => 'Blad',
            'delivered' => 'Doreczona',
            'ready_to_pickup' => 'Gotowa do odbioru',
            'returned_to_sender' => 'Zwrocona do nadawcy',
            null, '' => 'Brak statusu',
            default => str_replace('_', ' ', ucfirst($status)),
        };
    }

    public function canRetryCreation(): bool
    {
        return blank($this->external_id)
            && $this->status === self::STATUS_ERROR;
    }

    public function creationOutcomeUnknown(): bool
    {
        return blank($this->external_id) && $this->status === self::STATUS_CREATION_UNKNOWN;
    }

    public function requiresCreationVerification(): bool
    {
        return blank($this->external_id)
            && in_array($this->status, [self::STATUS_CREATION_FAILED, self::STATUS_CREATION_UNKNOWN], true);
    }

    public function canDownloadLabel(): bool
    {
        return filled($this->external_id)
            && filled($this->tracking_number)
            && ! in_array($this->status, [self::STATUS_CANCELLED, self::STATUS_CANCELLED_LOCALLY, 'canceled', self::STATUS_ERROR], true);
    }

    public function canCancel(): bool
    {
        return $this->canCancelViaCourier() || $this->canCancelLocally();
    }

    public function canCancelViaCourier(): bool
    {
        if ($this->provider === CourierAccount::PROVIDER_DPD) {
            return false;
        }

        if ($this->provider === CourierAccount::PROVIDER_ALLEGRO_SHIPPING) {
            return filled($this->external_id)
                && strtoupper((string) $this->carrier_code) !== 'ALLEGRO'
                && ! $this->hasTerminalOmsStatus()
                && ! in_array($this->status, [self::STATUS_CANCELLED, self::STATUS_CANCELLED_LOCALLY, 'canceled'], true);
        }

        return filled($this->external_id)
            && in_array($this->status, [self::STATUS_CREATED, 'offers_prepared', 'offer_selected'], true);
    }

    public function canCancelLocally(): bool
    {
        return $this->exists;
    }

    public function trackingUrl(): ?string
    {
        if (blank($this->tracking_number)) {
            return null;
        }

        return match ($this->provider) {
            CourierAccount::PROVIDER_INPOST_LOCKERS,
            CourierAccount::PROVIDER_INPOST_COURIER => 'https://inpost.pl/sledzenie-przesylek?number='.rawurlencode($this->tracking_number),
            CourierAccount::PROVIDER_DPD => 'https://tracktrace.dpd.com.pl/parcelDetails?typ=1&p1='.rawurlencode($this->tracking_number),
            CourierAccount::PROVIDER_ALLEGRO_SHIPPING => match (strtoupper((string) $this->carrier_code)) {
                'INPOST' => 'https://inpost.pl/sledzenie-przesylek?number='.rawurlencode($this->tracking_number),
                'DPD' => 'https://tracktrace.dpd.com.pl/parcelDetails?typ=1&p1='.rawurlencode($this->tracking_number),
                'ALLEGRO' => 'https://allegro.pl/allegrodelivery/sledzenie-paczki?numer='.rawurlencode($this->tracking_number),
                default => null,
            },
            default => null,
        };
    }
}
