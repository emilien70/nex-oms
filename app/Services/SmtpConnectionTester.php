<?php

namespace App\Services;

use App\Enums\EmailAccountEncryption;
use App\Exceptions\SmtpConnectionException;
use App\Models\EmailAccount;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Throwable;

class SmtpConnectionTester
{
    public function test(EmailAccount $account): void
    {
        $transport = null;

        try {
            $password = $account->smtp_password;

            if (! is_string($password) || $password === '') {
                throw new SmtpConnectionException('Konto SMTP nie ma zapisanego hasła.');
            }

            $stream = (new SocketStream)->setTimeout(10);
            $implicitTls = $account->encryption === EmailAccountEncryption::Tls;
            $transport = new EsmtpTransport(
                host: $account->smtp_host,
                port: $account->smtp_port,
                tls: $implicitTls,
                stream: $stream,
            );

            if ($account->encryption === EmailAccountEncryption::StartTls) {
                $transport->setAutoTls(true);
                $transport->setRequireTls(true);
            } elseif ($account->encryption === EmailAccountEncryption::None) {
                $transport->setAutoTls(false);
                $transport->setRequireTls(false);
            }

            $transport->setUsername($account->smtp_username);
            $transport->setPassword($password);
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
