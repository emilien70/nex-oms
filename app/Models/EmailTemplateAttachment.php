<?php

namespace App\Models;

use App\Enums\EmailTemplateAttachmentType;
use App\Enums\EmailTemplateDocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailTemplateAttachment extends Model
{
    protected $fillable = [
        'slot',
        'type',
        'document_type',
        'file_path',
        'original_name',
        'mime_type',
        'file_size',
    ];

    protected function casts(): array
    {
        return [
            'slot' => 'integer',
            'type' => EmailTemplateAttachmentType::class,
            'document_type' => EmailTemplateDocumentType::class,
            'file_size' => 'integer',
        ];
    }

    public function emailTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class);
    }
}
