<?php

namespace Modules\Integrations\PayNow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Integrations\PayNow\Enums\PayNowConnectionTestStatus;
use Modules\Integrations\PayNow\Enums\PayNowEnvironment;

class PayNowAccount extends Model
{
    public const DEFAULT_ACCOUNT_KEY = 'default';

    protected $table = 'paynow_accounts';

    protected $fillable = [
        'account_key',
        'name',
        'environment',
        'api_key',
        'signature_key',
        'is_active',
        'link_validity_seconds',
        'description_template',
        'continue_url',
        'last_tested_at',
        'last_test_status',
        'last_test_message',
    ];

    protected $hidden = [
        'api_key',
        'signature_key',
    ];

    protected $casts = [
        'environment' => PayNowEnvironment::class,
        'api_key' => 'encrypted',
        'signature_key' => 'encrypted',
        'is_active' => 'boolean',
        'link_validity_seconds' => 'integer',
        'last_tested_at' => 'immutable_datetime',
        'last_test_status' => PayNowConnectionTestStatus::class,
    ];

    public function payments(): HasMany
    {
        return $this->hasMany(PayNowPayment::class, 'paynow_account_id');
    }

    public function hasCompleteCredentials(): bool
    {
        return filled($this->api_key) && filled($this->signature_key);
    }
}
