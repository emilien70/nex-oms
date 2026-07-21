<?php

namespace Modules\Shipments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourierAccount extends Model
{
    public const PROVIDER_INPOST_LOCKERS = 'inpost_lockers';

    public const PROVIDER_INPOST_COURIER = 'inpost_courier';

    public const PROVIDER_DPD = 'dpd';

    public const PROVIDER_ALLEGRO_SHIPPING = 'allegro_shipping';

    protected $fillable = [
        'provider',
        'name',
        'environment',
        'api_token',
        'api_secret',
        'api_refresh_token',
        'organization_id',
        'settings',
        'is_active',
        'last_tested_at',
        'last_error',
    ];

    protected $casts = [
        'api_token' => 'encrypted',
        'api_secret' => 'encrypted',
        'api_refresh_token' => 'encrypted',
        'settings' => 'array',
        'is_active' => 'boolean',
        'last_tested_at' => 'datetime',
    ];

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function resolvedApiToken(): ?string
    {
        return $this->api_token ?: match ($this->provider) {
            self::PROVIDER_DPD => config('services.dpd.password'),
            self::PROVIDER_ALLEGRO_SHIPPING => config('services.allegro_shipping.access_token'),
            default => config('services.inpost.token'),
        };
    }

    public function resolvedOrganizationId(): ?string
    {
        return $this->organization_id ?: match ($this->provider) {
            self::PROVIDER_DPD => config('services.dpd.master_fid'),
            self::PROVIDER_ALLEGRO_SHIPPING => config('services.allegro_shipping.client_id'),
            default => config('services.inpost.organization_id'),
        };
    }

    public function resolvedApiSecret(): ?string
    {
        return $this->api_secret ?: match ($this->provider) {
            self::PROVIDER_ALLEGRO_SHIPPING => config('services.allegro_shipping.client_secret'),
            default => null,
        };
    }

    public function resolvedApiLogin(): ?string
    {
        return $this->setting('api_login') ?: match ($this->provider) {
            self::PROVIDER_DPD => config('services.dpd.login'),
            default => null,
        };
    }

    public function resolvedInfoChannel(): ?string
    {
        return $this->setting('info_channel') ?: match ($this->provider) {
            self::PROVIDER_DPD => config('services.dpd.info_channel'),
            default => null,
        };
    }

    public function hasCompleteCredentials(): bool
    {
        if ($this->provider === self::PROVIDER_ALLEGRO_SHIPPING) {
            return filled($this->resolvedApiToken());
        }

        if ($this->provider === self::PROVIDER_DPD) {
            return filled($this->resolvedApiLogin())
                && filled($this->resolvedApiToken())
                && filled($this->resolvedOrganizationId())
                && filled($this->resolvedInfoChannel());
        }

        return filled($this->resolvedApiToken()) && filled($this->resolvedOrganizationId());
    }

    public function isOperational(): bool
    {
        return $this->is_active
            && $this->hasCompleteCredentials()
            && blank($this->last_error);
    }

    public function baseUrl(): string
    {
        $key = $this->environment === 'production' ? 'production_url' : 'sandbox_url';

        $service = match ($this->provider) {
            self::PROVIDER_DPD => 'dpd',
            self::PROVIDER_ALLEGRO_SHIPPING => 'allegro_shipping',
            default => 'inpost',
        };

        return rtrim((string) config('services.'.$service.'.'.$key), '/');
    }

    public function infoServicesUrl(): string
    {
        return rtrim((string) config('services.dpd.info_services_url'), '/');
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }
}
