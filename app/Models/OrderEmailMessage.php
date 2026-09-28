<?php

namespace App\Models;

use App\Enums\EmailTemplateFormat;
use App\Enums\OrderEmailMessageSource;
use App\Enums\OrderEmailMessageStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Modules\Automation\Models\AutomationRun;

class OrderEmailMessage extends Model
{
    private const IMMUTABLE_SNAPSHOT_ATTRIBUTES = [
        'order_id',
        'email_template_id',
        'email_account_id',
        'created_by_user_id',
        'automation_run_id',
        'retry_of_message_id',
        'source',
        'idempotency_key',
        'message_id',
        'actor_snapshot',
        'template_name_snapshot',
        'account_name_snapshot',
        'from_email',
        'from_name',
        'reply_to_email',
        'to_email',
        'to_name',
        'subject',
        'format',
        'body_text',
        'body_html',
    ];

    protected $fillable = [
        'order_id',
        'email_template_id',
        'email_account_id',
        'created_by_user_id',
        'automation_run_id',
        'retry_of_message_id',
        'source',
        'status',
        'idempotency_key',
        'message_id',
        'actor_snapshot',
        'template_name_snapshot',
        'account_name_snapshot',
        'from_email',
        'from_name',
        'reply_to_email',
        'to_email',
        'to_name',
        'subject',
        'format',
        'body_text',
        'body_html',
        'safe_error_code',
        'safe_error_message',
        'attempt_count',
        'queued_at',
        'sending_started_at',
        'last_attempt_at',
        'sent_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'source' => OrderEmailMessageSource::class,
            'status' => OrderEmailMessageStatus::class,
            'format' => EmailTemplateFormat::class,
            'actor_snapshot' => 'array',
            'attempt_count' => 'integer',
            'queued_at' => 'immutable_datetime',
            'sending_started_at' => 'immutable_datetime',
            'last_attempt_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $message): void {
            if ($message->isDirty(self::IMMUTABLE_SNAPSHOT_ATTRIBUTES)) {
                throw new LogicException('Sent email snapshot attributes cannot be changed.');
            }
        });

        static::deleted(function (self $message): void {
            $messageId = $message->getKey();
            DB::afterCommit(
                static fn (): bool => Storage::disk('local')
                    ->deleteDirectory("order-email-messages/{$messageId}"),
            );
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function emailTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class);
    }

    public function emailAccount(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function automationRun(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class);
    }

    public function retryOfMessage(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_message_id');
    }

    public function retries(): HasMany
    {
        return $this->hasMany(self::class, 'retry_of_message_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(OrderEmailMessageAttachment::class)->orderBy('position');
    }
}
