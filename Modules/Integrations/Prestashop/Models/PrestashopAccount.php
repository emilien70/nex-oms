<?php

namespace Modules\Integrations\Prestashop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Integrations\Prestashop\Enums\PrestashopCatalogVatMode;
use Modules\Integrations\Prestashop\Enums\PrestashopConnectionTestStatus;

class PrestashopAccount extends Model
{
    public const DEFAULT_ACCOUNT_KEY = 'default';

    protected $table = 'prestashop_accounts';

    protected $fillable = [
        'account_key',
        'name',
        'base_url',
        'product_url_template',
        'api_key',
        'is_active',
        'notify_customer_on_status_change',
        'status_sync_enabled',
        'status_sync_interval_minutes',
        'last_status_sync_at',
        'last_status_sync_error_at',
        'last_status_sync_error',
        'order_import_enabled',
        'use_linked_products',
        'use_catalog_product_names',
        'catalog_vat_mode',
        'default_vat_rate',
        'order_import_started_at',
        'last_order_import_scan_at',
        'last_order_import_error_at',
        'last_order_import_error',
        'payment_sync_enabled',
        'warn_missing_transaction_id',
        'last_payment_sync_at',
        'last_payment_sync_error_at',
        'last_payment_sync_error',
        'shop_id',
        'shop_name',
        'language_id',
        'language_iso_code',
        'available_languages',
        'available_currencies',
        'available_resources',
        'order_enrichment_resource',
        'detected_version',
        'last_tested_at',
        'last_test_status',
        'last_test_message',
    ];

    protected $hidden = [
        'api_key',
    ];

    protected $casts = [
        'api_key' => 'encrypted',
        'is_active' => 'boolean',
        'notify_customer_on_status_change' => 'boolean',
        'status_sync_enabled' => 'boolean',
        'status_sync_interval_minutes' => 'integer',
        'last_status_sync_at' => 'immutable_datetime',
        'last_status_sync_error_at' => 'immutable_datetime',
        'order_import_enabled' => 'boolean',
        'use_linked_products' => 'boolean',
        'use_catalog_product_names' => 'boolean',
        'catalog_vat_mode' => PrestashopCatalogVatMode::class,
        'default_vat_rate' => 'decimal:2',
        'order_import_started_at' => 'immutable_datetime',
        'last_order_import_scan_at' => 'immutable_datetime',
        'last_order_import_error_at' => 'immutable_datetime',
        'payment_sync_enabled' => 'boolean',
        'warn_missing_transaction_id' => 'boolean',
        'last_payment_sync_at' => 'immutable_datetime',
        'last_payment_sync_error_at' => 'immutable_datetime',
        'shop_id' => 'integer',
        'language_id' => 'integer',
        'available_languages' => 'array',
        'available_currencies' => 'array',
        'available_resources' => 'array',
        'last_tested_at' => 'immutable_datetime',
        'last_test_status' => PrestashopConnectionTestStatus::class,
    ];

    public function orderStates(): HasMany
    {
        return $this->hasMany(PrestashopOrderState::class, 'prestashop_account_id');
    }

    public function orderLinks(): HasMany
    {
        return $this->hasMany(PrestashopOrderLink::class, 'prestashop_account_id');
    }

    public function orderImportAttempts(): HasMany
    {
        return $this->hasMany(PrestashopOrderImportAttempt::class, 'prestashop_account_id');
    }

    public function inboundStatusMappings(): HasMany
    {
        return $this->hasMany(PrestashopInboundStatusMapping::class, 'prestashop_account_id');
    }

    public function outboundStatusMappings(): HasMany
    {
        return $this->hasMany(PrestashopOutboundStatusMapping::class, 'prestashop_account_id');
    }

    public function productLinks(): HasMany
    {
        return $this->hasMany(PrestashopProductLink::class, 'prestashop_account_id');
    }

    public function hasCompleteCredentials(): bool
    {
        return filled($this->base_url) && filled($this->api_key);
    }

    public function resolvedProductUrlTemplate(): ?string
    {
        $template = trim((string) $this->product_url_template);

        if ($template !== '') {
            return $template;
        }

        $baseUrl = rtrim(trim((string) $this->base_url), '/');

        return $baseUrl === ''
            ? null
            : $baseUrl.'/index.php?controller=product&id_product=[product_id]';
    }

    public function productUrl(?int $productId): ?string
    {
        $template = $this->resolvedProductUrlTemplate();

        if ($productId === null || $productId < 1 || $template === null || ! str_contains($template, '[product_id]')) {
            return null;
        }

        return str_replace('[product_id]', (string) $productId, $template);
    }
}
