<?php

namespace Modules\CustomerPortal\Models;

use Illuminate\Database\Eloquent\Model;

class PortalSetting extends Model
{
    public const KEY = 'default';

    protected $table = 'customer_portal_settings';

    protected $guarded = ['id'];

    protected $casts = [
        'is_enabled' => 'boolean', 'revision' => 'integer', 'availability_days' => 'integer',
        'show_images' => 'boolean', 'show_address' => 'boolean', 'show_invoices' => 'boolean',
        'show_corrections' => 'boolean', 'show_proformas' => 'boolean',
        'paynow_enabled' => 'boolean', 'paynow_retry_enabled' => 'boolean', 'paynow_sources' => 'array',
        'mask_personal_data' => 'boolean', 'verify_documents' => 'boolean',
    ];

    protected $attributes = [
        'singleton_key' => self::KEY, 'is_enabled' => true, 'revision' => 1,
        'store_name' => 'NEX-OMS', 'accent_color' => '#0075c9', 'availability_days' => 90,
        'show_images' => true, 'show_address' => false, 'show_invoices' => true,
        'show_corrections' => true, 'show_proformas' => false,
    ];

    public function masksPersonalData(): bool
    {
        return $this->mask_personal_data ?? true;
    }

    public function verifiesDocuments(): bool
    {
        return $this->verify_documents ?? true;
    }

    public function verificationMethod(): string
    {
        return $this->verification_method ?? 'any';
    }
}
