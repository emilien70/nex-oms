<?php

namespace App\Services;

use App\Contracts\OrderEmailTransport;
use App\Enums\OrderEmailMessageStatus;
use App\Exceptions\OrderEmailTransportException;
use App\Models\EmailAccount;
use App\Models\OrderEmailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

class OrderEmailSender
{
    public function __construct(
        private readonly OrderEmailTransport $transport,
    ) {}

    public function send(int $messageId): void
    {
        $message = $this->claim($messageId);

        if (! $message instanceof OrderEmailMessage) {
            return;
        }

        try {
            $email = $this->buildEmail($message);
        } catch (Throwable) {
            $this->markFailed(
                $messageId,
                'message_snapshot_invalid',
                'Nie udało się przygotować zapisanej wiadomości do wysyłki.',
            );

            return;
        }

        $account = $message->emailAccount;

        if (! $account instanceof EmailAccount || ! $account->hasConfiguredPassword()) {
            $this->markFailed(
                $messageId,
                'email_account_unavailable',
                'Konto SMTP użyte przez wiadomość nie jest już dostępne.',
            );

            return;
        }

        try {
            $this->transport->send($account, $email);
        } catch (OrderEmailTransportException $exception) {
            if ($exception->outcomeUnknown()) {
                $this->markUnknown($messageId);
            } else {
                $this->markFailed(
                    $messageId,
                    'smtp_connection_failed',
                    'Nie udało się połączyć lub uwierzytelnić na serwerze SMTP.',
                );
            }

            return;
        } catch (Throwable) {
            $this->markUnknown($messageId);

            return;
        }

        $this->transition($messageId, [
            'status' => OrderEmailMessageStatus::Sent,
            'safe_error_code' => null,
            'safe_error_message' => null,
            'sent_at' => now(),
            'failed_at' => null,
        ]);
    }

    public function markInterrupted(int $messageId): void
    {
        DB::transaction(function () use ($messageId): void {
            $message = OrderEmailMessage::query()->lockForUpdate()->find($messageId);

            if (! $message instanceof OrderEmailMessage || $message->status->isTerminal()) {
                return;
            }

            if ($message->status === OrderEmailMessageStatus::Sending) {
                $message->update([
                    'status' => OrderEmailMessageStatus::Unknown,
                    'safe_error_code' => 'send_interrupted',
                    'safe_error_message' => 'Wynik wysyłki jest niepewny. Wiadomości nie wysłano ponownie automatycznie.',
                    'failed_at' => now(),
                ]);

                return;
            }

            $message->update([
                'status' => OrderEmailMessageStatus::Failed,
                'safe_error_code' => 'job_failed_before_send',
                'safe_error_message' => 'Zadanie wysyłkowe zakończyło się przed rozpoczęciem wysyłki SMTP.',
                'failed_at' => now(),
            ]);
        });
    }

    private function claim(int $messageId): ?OrderEmailMessage
    {
        $claimed = DB::transaction(function () use ($messageId): ?OrderEmailMessage {
            $message = OrderEmailMessage::query()->lockForUpdate()->find($messageId);

            if (! $message instanceof OrderEmailMessage
                || $message->status !== OrderEmailMessageStatus::Queued) {
                return null;
            }

            $message->update([
                'status' => OrderEmailMessageStatus::Sending,
                'attempt_count' => $message->attempt_count + 1,
                'sending_started_at' => now(),
                'last_attempt_at' => now(),
                'safe_error_code' => null,
                'safe_error_message' => null,
            ]);

            return $message;
        });

        return $claimed?->load(['emailAccount', 'attachments']);
    }

    private function buildEmail(OrderEmailMessage $message): Email
    {
        $email = (new Email)
            ->from(new Address($message->from_email, (string) ($message->from_name ?? '')))
            ->to(new Address($message->to_email, (string) ($message->to_name ?? '')))
            ->subject($message->subject)
            ->text($message->body_text);

        if ($message->reply_to_email !== null) {
            $email->replyTo($message->reply_to_email);
        }

        if ($message->body_html !== null) {
            $email->html($message->body_html);
        }

        $email->getHeaders()->addIdHeader('Message-ID', (string) $message->message_id);

        foreach ($message->attachments as $attachment) {
            $contents = Storage::disk($attachment->disk)->get($attachment->file_path);

            if (strlen($contents) !== $attachment->file_size
                || ! hash_equals($attachment->checksum_sha256, hash('sha256', $contents))) {
                throw new \RuntimeException('Attachment snapshot mismatch.');
            }

            $email->attach($contents, $attachment->file_name, $attachment->mime_type);
        }

        return $email;
    }

    private function markFailed(int $messageId, string $code, string $message): void
    {
        $this->transition($messageId, [
            'status' => OrderEmailMessageStatus::Failed,
            'safe_error_code' => $code,
            'safe_error_message' => $message,
            'failed_at' => now(),
        ]);
    }

    private function markUnknown(int $messageId): void
    {
        $this->transition($messageId, [
            'status' => OrderEmailMessageStatus::Unknown,
            'safe_error_code' => 'smtp_result_unknown',
            'safe_error_message' => 'Wynik wysyłki jest niepewny. Wiadomości nie wysłano ponownie automatycznie.',
            'failed_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function transition(int $messageId, array $attributes): void
    {
        DB::transaction(function () use ($messageId, $attributes): void {
            $message = OrderEmailMessage::query()->lockForUpdate()->find($messageId);

            if (! $message instanceof OrderEmailMessage
                || $message->status !== OrderEmailMessageStatus::Sending) {
                return;
            }

            $message->update($attributes);
        });
    }
}
