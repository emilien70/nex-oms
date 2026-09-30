<?php

namespace App\Services;

use App\Models\EmailTemplate;
use App\Models\Order;
use App\ValueObjects\OrderEmailPreparationData;
use JsonException;

final class OrderEmailIntentFingerprint
{
    /** @throws JsonException */
    public function make(
        Order $order,
        ?EmailTemplate $template,
        OrderEmailPreparationData $data,
    ): string {
        $payload = [
            'order_id' => (int) $order->getKey(),
            'email_template_id' => $this->nullableId($template?->getKey()),
            'email_account_id' => $this->nullableId(
                $data->emailAccount?->getKey() ?? $template?->email_account_id,
            ),
            'source' => $data->source->value,
            'to_email' => $this->normalizeEmail($data->toEmail),
            'to_name' => $this->normalizeName($data->toName),
            'created_by_user_id' => $this->nullableId($data->user?->getKey()),
            'automation_run_id' => $this->nullableId($data->automationRun?->getKey()),
            'retry_of_message_id' => $this->nullableId($data->retryOf?->getKey()),
            'subject_template_override' => $data->subjectTemplate,
            'body_template_override' => $data->bodyTemplate,
            'format_override' => $data->format?->value,
            'actor_name' => $this->normalizeName($data->actorName),
            'content_is_rendered' => $data->contentIsRendered,
        ];

        return hash('sha256', json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    public function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function normalizeName(?string $name): ?string
    {
        $name = trim(preg_replace('/[\r\n]+/u', ' ', (string) $name) ?? '');

        return $name !== '' ? mb_substr($name, 0, 255) : null;
    }

    private function nullableId(mixed $id): ?int
    {
        return $id === null ? null : (int) $id;
    }
}
