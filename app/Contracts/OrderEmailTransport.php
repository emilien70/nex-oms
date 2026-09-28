<?php

namespace App\Contracts;

use App\Models\EmailAccount;
use Symfony\Component\Mime\Email;

interface OrderEmailTransport
{
    public function send(EmailAccount $account, Email $email): void;
}
