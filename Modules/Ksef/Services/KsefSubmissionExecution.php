<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Modules\Ksef\Enums\KsefInvoiceSubmissionStatus as Status;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefInvoiceSubmission;

class KsefSubmissionExecution
{
    public const VERSION = 1;

    public static function initialAttributes(): array
    {
        return [
            'execution_protocol_version' => self::VERSION,
            'execution_expires_at' => self::deadline(),
        ];
    }

    private static function deadline(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->addSeconds(
            max(30, (int) config('ksef.submission_execution.lease_seconds', 300)),
        );
    }

    public function claim(KsefInvoiceSubmission $submission): KsefInvoiceSubmission
    {
        if ($submission->execution_protocol_version !== self::VERSION) {
            throw new KsefApiException('Próba wymaga przeglądu protokołu wykonania.', 'ksef_submission_execution_review_required');
        }
        if ($submission->status !== Status::Preparing
            || $submission->execution_owner !== null
            || $submission->invoice_post_started_at !== null
            || $submission->recovery_code !== null
            || $submission->recovered_at !== null
            || $submission->session_reference_number !== null
            || $submission->invoice_reference_number !== null
            || $submission->ksef_number !== null
            || $submission->ksef_status_code !== null
            || $submission->execution_expires_at === null) {
            throw $this->lost();
        }

        $attributes = [
            'execution_owner' => bin2hex(random_bytes(32)),
            'execution_expires_at' => self::deadline(),
        ];
        $this->write($submission, $this->compare($submission)
            ->where('execution_expires_at', '>', $this->now()), $attributes);

        return $submission->fresh();
    }

    public function touch(KsefInvoiceSubmission $submission, #[\SensitiveParameter] string $owner): KsefInvoiceSubmission
    {
        $this->write($submission, $this->owned($submission, $owner), [
            'execution_expires_at' => self::deadline(),
        ]);

        return $submission->fresh();
    }

    public function phase(
        KsefInvoiceSubmission $submission,
        #[\SensitiveParameter] string $owner,
        Status $status,
        array $attributes = [],
    ): KsefInvoiceSubmission {
        if (! $submission->status->canTransitionTo($status)
            || (in_array($status, [Status::Submitted, Status::Uncertain], true) && $submission->invoice_post_started_at === null)
            || ! in_array($status, [Status::SessionOpened, Status::Submitted, Status::TechnicalFailed, Status::Uncertain], true)
            || array_intersect(array_keys($attributes), [
                'execution_protocol_version', 'execution_owner', 'execution_expires_at',
                'invoice_post_started_at', 'recovery_code', 'recovered_at', 'status',
            ]) !== []) {
            throw $this->lost();
        }
        $action = app(KsefSubmissionFollowUpPolicy::class)->actionForStatus($status, false);
        $this->write($submission, $this->owned($submission, $owner), $attributes + [
            'status' => $status,
            'execution_expires_at' => self::deadline(),
            'follow_up_action' => $action,
            'next_follow_up_at' => $action === null ? null : app(KsefSubmissionFollowUpPolicy::class)->nextAttemptAt(0),
        ]);

        return $submission->fresh();
    }

    public function encryptionMetadata(KsefInvoiceSubmission $submission, #[\SensitiveParameter] string $owner, array $attributes): KsefInvoiceSubmission
    {
        $this->write($submission, $this->owned($submission, $owner), array_intersect_key($attributes, array_flip([
            'public_key_id', 'encrypted_invoice_hash', 'encrypted_invoice_size',
        ])) + ['execution_expires_at' => self::deadline()]);

        return $submission->fresh();
    }

    public function consumePost(KsefInvoiceSubmission $submission, #[\SensitiveParameter] string $owner, array $request): KsefInvoiceSubmission
    {
        $ciphertext = base64_decode($request['encryptedInvoiceContent'] ?? '', true);
        if ($submission->status !== Status::SessionOpened
            || ! is_string($submission->session_reference_number) || trim($submission->session_reference_number) === ''
            || $submission->invoice_post_started_at !== null
            || $submission->invoice_reference_number !== null
            || ($request['invoiceHash'] ?? null) !== $submission->invoice_hash
            || ($request['invoiceSize'] ?? null) !== $submission->invoice_size
            || ($request['encryptedInvoiceHash'] ?? null) !== $submission->encrypted_invoice_hash
            || ($request['encryptedInvoiceSize'] ?? null) !== $submission->encrypted_invoice_size
            || ($request['offlineMode'] ?? null) !== ($submission->offline_issuance_id !== null)
            || ! is_string($ciphertext) || $ciphertext === ''
            || strlen($ciphertext) !== $submission->encrypted_invoice_size
            || base64_encode(hash('sha256', $ciphertext, true)) !== $submission->encrypted_invoice_hash) {
            throw new KsefApiException('Request transmisji nie odpowiada zapisanej próbie.', 'ksef_submission_request_inconsistent');
        }

        // A durable one-way fence, not confirmation that MF received the request.
        $this->write($submission, $this->owned($submission, $owner)->whereNull('invoice_post_started_at'), [
            'invoice_post_started_at' => CarbonImmutable::now('UTC'),
            'execution_expires_at' => self::deadline(),
        ]);

        return $submission->fresh();
    }

    public function sessionMetadata(KsefInvoiceSubmission $submission, #[\SensitiveParameter] string $owner, array $attributes): KsefInvoiceSubmission
    {
        if ($this->owns($submission, $owner)) {
            $this->write($submission, $this->owned($submission, $owner), array_intersect_key($attributes, array_flip([
                'session_closed_at', 'session_close_error_code', 'session_close_error_message',
            ])));
        }

        return $submission->fresh();
    }

    public function owns(KsefInvoiceSubmission $submission, #[\SensitiveParameter] string $owner): bool
    {
        return $this->owned($submission, $owner)->exists();
    }

    private function owned(KsefInvoiceSubmission $submission, string $owner): Builder
    {
        if ($owner === '' || $submission->execution_protocol_version !== self::VERSION
            || $submission->execution_owner !== $owner) {
            throw $this->lost();
        }

        return $this->compare($submission)->where('execution_owner', $owner)
            ->where('execution_expires_at', '>', $this->now());
    }

    // Compare every observed column: a stale process cannot overwrite newer evidence.
    public function compare(KsefInvoiceSubmission $submission): Builder
    {
        $query = $submission->newQuery()->whereKey($submission->getKey());
        foreach ($submission->getRawOriginal() as $column => $value) {
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }

        return $query;
    }

    public function write(KsefInvoiceSubmission $submission, Builder $query, array $attributes): void
    {
        $copy = clone $submission;
        $copy->forceFill($attributes);
        if ($query->update($copy->getDirty()) !== 1) {
            throw $this->lost();
        }
    }

    private function now(): string
    {
        return CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
    }

    private function lost(): KsefApiException
    {
        return new KsefApiException('Próba jest obsługiwana lub prawo wykonania wygasło. Nie ponawiaj wysyłki.', 'ksef_submission_execution_lost');
    }
}
