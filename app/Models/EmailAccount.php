<?php

namespace App\Models;

use App\Enums\EmailAccountEncryption;
use App\Enums\EmailAccountTestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailAccount extends Model
{
    protected $fillable = [
        'name',
        'email',
        'smtp_host',
        'smtp_port',
        'smtp_username',
        'smtp_password',
        'encryption',
        'last_tested_at',
        'last_test_status',
        'last_test_message',
    ];

    protected $hidden = [
        'smtp_password',
    ];

    protected function casts(): array
    {
        return [
            'smtp_password' => 'encrypted',
            'smtp_port' => 'integer',
            'encryption' => EmailAccountEncryption::class,
            'last_tested_at' => 'immutable_datetime',
            'last_test_status' => EmailAccountTestStatus::class,
        ];
    }

    public function hasConfiguredPassword(): bool
    {
        return is_string($this->smtp_password) && $this->smtp_password !== '';
    }

    public function emailTemplates(): HasMany
    {
        return $this->hasMany(EmailTemplate::class);
    }
}
