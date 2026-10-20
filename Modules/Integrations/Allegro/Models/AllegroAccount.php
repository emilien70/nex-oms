<?php

namespace Modules\Integrations\Allegro\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Shipments\Models\CourierAccount;

class AllegroAccount extends Model
{
    public const DEFAULT_ACCOUNT_KEY = 'default';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_ERROR = 'error';

    public const BILLING_SCOPE = 'allegro:api:billing:read';

    public const REQUIRED_SCOPES = [
        'allegro:api:profile:read',
        'allegro:api:orders:read',
        'allegro:api:orders:write',
        'allegro:api:shipments:read',
        'allegro:api:shipments:write',
    ];

    protected $attributes = [
        'use_linked_products' => true,
        'use_catalog_product_names' => true,
        'use_catalog_vat' => true,
    ];

    protected $fillable = [
        'account_key',
        'name',
        'environment',
        'client_id',
        'client_secret',
        'access_token',
        'refresh_token',
        'access_token_expires_at',
        'granted_scopes',
        'allegro_user_id',
        'allegro_login',
        'marketplace_id',
        'is_active',
        'last_tested_at',
        'last_test_status',
        'last_error',
        'use_linked_products',
        'use_catalog_product_names',
        'use_catalog_vat',
    ];

    protected $hidden = [
        'client_secret',
        'access_token',
        'refresh_token',
    ];

    protected $casts = [
        'client_secret' => 'encrypted',
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'access_token_expires_at' => 'immutable_datetime',
        'granted_scopes' => 'array',
        'is_active' => 'boolean',
        'use_linked_products' => 'boolean',
        'use_catalog_product_names' => 'boolean',
        'use_catalog_vat' => 'boolean',
        'last_tested_at' => 'immutable_datetime',
    ];

    public function courierAccounts(): HasMany
    {
        return $this->hasMany(CourierAccount::class);
    }

    public function hasApplicationCredentials(): bool
    {
        return filled($this->client_id) && filled($this->client_secret);
    }

    public function hasOAuthTokens(): bool
    {
        return filled($this->access_token) && filled($this->refresh_token);
    }

    public function missingScopes(): array
    {
        return array_values(array_diff(self::REQUIRED_SCOPES, $this->granted_scopes ?? []));
    }

    public function hasRequiredScopes(): bool
    {
        return $this->missingScopes() === [];
    }

    public function hasBillingScope(): bool
    {
        return in_array(self::BILLING_SCOPE, $this->granted_scopes ?? [], true);
    }

    public function isOperational(): bool
    {
        return $this->is_active
            && $this->hasApplicationCredentials()
            && $this->hasOAuthTokens()
            && $this->hasRequiredScopes()
            && $this->last_test_status === self::STATUS_SUCCESS
            && blank($this->last_error);
    }

    public function apiBaseUrl(): string
    {
        $key = $this->environment === 'production' ? 'production_url' : 'sandbox_url';

        return rtrim((string) config('services.allegro.'.$key), '/');
    }

    public function authUrl(): string
    {
        $key = $this->environment === 'production' ? 'production_auth_url' : 'sandbox_auth_url';

        return (string) config('services.allegro.'.$key);
    }

    public function deviceUrl(): string
    {
        $key = $this->environment === 'production' ? 'production_device_url' : 'sandbox_device_url';

        return (string) config('services.allegro.'.$key);
    }
}
