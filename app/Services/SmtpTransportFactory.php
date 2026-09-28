<?php

namespace App\Services;

use App\Enums\EmailAccountEncryption;
use App\Exceptions\SmtpConnectionException;
use App\Models\EmailAccount;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

class SmtpTransportFactory
{
    public function make(EmailAccount $account): EsmtpTransport
    {
        $password = $account->smtp_password;

        if (! is_string($password) || $password === '') {
            throw new SmtpConnectionException('Konto SMTP nie ma zapisanego hasła.');
        }

        $transport = new EsmtpTransport(
            host: $account->smtp_host,
            port: $account->smtp_port,
            tls: $account->encryption === EmailAccountEncryption::Tls,
            stream: (new SocketStream)->setTimeout(10),
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

        return $transport;
    }
}
