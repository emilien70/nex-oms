<?php

namespace App\Models;

use App\Enums\EmailTemplateFormat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailTemplate extends Model
{
    protected $fillable = [
        'email_account_id',
        'name',
        'subject_template',
        'format',
        'body_template',
        'is_hidden',
    ];

    protected function casts(): array
    {
        return [
            'format' => EmailTemplateFormat::class,
            'is_hidden' => 'boolean',
        ];
    }

    public function emailAccount(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EmailTemplateAttachment::class)->orderBy('slot');
    }
}
