<?php

namespace Modules\Integrations\MacroDroid\Services;

use App\Exceptions\OrderEmailException;
use App\Models\Order;
use App\Services\OrderVariableService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\CustomerPortal\Models\PortalLink;
use Modules\CustomerPortal\Models\PortalSetting;
use Modules\CustomerPortal\Services\PortalAccessService;
use Modules\Integrations\MacroDroid\Jobs\NotifySmsDeviceJob;
use Modules\Integrations\MacroDroid\Models\SmsDevice;
use Modules\Integrations\MacroDroid\Models\SmsMessage;
use Modules\Integrations\MacroDroid\Models\SmsTemplate;
use Ramsey\Uuid\Uuid;

class SmsPreparationService
{
    public function __construct(private readonly OrderVariableService $variables, private readonly SmsSegments $segments) {}

    public function validateTemplate(string $text, bool $checkVariables = true): void
    {
        $unknown = $checkVariables ? $this->variables->unknownVariables($text) : [];
        if (trim($text) === '' || strlen($text) > 12000 || ! mb_check_encoding($text, 'UTF-8')
            || preg_match('/[\x00-\x08\x0B\x0E-\x1F\x7F]/u', $text) || $unknown !== []) {
            $this->invalid('body', $unknown ? 'Nieznane zmienne: '.implode(', ', $unknown) : 'Tresc SMS jest pusta, za dluga lub zawiera niedozwolone znaki.');
        }
    }

    public function preview(Order $order, string $body): array
    {
        $this->validateTemplate($body);
        try {
            $rendered = $this->variables->render($body, $order);
        } catch (OrderEmailException $exception) {
            $this->invalid('body', $exception->getMessage());
        }
        $this->validateRendered($rendered, SmsDevice::configured()?->max_parts ?? 5);

        return ['body' => $rendered, ...$this->segments->measure($rendered)];
    }

    /** @return list<SmsMessage> */
    public function prepare(Order $order, ?SmsTemplate $template, array $data): array
    {
        return DB::transaction(function () use ($order, $template, $data): array {
            PortalSetting::query()->lockForUpdate()->first();
            $device = SmsDevice::query()->where('singleton_key', 'primary')->lockForUpdate()->first();
            $this->assertEnabled($device);
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $order->assertPurchaseReady();
            if ($template) {
                $template = SmsTemplate::query()->lockForUpdate()->find($template->id);
                if (! $template) {
                    $this->invalid('sms_template_id', 'Wybrany szablon SMS nie istnieje.');
                }
            }
            $body = $data['body'] ?? $template?->body_template ?? '';
            $this->validateTemplate($body);
            $customer = filter_var($data['recipient_customer'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $numbers = $data['recipient_phones'] ?? [];
            if (! is_array($numbers) || count($numbers) > 10) {
                $this->invalid('recipient_phones', 'Mozesz dodac maksymalnie 10 numerow.');
            }
            $recipients = [];
            if ($customer) {
                $recipients[SmsPhoneNumber::normalize($order->customer_phone)] = true;
            }
            foreach ($numbers as $number) {
                $recipients[SmsPhoneNumber::normalize($number)] ??= false;
            }
            try {
                $currentPhone = SmsPhoneNumber::normalize($order->customer_phone);
                if (array_key_exists($currentPhone, $recipients)) {
                    $recipients[$currentPhone] = true;
                }
            } catch (ValidationException) {
                // A custom recipient does not require a customer phone, unless the customer was selected.
            }
            if ($recipients === []) {
                $this->invalid('recipient_phones', 'Wybierz klienta lub podaj numer odbiorcy.');
            }
            $usesPortal = str_contains($body, '[link_zamowienia]');
            if ($usesPortal) {
                $buyerPhone = SmsPhoneNumber::normalize($order->customer_phone);
                if (count($recipients) !== 1 || ! array_key_exists($buyerPhone, $recipients)) {
                    $this->invalid('recipient_phones', 'Link strony klienta mozna wyslac tylko na numer klienta z zamowienia.');
                }
                $recipients[$buyerPhone] = true;
            }
            $key = $data['idempotency_key'] ?? '';
            if (! is_string($key) || ! Str::isUuid($key)) {
                $this->invalid('idempotency_key', 'Nieprawidlowy identyfikator zlecenia.');
            }
            $source = $data['source'] ?? 'manual';
            $intent = $this->hash([$order->id, $template?->id, $body, $recipients, $source, $data['automation_run_id'] ?? null]);
            $existing = SmsMessage::query()->where('batch_key', $key)->first();
            if ($existing) {
                if (! hash_equals($existing->intent_hash, $intent)) {
                    $this->invalid('idempotency_key', 'To zlecenie zapisano juz z innymi danymi. Otworz nowy formularz.');
                }

                return SmsMessage::query()->where('batch_key', $key)->orderBy('id')->get()->all();
            }
            if ($usesPortal) {
                try {
                    app(PortalAccessService::class)->issue($order);
                } catch (ValidationException $exception) {
                    $this->invalid('body', 'Link portalu jest niedostepny. Sprawdz ustawienia stron informacyjnych.');
                }
            }
            $rendered = $this->preview($order, $body);
            $messages = [];
            foreach ($recipients as $phone => $isCustomer) {
                $message = SmsMessage::query()->create([
                    'public_id' => (string) Str::uuid(), 'order_id' => $order->id,
                    'sms_template_id' => $template?->id, 'device_id' => $device->id,
                    'automation_run_id' => $data['automation_run_id'] ?? null,
                    'source' => $source, 'status' => 'queued',
                    'idempotency_key' => Uuid::uuid5($key, $phone)->toString(), 'intent_hash' => $intent,
                    'batch_key' => $key,
                    'customer_recipient' => $isCustomer, 'uses_portal' => $usesPortal,
                    'context_hash' => $isCustomer ? $this->context($order, $usesPortal) : null,
                    'device_generation' => $device->generation, 'recipient' => $phone,
                    'body' => $rendered['body'], 'template_name_snapshot' => $template?->name,
                    'actor_name' => $data['actor_name'] ?? 'Operator NEX-OMS',
                    'encoding' => $rendered['encoding'], 'units' => $rendered['units'], 'parts' => $rendered['parts'],
                    'expires_at' => now()->addMinutes($device->lifetime_minutes),
                ]);
                NotifySmsDeviceJob::dispatch($message->public_id, $device->generation)->afterCommit();
                $messages[] = $message;
            }

            return $messages;
        });
    }

    public function context(Order $order, bool $portal): string
    {
        $link = $portal ? PortalLink::query()->where('order_id', $order->id)->first() : null;

        return $this->hash([$order->id, SmsPhoneNumber::normalize($order->customer_phone),
            $link?->generation, $link?->token_hash, $link?->binding_hash]);
    }

    public function assertEnabled(?SmsDevice $device): void
    {
        if (! $device?->enabled || ! $device->token_hash || ! $device->webhook_url) {
            $this->invalid('device', 'Integracja MacroDroid jest wylaczona lub niekompletna.');
        }
    }

    private function validateRendered(string $body, int $maxParts): void
    {
        $this->validateTemplate($body, false);
        if ($this->segments->measure($body)['parts'] > $maxParts) {
            $this->invalid('body', 'Wiadomosc przekracza dozwolona liczbe czesci SMS po podstawieniu zmiennych.');
        }
    }

    private function hash(array $value): string
    {
        return hash_hmac('sha256', json_encode($value, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
