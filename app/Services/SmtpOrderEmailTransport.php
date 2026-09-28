<?php

namespace App\Services;

use App\Contracts\OrderEmailTransport;
use App\Exceptions\OrderEmailTransportException;
use App\Models\EmailAccount;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;
use Throwable;

class SmtpOrderEmailTransport implements OrderEmailTransport
{
    public function __construct(
        private readonly SmtpTransportFactory $transports,
    ) {}

    public function send(EmailAccount $account, Email $email): void
    {
        $transport = null;

        try {
            $transport = $this->transports->make($account);
            $transport->start();
        } catch (Throwable $exception) {
            $this->stop($transport);

            throw new OrderEmailTransportException(false, $exception);
        }

        try {
            (new Mailer($transport))->send($email);
        } catch (Throwable $exception) {
            throw new OrderEmailTransportException(true, $exception);
        } finally {
            $this->stop($transport);
        }
    }

    private function stop(?EsmtpTransport $transport): void
    {
        if (! $transport instanceof EsmtpTransport) {
            return;
        }

        try {
            $transport->stop();
        } catch (Throwable) {
            // Zamknięcie połączenia nie może zmienić wyniku wysyłki.
        }
    }
}
