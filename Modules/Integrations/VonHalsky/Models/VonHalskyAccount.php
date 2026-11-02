<?php

namespace Modules\Integrations\VonHalsky\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Integrations\VonHalsky\Enums\VonHalskyEnvironment;

class VonHalskyAccount extends Model
{
    public const ACCOUNT_KEY = 'default';

    protected $table = 'von_halsky_accounts';

    protected $guarded = ['id'];

    protected $hidden = ['client_secret', 'access_token'];

    protected $attributes = [
        'account_key' => self::ACCOUNT_KEY,
        'name' => 'Von Halsky',
        'environment' => 'stage',
        'is_active' => false,
        'lock_version' => 0,
        'connection_revision' => 0,
    ];

    protected $casts = [
        'environment' => VonHalskyEnvironment::class,
        'client_secret' => 'encrypted',
        'access_token' => 'encrypted',
        'token_expires_at' => 'immutable_datetime',
        'last_tested_at' => 'immutable_datetime',
        'is_active' => 'boolean',
        'lock_version' => 'integer',
        'connection_revision' => 'integer',
        'default_vat_rate' => 'decimal:2',
        'initial_status_setting_id' => 'integer',
        'use_linked_products' => 'boolean',
        'use_catalog_product_names' => 'boolean',
        'use_catalog_vat' => 'boolean',
        'order_import_enabled' => 'boolean',
        'order_import_interval_minutes' => 'integer',
        'order_import_revision' => 'integer',
        'status_sync_enabled' => 'boolean',
        'status_sync_revision' => 'integer',
        'access_token_scopes' => 'array',
        'order_import_started_at' => 'immutable_datetime',
        'order_import_cursor_at' => 'immutable_datetime',
        'last_order_scan_at' => 'immutable_datetime',
        'last_order_scan_requested_at' => 'immutable_datetime',
        'last_order_reconciliation_at' => 'immutable_datetime',
    ];

    public function hasCompleteCredentials(): bool
    {
        return filled($this->organization_id) && filled($this->client_id) && filled($this->client_secret);
    }
}
