<?php

namespace Tests\Feature;

use App\Contracts\OrderEmailTransport;
use App\Enums\EmailAccountEncryption;
use App\Enums\EmailTemplateAttachmentType;
use App\Enums\EmailTemplateDocumentType;
use App\Enums\EmailTemplateFormat;
use App\Enums\OrderEmailMessageSource;
use App\Enums\OrderEmailMessageStatus;
use App\Exceptions\OrderEmailException;
use App\Exceptions\OrderEmailTransportException;
use App\Jobs\SendOrderEmailJob;
use App\Models\EmailAccount;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\OrderEmailMessage;
use App\Models\User;
use App\Services\OrderEmailPreparationService;
use App\Services\OrderEmailSender;
use App\ValueObjects\OrderEmailPreparationData;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Automation\Models\AutomationRun;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\InvoicePdfService;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class OrderEmailDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();
    }

    public function test_preparation_renders_safe_snapshot_freezes_upload_and_queues_once(): void
    {
        $order = $this->order([
            'billing_name' => '<img src=x onerror=alert(1)> Jan Kowalski',
        ]);
        $template = $this->template([
            'subject_template' => "Potwierdzenie [id_zamowienia]\r\nBCC: hidden@example.test",
            'format' => EmailTemplateFormat::Html,
            'body_template' => '<p>Witaj [imie_i_nazwisko_nabywcy]</p><script>alert(1)</script>'
                .'<a href="javascript:alert(2)" onclick="alert(3)">link</a>',
        ]);
        $sourcePath = "email-templates/{$template->getKey()}/attachments/terms.txt";
        Storage::disk('local')->put($sourcePath, 'ORIGINAL ATTACHMENT');
        $template->attachments()->create([
            'slot' => 1,
            'type' => EmailTemplateAttachmentType::Upload,
            'file_path' => $sourcePath,
            'original_name' => 'warunki.txt',
            'mime_type' => 'text/plain',
            'file_size' => strlen('ORIGINAL ATTACHMENT'),
        ]);
        $key = (string) Str::uuid();
        $data = $this->data($key);

        $message = app(OrderEmailPreparationService::class)->prepareAndQueue($order, $template, $data);
        $sameMessage = app(OrderEmailPreparationService::class)->prepareAndQueue($order, $template, $data);

        $this->assertTrue($message->is($sameMessage));
        $this->assertSame(OrderEmailMessageStatus::Queued, $message->status);
        $this->assertSame('Potwierdzenie 1 BCC: hidden@example.test', $message->subject);
        $this->assertStringNotContainsString('<script', (string) $message->body_html);
        $this->assertStringNotContainsString('<img', (string) $message->body_html);
        $this->assertStringNotContainsString('onclick', (string) $message->body_html);
        $this->assertStringNotContainsString('javascript:', (string) $message->body_html);
        $this->assertStringContainsString('&lt;img', (string) $message->body_html);
        $this->assertStringContainsString('Jan Kowalski', $message->body_text);
        $this->assertSame("order-email-{$key}@example.test", $message->message_id);
        $attachment = $message->attachments->sole();
        $this->assertNotSame($sourcePath, $attachment->file_path);
        $this->assertSame('warunki.txt', $attachment->file_name);
        $this->assertSame(hash('sha256', 'ORIGINAL ATTACHMENT'), $attachment->checksum_sha256);
        Storage::disk('local')->assertExists($attachment->file_path);

        Storage::disk('local')->put($sourcePath, 'CHANGED TEMPLATE ATTACHMENT');
        $this->assertSame('ORIGINAL ATTACHMENT', Storage::disk('local')->get($attachment->file_path));

        Queue::assertPushedOn('default', SendOrderEmailJob::class, function (SendOrderEmailJob $job) use ($message): bool {
            return $job->messageId === $message->getKey();
        });
        Queue::assertPushed(SendOrderEmailJob::class, 1);
    }

    public function test_document_attachment_uses_existing_pdf_service_and_freezes_document_identity(): void
    {
        $order = $this->order();
        $series = InvoiceSeries::query()->create([
            'document_type' => InvoiceDocumentType::Invoice,
            'name' => 'Seria testowa e-mail',
            'number_format' => 'TEST %N/%Y',
            'reset_period' => 'yearly',
            'fiscal_year_start_month' => 1,
            'is_active' => true,
            'default_currency' => 'PLN',
        ]);
        $invoice = Invoice::query()->create([
            'order_id' => $order->getKey(),
            'invoice_series_id' => $series->getKey(),
            'document_type' => InvoiceDocumentType::Invoice,
            'status' => InvoiceDocumentStatus::Issued,
            'number' => 'TEST 1/2026',
            'issued_at' => '2026-09-28 10:15:00',
        ]);
        $template = $this->template();
        $template->attachments()->create([
            'slot' => 1,
            'type' => EmailTemplateAttachmentType::Document,
            'document_type' => EmailTemplateDocumentType::InvoicePdf,
        ]);
        $pdf = '%PDF-1.7 FAKE DOCUMENT';
        $this->mock(InvoicePdfService::class, function ($mock) use ($invoice, $pdf): void {
            $mock->shouldReceive('contents')
                ->once()
                ->withArgs(fn (Invoice $document): bool => $document->is($invoice))
                ->andReturn($pdf);
        });

        $message = app(OrderEmailPreparationService::class)->prepareAndQueue(
            $order,
            $template,
            $this->data((string) Str::uuid()),
        );

        $attachment = $message->attachments->sole();
        $this->assertSame('TEST_1-2026.pdf', $attachment->file_name);
        $this->assertSame('application/pdf', $attachment->mime_type);
        $this->assertSame($invoice->getKey(), $attachment->source_snapshot['invoice_id']);
        $this->assertSame('TEST 1/2026', $attachment->source_snapshot['document_number']);
        $this->assertSame($pdf, Storage::disk('local')->get($attachment->file_path));
    }

    public function test_sender_delivers_snapshot_exactly_once_without_serializing_smtp_password(): void
    {
        $message = $this->preparedMessage();
        $transport = new FakeOrderEmailTransport;
        $this->app->instance(OrderEmailTransport::class, $transport);

        app(OrderEmailSender::class)->send($message->getKey());
        app(OrderEmailSender::class)->send($message->getKey());

        $message->refresh();
        $this->assertSame(OrderEmailMessageStatus::Sent, $message->status);
        $this->assertSame(1, $message->attempt_count);
        $this->assertNotNull($message->sent_at);
        $this->assertSame(1, $transport->sendCount);
        $this->assertInstanceOf(Email::class, $transport->email);
        $this->assertSame($message->subject, $transport->email->getSubject());
        $this->assertSame($message->body_text, $transport->email->getTextBody());
        $this->assertSame($message->body_html, $transport->email->getHtmlBody());
        $this->assertSame('sprzedaz@example.test', $transport->email->getFrom()[0]->getAddress());
        $this->assertSame('klient@example.test', $transport->email->getTo()[0]->getAddress());
        $this->assertCount(1, $transport->email->getAttachments());
        $this->assertStringContainsString($message->message_id, $transport->email->getHeaders()->toString());
        $this->assertStringNotContainsString(
            'FAKE_SMTP_PASSWORD_MUST_NOT_BE_SERIALIZED',
            serialize(new SendOrderEmailJob($message->getKey())),
        );
    }

    public function test_job_is_unique_one_shot_and_marks_pre_send_failure_without_transport(): void
    {
        $message = $this->preparedMessage();
        $job = new SendOrderEmailJob($message->getKey());

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame(1, $job->tries);
        $this->assertSame(60, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
        $this->assertSame('default', $job->queue);
        $this->assertSame("order-email-{$message->getKey()}", $job->uniqueId());

        $job->failed(new \RuntimeException('FAKE QUEUE FAILURE'));

        $message->refresh();
        $this->assertSame(OrderEmailMessageStatus::Failed, $message->status);
        $this->assertSame('job_failed_before_send', $message->safe_error_code);
    }

    public function test_automation_source_preserves_run_provenance_without_user_or_smtp_secret(): void
    {
        $order = $this->order();
        $template = $this->template();
        $run = AutomationRun::query()->create([
            'order_id' => $order->getKey(),
            'event_id' => (string) Str::uuid(),
            'event_name' => 'order_status_changed',
            'chain_id' => (string) Str::uuid(),
            'depth' => 0,
            'status' => AutomationRun::STATUS_RUNNING,
            'event_payload' => [],
            'rule_snapshot' => [],
        ]);

        $message = app(OrderEmailPreparationService::class)->prepareAndQueue(
            $order,
            $template,
            new OrderEmailPreparationData(
                toEmail: 'klient@example.test',
                toName: 'Klient testowy',
                source: OrderEmailMessageSource::Automation,
                idempotencyKey: (string) Str::uuid(),
                automationRun: $run,
            ),
        );

        $this->assertSame(OrderEmailMessageSource::Automation, $message->source);
        $this->assertSame($run->getKey(), $message->automation_run_id);
        $this->assertNull($message->created_by_user_id);
        $this->assertSame('automation', $message->actor_snapshot['type']);
        $this->assertFalse(array_key_exists('smtp_password', $message->getAttributes()));
    }

    public function test_connection_failure_is_known_and_does_not_leak_transport_diagnostics(): void
    {
        $secret = 'FAKE_SMTP_SECRET_FROM_EXCEPTION';
        $message = $this->preparedMessage();
        $transport = new FakeOrderEmailTransport(
            new OrderEmailTransportException(false, new \RuntimeException("diagnostic {$secret}")),
        );
        $this->app->instance(OrderEmailTransport::class, $transport);

        app(OrderEmailSender::class)->send($message->getKey());

        $message->refresh();
        $this->assertSame(OrderEmailMessageStatus::Failed, $message->status);
        $this->assertSame('smtp_connection_failed', $message->safe_error_code);
        $this->assertStringNotContainsString($secret, (string) $message->safe_error_message);
    }

    public function test_failure_after_smtp_send_begins_is_unknown_and_is_not_retried(): void
    {
        $message = $this->preparedMessage();
        $transport = new FakeOrderEmailTransport(new OrderEmailTransportException(true));
        $this->app->instance(OrderEmailTransport::class, $transport);

        app(OrderEmailSender::class)->send($message->getKey());
        app(OrderEmailSender::class)->send($message->getKey());

        $message->refresh();
        $this->assertSame(OrderEmailMessageStatus::Unknown, $message->status);
        $this->assertSame('smtp_result_unknown', $message->safe_error_code);
        $this->assertSame(1, $message->attempt_count);
        $this->assertSame(1, $transport->sendCount);
    }

    public function test_changed_attachment_is_rejected_before_transport_is_called(): void
    {
        $message = $this->preparedMessage();
        $attachment = $message->attachments->sole();
        Storage::disk('local')->put($attachment->file_path, 'TAMPERED');
        $transport = new FakeOrderEmailTransport;
        $this->app->instance(OrderEmailTransport::class, $transport);

        app(OrderEmailSender::class)->send($message->getKey());

        $message->refresh();
        $this->assertSame(OrderEmailMessageStatus::Failed, $message->status);
        $this->assertSame('message_snapshot_invalid', $message->safe_error_code);
        $this->assertSame(0, $transport->sendCount);
    }

    public function test_missing_account_fails_before_smtp_and_keeps_snapshot(): void
    {
        $message = $this->preparedMessage();
        $message->emailAccount->delete();
        $transport = new FakeOrderEmailTransport;
        $this->app->instance(OrderEmailTransport::class, $transport);

        app(OrderEmailSender::class)->send($message->getKey());

        $message->refresh();
        $this->assertSame(OrderEmailMessageStatus::Failed, $message->status);
        $this->assertSame('email_account_unavailable', $message->safe_error_code);
        $this->assertSame('Sklep testowy', $message->account_name_snapshot);
        $this->assertSame(0, $transport->sendCount);
    }

    public function test_preparation_failure_rolls_back_message_and_private_files(): void
    {
        $order = $this->order();
        $template = $this->template();
        $template->attachments()->create([
            'slot' => 1,
            'type' => EmailTemplateAttachmentType::Upload,
            'file_path' => "email-templates/{$template->getKey()}/attachments/missing.pdf",
            'original_name' => 'missing.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 123,
        ]);

        try {
            app(OrderEmailPreparationService::class)->prepareAndQueue(
                $order,
                $template,
                $this->data((string) Str::uuid()),
            );
            $this->fail('Preparation should fail for a missing template attachment.');
        } catch (OrderEmailException $exception) {
            $this->assertSame('attachment_missing', $exception->safeCode());
        }

        $this->assertDatabaseCount('order_email_messages', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('order-email-messages'));
        Queue::assertNothingPushed();
    }

    public function test_deleting_message_or_order_removes_its_private_directory(): void
    {
        $first = $this->preparedMessage();
        $firstPath = $first->attachments->sole()->file_path;
        $first->delete();
        Storage::disk('local')->assertMissing($firstPath);

        $second = $this->preparedMessage();
        $secondPath = $second->attachments->sole()->file_path;
        $second->order->forceDelete();
        Storage::disk('local')->assertMissing($secondPath);
    }

    private function preparedMessage(): OrderEmailMessage
    {
        $order = $this->order();
        $template = $this->template();
        $sourcePath = "email-templates/{$template->getKey()}/attachments/terms.txt";
        Storage::disk('local')->put($sourcePath, 'FROZEN ATTACHMENT');
        $template->attachments()->create([
            'slot' => 1,
            'type' => EmailTemplateAttachmentType::Upload,
            'file_path' => $sourcePath,
            'original_name' => 'terms.txt',
            'mime_type' => 'text/plain',
            'file_size' => strlen('FROZEN ATTACHMENT'),
        ]);

        return app(OrderEmailPreparationService::class)->prepareAndQueue(
            $order,
            $template,
            $this->data((string) Str::uuid()),
        );
    }

    private function data(string $idempotencyKey): OrderEmailPreparationData
    {
        return new OrderEmailPreparationData(
            toEmail: 'klient@example.test',
            toName: 'Klient testowy',
            source: OrderEmailMessageSource::Manual,
            idempotencyKey: $idempotencyKey,
            user: User::factory()->create(['name' => 'Operator testowy']),
        );
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'source' => 'manual',
            'status' => Order::STATUS_NEW,
            'currency' => 'PLN',
            'customer_email' => 'klient@example.test',
            'billing_name' => 'Jan Kowalski',
        ], $overrides));
    }

    private function template(array $overrides = []): EmailTemplate
    {
        $account = EmailAccount::query()->create([
            'name' => 'Sklep testowy',
            'email' => 'sprzedaz@example.test',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_username' => 'sprzedaz@example.test',
            'smtp_password' => 'FAKE_SMTP_PASSWORD_MUST_NOT_BE_SERIALIZED',
            'encryption' => EmailAccountEncryption::StartTls,
        ]);

        return EmailTemplate::query()->create(array_merge([
            'email_account_id' => $account->getKey(),
            'name' => 'Potwierdzenie zamówienia',
            'subject_template' => 'Zamówienie [id_zamowienia]',
            'format' => EmailTemplateFormat::Html,
            'body_template' => '<p>Witaj [imie_i_nazwisko_nabywcy]</p>',
            'is_hidden' => false,
        ], $overrides));
    }
}

final class FakeOrderEmailTransport implements OrderEmailTransport
{
    public int $sendCount = 0;

    public ?Email $email = null;

    public function __construct(
        private readonly ?OrderEmailTransportException $exception = null,
    ) {}

    public function send(EmailAccount $account, Email $email): void
    {
        $this->sendCount++;
        $this->email = clone $email;

        if ($this->exception instanceof OrderEmailTransportException) {
            throw $this->exception;
        }
    }
}
