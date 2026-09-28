<?php

namespace Tests\Unit;

use App\Enums\EmailAccountEncryption;
use App\Exceptions\SmtpConnectionException;
use App\Models\EmailAccount;
use App\Services\SmtpTransportFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Tests\TestCase;

class SmtpTransportFactoryTest extends TestCase
{
    #[DataProvider('encryptionProvider')]
    public function test_factory_maps_saved_account_to_smtp_transport_without_connecting(
        EmailAccountEncryption $encryption,
        int $port,
        bool $streamTls,
        bool $autoTls,
        bool $requireTls,
    ): void {
        $account = new EmailAccount([
            'name' => 'Test',
            'email' => 'sender@example.test',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => $port,
            'smtp_username' => 'login@example.test',
            'smtp_password' => 'FAKE_PASSWORD',
            'encryption' => $encryption,
        ]);

        $transport = app(SmtpTransportFactory::class)->make($account);
        $stream = $transport->getStream();

        $this->assertInstanceOf(SocketStream::class, $stream);
        $this->assertSame('smtp.example.test', $stream->getHost());
        $this->assertSame($port, $stream->getPort());
        $this->assertSame(10.0, $stream->getTimeout());
        $this->assertSame($streamTls, $stream->isTLS());
        $this->assertSame($autoTls, $transport->isAutoTls());
        $this->assertSame($requireTls, $transport->isTlsRequired());
        $this->assertSame('login@example.test', $transport->getUsername());
        $this->assertSame('FAKE_PASSWORD', $transport->getPassword());
    }

    public function test_factory_rejects_account_without_password_before_network_activity(): void
    {
        $account = new EmailAccount([
            'name' => 'Test',
            'email' => 'sender@example.test',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_username' => 'login@example.test',
            'encryption' => EmailAccountEncryption::StartTls,
        ]);

        $this->expectException(SmtpConnectionException::class);
        $this->expectExceptionMessage('Konto SMTP nie ma zapisanego hasła.');

        app(SmtpTransportFactory::class)->make($account);
    }

    /** @return array<string, array{EmailAccountEncryption, int, bool, bool, bool}> */
    public static function encryptionProvider(): array
    {
        return [
            'STARTTLS' => [EmailAccountEncryption::StartTls, 587, false, true, true],
            'SSL/TLS' => [EmailAccountEncryption::Tls, 465, true, true, false],
            'bez szyfrowania' => [EmailAccountEncryption::None, 25, false, false, false],
        ];
    }
}
