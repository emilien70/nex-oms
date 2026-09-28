<?php

namespace App\Services;

use App\Exceptions\SmtpConnectionException;
use App\Models\EmailAccount;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Throwable;

class SmtpConnectionTester
{
    public function __construct(
        private readonly SmtpTransportFactory $transports,
    ) {}

    public function test(EmailAccount $account): void
    {
        $transport = null;

        try {
            $transport = $this->transports->make($account);
            $transport->start();
        } catch (SmtpConnectionException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new SmtpConnectionException(
                'Nie udało się połączyć lub uwierzytelnić na serwerze SMTP. Sprawdź zapisane ustawienia konta.',
            );
        } finally {
            if ($transport instanceof EsmtpTransport) {
                try {
                    $transport->stop();
                } catch (Throwable) {
                    // Zamknięcie nieudanego połączenia nie może zmienić wyniku testu.
                }
            }
        }
    }
}
