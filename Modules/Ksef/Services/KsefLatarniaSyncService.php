<?php

namespace Modules\Ksef\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Ksef\Enums\KsefLatarniaEnvironment;
use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Models\KsefLatarniaMessage;
use Modules\Ksef\Models\KsefLatarniaSyncState;
use Modules\Ksef\ValueObjects\KsefLatarniaMessageData;
use Modules\Ksef\ValueObjects\KsefLatarniaStatusData;
use Modules\Ksef\ValueObjects\KsefLatarniaSyncResult;

final class KsefLatarniaSyncService
{
    public function __construct(
        private readonly KsefLatarniaClient $client,
        private readonly KsefLatarniaStatusParser $statusParser,
        private readonly KsefLatarniaMessageParser $messageParser,
    ) {}

    public function syncStatus(KsefLatarniaEnvironment $environment): KsefLatarniaStatusData
    {
        $this->recordAttempt($environment, 'status');

        try {
            $status = $this->statusParser->parse($this->client->status($environment));
            $now = CarbonImmutable::now('UTC');

            DB::transaction(function () use ($environment, $status, $now): void {
                $this->persistMessages($environment, $status->messages, $now);

                $state = $this->lockedState($environment);
                $state->forceFill([
                    'current_status' => $status->status,
                    'status_payload_json' => $status->payloadJson,
                    'status_payload_hash' => $status->payloadHash,
                    'status_last_success_at' => $now,
                    'status_last_error_at' => null,
                    'status_last_error_code' => null,
                    'status_last_error_message' => null,
                ])->save();
            });

            return $status;
        } catch (KsefApiException $exception) {
            $this->recordError($environment, 'status', $exception);

            throw $exception;
        }
    }

    /** @return list<KsefLatarniaMessageData> */
    public function syncMessages(KsefLatarniaEnvironment $environment): array
    {
        $this->recordAttempt($environment, 'messages');

        try {
            $messages = $this->messageParser->parseMany($this->client->messages($environment));
            $now = CarbonImmutable::now('UTC');

            DB::transaction(function () use ($environment, $messages, $now): void {
                $this->persistMessages($environment, $messages, $now);

                $state = $this->lockedState($environment);
                $state->forceFill([
                    'messages_last_success_at' => $now,
                    'messages_last_error_at' => null,
                    'messages_last_error_code' => null,
                    'messages_last_error_message' => null,
                ])->save();
            });

            return $messages;
        } catch (KsefApiException $exception) {
            $this->recordError($environment, 'messages', $exception);

            throw $exception;
        }
    }

    public function sync(KsefLatarniaEnvironment $environment): KsefLatarniaSyncResult
    {
        $statusSuccess = false;
        $messagesSuccess = false;
        $statusError = null;
        $messagesError = null;

        try {
            $this->syncStatus($environment);
            $statusSuccess = true;
        } catch (KsefApiException $exception) {
            $statusError = $exception->safeCode;
        }

        try {
            $this->syncMessages($environment);
            $messagesSuccess = true;
        } catch (KsefApiException $exception) {
            $messagesError = $exception->safeCode;
        }

        return new KsefLatarniaSyncResult(
            $statusSuccess,
            $messagesSuccess,
            $statusError,
            $messagesError,
        );
    }

    private function recordAttempt(KsefLatarniaEnvironment $environment, string $endpoint): void
    {
        $state = $this->state($environment);
        $state->forceFill([
            $endpoint.'_last_attempt_at' => CarbonImmutable::now('UTC'),
        ])->save();
    }

    private function recordError(
        KsefLatarniaEnvironment $environment,
        string $endpoint,
        KsefApiException $exception,
    ): void {
        $state = $this->state($environment);
        $state->forceFill([
            $endpoint.'_last_error_at' => CarbonImmutable::now('UTC'),
            $endpoint.'_last_error_code' => $exception->safeCode,
            $endpoint.'_last_error_message' => mb_substr($exception->getMessage(), 0, 500),
        ])->save();
    }

    private function state(KsefLatarniaEnvironment $environment): KsefLatarniaSyncState
    {
        return KsefLatarniaSyncState::query()->firstOrCreate([
            'source_environment' => $environment,
        ]);
    }

    private function lockedState(KsefLatarniaEnvironment $environment): KsefLatarniaSyncState
    {
        return KsefLatarniaSyncState::query()
            ->where('source_environment', $environment->value)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @param  list<KsefLatarniaMessageData>  $messages
     */
    private function persistMessages(
        KsefLatarniaEnvironment $environment,
        array $messages,
        CarbonImmutable $now,
    ): void {
        foreach ($messages as $message) {
            $existing = KsefLatarniaMessage::query()
                ->where('source_environment', $environment->value)
                ->where('external_message_id', $message->externalMessageId)
                ->where('version', $message->version)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (! hash_equals($existing->payload_hash, $message->payloadHash)) {
                    throw new KsefApiException(
                        'Latarnia KSeF zmieniła treść istniejącej wersji komunikatu.',
                        'ksef_latarnia_message_version_conflict',
                    );
                }

                $existing->forceFill(['last_seen_at' => $now])->save();

                continue;
            }

            KsefLatarniaMessage::query()->create([
                'source_environment' => $environment,
                'external_message_id' => $message->externalMessageId,
                'event_id' => $message->eventId,
                'version' => $message->version,
                'category' => $message->category,
                'type' => $message->type,
                'title' => $message->title,
                'text' => $message->text,
                'start_at' => $message->startAt,
                'end_at' => $message->endAt,
                'published_at' => $message->publishedAt,
                'payload_json' => $message->payloadJson,
                'payload_hash' => $message->payloadHash,
                'first_fetched_at' => $now,
                'last_seen_at' => $now,
            ]);
        }
    }
}
