<?php

namespace Modules\Ksef\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Ksef\Enums\KsefEnvironment;

class KsefSetting extends Model
{
    public const SINGLETON_KEY = 'default';

    protected $fillable = [
        'singleton_key',
        'name',
        'environment',
        'context_nip',
        'automatic_submission',
        'send_without_buyer_nip',
        'include_recipient_data',
        'include_buyer_contact_data',
        'include_additional_information',
        'include_order_reference',
        'include_bank_account',
        'include_gtu',
        'include_sale_date',
    ];

    protected $casts = [
        'environment' => KsefEnvironment::class,
        'automatic_submission' => 'boolean',
        'send_without_buyer_nip' => 'boolean',
        'include_recipient_data' => 'boolean',
        'include_buyer_contact_data' => 'boolean',
        'include_additional_information' => 'boolean',
        'include_order_reference' => 'boolean',
        'include_bank_account' => 'boolean',
        'include_gtu' => 'boolean',
        'include_sale_date' => 'boolean',
    ];
}
