<?php

namespace Modules\Integrations\Prestashop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
