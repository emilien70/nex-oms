<?php

namespace Modules\Automation\Services;

use App\Enums\OrderEmailMessageSource;
use App\Exceptions\OrderEmailException;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Services\OrderEmailPreparationService;
use App\ValueObjects\OrderEmailPreparationData;
use Modules\Automation\Models\AutomationRun;
use Modules\Automation\Models\AutomationRunStep;
use Ramsey\Uuid\Uuid;

class AutomationEmailActionService
{
    public function __construct(
        private readonly OrderEmailPreparationService $preparation,
    ) {}

    public function execute(
        AutomationRun $run,
        AutomationRunStep $step,
        array $configuration,
    ): array {
        $order = $run->order;

        if (! $order instanceof Order) {
            throw new OrderEmailException(
                'order_missing',
                'Zamówienie dla automatycznej wysyłki e-mail nie istnieje.',
            );
        }

        $templateId = filter_var(
            $configuration['email_template_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        $template = $templateId === false
            ? null
            : EmailTemplate::query()->find($templateId);

        if (! $template instanceof EmailTemplate) {
            throw new OrderEmailException(
                'template_missing',
                'Wybrany szablon e-mail nie istnieje.',
            );
        }

        $messages = collect($this->recipients($order, $configuration))
            ->map(function (array $recipient) use ($order, $template, $run, $step) {
                return $this->preparation->prepareAndQueue(
                    $order,
                    $template,
                    new OrderEmailPreparationData(
                        toEmail: $recipient['email'],
                        toName: $recipient['name'],
                        source: OrderEmailMessageSource::Automation,
                        idempotencyKey: $this->idempotencyKey(
                            $run,
                            $step,
                            $recipient['email'],
                            $recipient['customer'],
                        ),
                        automationRun: $run,
                    ),
                );
            });
        $statuses = $messages
            ->map(fn ($message): string => $message->status->value)
            ->unique()
            ->values();

        return [
            'message_id' => $messages->first()->getKey(),
            'message_ids' => $messages
                ->map(fn ($message): int => (int) $message->getKey())
                ->values()
                ->all(),
            'recipient_count' => $messages->count(),
            'email_template_id' => $template->getKey(),
            'status' => $statuses->count() === 1 ? $statuses->first() : 'mixed',
        ];
    }

    /** @return list<array{email: string, name: ?string, customer: bool}> */
    private function recipients(Order $order, array $configuration): array
    {
        $includeCustomer = array_key_exists('recipient_customer', $configuration)
            ? filter_var($configuration['recipient_customer'], FILTER_VALIDATE_BOOLEAN)
            : true;
        $additionalEmails = $configuration['recipient_emails'] ?? [];

        if (! is_array($additionalEmails) || count($additionalEmails) > 10) {
            throw new OrderEmailException(
                'recipients_invalid',
                'Lista dodatkowych odbiorców wiadomości jest nieprawidłowa.',
            );
        }

        $recipients = [];

        if ($includeCustomer) {
            $customerEmail = $this->validatedEmail(
                $order->customer_email,
                'Adres e-mail klienta jest nieprawidłowy lub nie został podany.',
            );
            $recipients[$customerEmail] = [
                'email' => $customerEmail,
                'name' => $order->billing_name ?: ($order->shipping_name ?: $order->customer_login),
                'customer' => true,
            ];
        }

        foreach ($additionalEmails as $email) {
            $normalizedEmail = $this->validatedEmail(
                $email,
                'Dodatkowy adres e-mail odbiorcy jest nieprawidłowy.',
            );
            $recipients[$normalizedEmail] ??= [
                'email' => $normalizedEmail,
                'name' => null,
                'customer' => false,
            ];
        }

        if ($recipients === []) {
            throw new OrderEmailException(
                'recipient_missing',
                'Wybierz klienta lub podaj co najmniej jeden adres e-mail.',
            );
        }

        return array_values($recipients);
    }

    private function validatedEmail(mixed $email, string $message): string
    {
        if (! is_string($email)
            || preg_match('/[\r\n]/', $email)
            || filter_var(trim($email), FILTER_VALIDATE_EMAIL) === false) {
            throw new OrderEmailException('recipient_invalid', $message);
        }

        return mb_strtolower(trim($email));
    }

    private function idempotencyKey(
        AutomationRun $run,
        AutomationRunStep $step,
        string $email,
        bool $customer,
    ): string {
        $baseKey = Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            "nex-oms:automation-email:run:{$run->getKey()}:position:{$step->position}",
        );

        if ($customer) {
            return $baseKey->toString();
        }

        return Uuid::uuid5(
            $baseKey,
            'recipient:'.hash('sha256', $email),
        )->toString();
    }
}
