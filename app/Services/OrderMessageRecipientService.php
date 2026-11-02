<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Validation\ValidationException;
use Modules\Integrations\MacroDroid\Services\SmsPhoneNumber;

class OrderMessageRecipientService
{
    public function recipient(Order $order, string $channel): string
    {
        if ($channel === 'sms') {
            return SmsPhoneNumber::normalize($order->customer_phone);
        }

        $email = (string) $order->customer_email;
        if (preg_match('/[\r\n]/', $email) || filter_var(trim($email), FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['recipient' => 'Uzupelnij poprawny adres e-mail klienta w zamowieniu.']);
        }

        return mb_strtolower(trim($email));
    }

    public function token(Order $order, string $channel): string
    {
        return hash_hmac('sha256', json_encode([
            'order-message-recipient-v1', $order->id, $channel, $this->recipient($order, $channel),
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function assertConfirmation(Order $order, string $channel, ?string $token): void
    {
        if ($token !== null && ! hash_equals($this->token($order, $channel), $token)) {
            throw ValidationException::withMessages([
                'recipient_token' => 'Dane kontaktowe klienta zmienily sie. Kliknij Wyslij ponownie, aby pobrac aktualnego odbiorce.',
            ]);
        }
    }
}
