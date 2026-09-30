<?php

namespace App\Services;

use App\Enums\EmailTemplateFormat;
use App\Enums\OrderEmailMessageSource;
use App\Enums\OrderEmailMessageStatus;
use App\Exceptions\OrderEmailException;
use App\Jobs\SendOrderEmailJob;
use App\Models\EmailAccount;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\OrderEmailMessage;
use App\ValueObjects\OrderEmailPreparationData;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class OrderEmailPreparationService
{
    public function __construct(
        private readonly OrderEmailTemplateRenderer $renderer,
        private readonly OrderEmailAttachmentFreezer $attachments,
        private readonly OrderEmailIntentFingerprint $intentFingerprints,
        private readonly OrderEmailAttachmentManifest $attachmentManifests,
        private readonly SmtpAccountFingerprint $smtpAccountFingerprints,
    ) {}

    public function prepareAndQueue(
        Order $order,
        ?EmailTemplate $template,
        OrderEmailPreparationData $data,
    ): OrderEmailMessage {
        $intentFingerprint = $this->intentFingerprints->make($order, $template, $data);
        $existing = OrderEmailMessage::query()
            ->where('idempotency_key', $data->idempotencyKey)
            ->first();

        if ($existing instanceof OrderEmailMessage) {
            return $this->resolveExisting($existing, $intentFingerprint);
        }

        $message = null;
        $messageRecordId = null;

        try {
            $message = DB::transaction(function () use (
                $order,
                $template,
                $data,
                $intentFingerprint,
                &$messageRecordId,
            ): OrderEmailMessage {
                $managedTemplate = null;

                if ($template instanceof EmailTemplate) {
                    $managedTemplate = EmailTemplate::query()
                        ->with('attachments')
                        ->whereKey($template->getKey())
                        ->lockForUpdate()
                        ->first();

                    if (! $managedTemplate instanceof EmailTemplate) {
                        throw new OrderEmailException('template_missing', 'Wybrany szablon e-mail nie istnieje.');
                    }
                }

                $accountId = $data->emailAccount?->getKey() ?? $managedTemplate?->email_account_id;
                $account = is_numeric($accountId)
                    ? EmailAccount::query()->whereKey($accountId)->lockForUpdate()->first()
                    : null;

                if (! $account instanceof EmailAccount || ! $account->hasConfiguredPassword()) {
                    throw new OrderEmailException(
                        'email_account_unavailable',
                        'Wybrane konto e-mail nie istnieje albo nie ma zapisanego hasła SMTP.',
                    );
                }

                $this->validate($order, $managedTemplate, $data);
                $subjectTemplate = $data->subjectTemplate ?? $managedTemplate?->subject_template;
                $bodyTemplate = $data->bodyTemplate ?? $managedTemplate?->body_template;
                $format = $data->format ?? $managedTemplate?->format;

                if (! is_string($subjectTemplate)
                    || ! is_string($bodyTemplate)
                    || ! $format instanceof EmailTemplateFormat) {
                    throw new OrderEmailException(
                        'message_content_missing',
                        'Podaj temat, treść i format wiadomości.',
                    );
                }

                $rendered = $data->contentIsRendered
                    ? $this->renderer->finalizeRendered($subjectTemplate, $bodyTemplate, $format)
                    : $this->renderer->render($order, $subjectTemplate, $bodyTemplate, $format);
                $actorSnapshot = $this->actorSnapshot($data);
                $messageId = 'order-email-'.$data->idempotencyKey.'@'.$this->emailDomain($account->email);

                $created = $order->emailMessages()->create([
                    'email_template_id' => $managedTemplate?->getKey(),
                    'email_account_id' => $account->getKey(),
                    'created_by_user_id' => $data->user?->getKey(),
                    'automation_run_id' => $data->automationRun?->getKey(),
                    'retry_of_message_id' => $data->retryOf?->getKey(),
                    'source' => $data->source,
                    'status' => OrderEmailMessageStatus::Queued,
                    'idempotency_key' => $data->idempotencyKey,
                    'intent_fingerprint' => $intentFingerprint,
                    'smtp_account_fingerprint' => $this->smtpAccountFingerprints->make($account),
                    'message_id' => $messageId,
                    'actor_snapshot' => $actorSnapshot,
                    'template_name_snapshot' => $managedTemplate?->name,
                    'account_name_snapshot' => $account->name,
                    'from_email' => $account->email,
                    'from_name' => $this->intentFingerprints->normalizeName($account->name),
                    'reply_to_email' => $account->email,
                    'to_email' => $this->intentFingerprints->normalizeEmail($data->toEmail),
                    'to_name' => $this->intentFingerprints->normalizeName($data->toName),
                    'subject' => $rendered->subject,
                    'format' => $rendered->format,
                    'body_text' => $rendered->bodyText,
                    'body_html' => $rendered->bodyHtml,
                    'attempt_count' => 0,
                    'queued_at' => now(),
                ]);
                $messageRecordId = $created->getKey();

                foreach ($managedTemplate?->attachments ?? [] as $attachment) {
                    $this->attachments->freeze($created, $order, $attachment);
                }

                $this->attachmentManifests->seal($created);

                return $created->load('attachments');
            });
        } catch (QueryException $exception) {
            if (is_int($messageRecordId)) {
                $this->attachments->deleteMessageDirectory($messageRecordId);
            }

            $existing = OrderEmailMessage::query()
                ->where('idempotency_key', $data->idempotencyKey)
                ->first();

            if ($existing instanceof OrderEmailMessage) {
                return $this->resolveExisting($existing, $intentFingerprint);
            }

            throw $exception;
        } catch (Throwable $exception) {
            if (is_int($messageRecordId)) {
                $this->attachments->deleteMessageDirectory($messageRecordId);
            }

            throw $exception;
        }

        try {
            SendOrderEmailJob::dispatch($message->getKey());
        } catch (Throwable $exception) {
            $message->update([
                'status' => OrderEmailMessageStatus::Failed,
                'safe_error_code' => 'queue_dispatch_failed',
                'safe_error_message' => 'Nie udało się dodać wiadomości do kolejki wysyłkowej.',
                'failed_at' => now(),
            ]);

            throw new OrderEmailException(
                'queue_dispatch_failed',
                'Nie udało się dodać wiadomości do kolejki wysyłkowej.',
                $exception,
            );
        }

        return $message;
    }

    private function validate(Order $order, ?EmailTemplate $template, OrderEmailPreparationData $data): void
    {
        if (! $order->exists || ($template !== null && ! $template->exists)) {
            throw new OrderEmailException('email_context_invalid', 'Nie można przygotować wiadomości bez poprawnego zamówienia.');
        }

        if (! Str::isUuid($data->idempotencyKey)) {
            throw new OrderEmailException('idempotency_key_invalid', 'Identyfikator operacji wysyłki jest nieprawidłowy.');
        }

        if (filter_var(trim($data->toEmail), FILTER_VALIDATE_EMAIL) === false
            || preg_match('/[\r\n]/', $data->toEmail)) {
            throw new OrderEmailException('recipient_invalid', 'Adres e-mail odbiorcy jest nieprawidłowy.');
        }

        if ($data->source === OrderEmailMessageSource::Manual
            && ($data->user === null || ! $data->user->exists)
            && trim((string) $data->actorName) === '') {
            throw new OrderEmailException('actor_missing', 'Ręczna wysyłka wiadomości wymaga operatora.');
        }

        if ($data->source === OrderEmailMessageSource::Automation
            && ($data->automationRun === null || ! $data->automationRun->exists)) {
            throw new OrderEmailException('automation_run_missing', 'Automatyczna wysyłka wymaga zapisu wykonania automatyzacji.');
        }

        if ($data->automationRun !== null && $data->automationRun->order_id !== $order->getKey()) {
            throw new OrderEmailException('automation_context_invalid', 'Wykonanie automatyzacji nie należy do tego zamówienia.');
        }

        if ($data->retryOf !== null
            && (! $data->retryOf->exists || $data->retryOf->order_id !== $order->getKey())) {
            throw new OrderEmailException('retry_context_invalid', 'Ponowienie nie należy do tego zamówienia.');
        }
    }

    /** @return array<string, mixed> */
    private function actorSnapshot(OrderEmailPreparationData $data): array
    {
        if ($data->source === OrderEmailMessageSource::Manual) {
            return [
                'type' => 'user',
                'id' => $data->user?->getKey(),
                'name' => $this->intentFingerprints->normalizeName(
                    $data->user?->name ?? $data->actorName,
                ),
            ];
        }

        return [
            'type' => 'automation',
            'automation_run_id' => $data->automationRun?->getKey(),
        ];
    }

    private function emailDomain(string $email): string
    {
        $position = strrpos($email, '@');

        return mb_strtolower($position === false ? 'localhost' : substr($email, $position + 1));
    }

    private function resolveExisting(
        OrderEmailMessage $message,
        string $intentFingerprint,
    ): OrderEmailMessage {
        if (! is_string($message->intent_fingerprint)
            || ! hash_equals($message->intent_fingerprint, $intentFingerprint)) {
            throw new OrderEmailException(
                'idempotency_conflict',
                'Identyfikator operacji wysyłki został już użyty dla innej wiadomości.',
            );
        }

        return $message->loadMissing('attachments');
    }
}
