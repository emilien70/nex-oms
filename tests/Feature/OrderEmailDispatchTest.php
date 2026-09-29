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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Automation\Models\AutomationRun;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Models\InvoiceSeries;
use Modules\Invoices\Services\InvoicePdfService;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $message->intent_fingerprint);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $message->smtp_account_fingerprint);
        $this->assertSame(1, $message->attachment_count);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $message->attachment_manifest_sha256);
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

    #[DataProvider('intentConflictProvider')]
    public function test_reused_idempotency_key_rejects_changed_intent(string $changedField): void
    {
        $order = $this->order();
        $template = $this->template();
        $key = (string) Str::uuid();
        $user = User::factory()->create(['name' => 'Operator pierwszy']);
        $first = new OrderEmailPreparationData(
            toEmail: 'Klient@Example.test',
            toName: " Klient\r\ntestowy ",
            source: OrderEmailMessageSource::Manual,
            idempotencyKey: $key,
            user: $user,
        );
        $secondArguments = [
            'toEmail' => 'klient@example.test',
            'toName' => 'Klient testowy',
            'source' => OrderEmailMessageSource::Manual,
            'idempotencyKey' => $key,
            'user' => $user,
        ];

        match ($changedField) {
            'subject' => $secondArguments['subjectTemplate'] = 'Inny temat [id_zamowienia]',
            'body' => $secondArguments['bodyTemplate'] = 'Inna treść [id_zamowienia]',
            'format' => $secondArguments['format'] = EmailTemplateFormat::PlainText,
            'actor' => $secondArguments['user'] = User::factory()->create(['name' => 'Operator drugi']),
            'to_name' => $secondArguments['toName'] = 'Inny klient',
        };

        app(OrderEmailPreparationService::class)->prepareAndQueue($order, $template, $first);

        try {
            app(OrderEmailPreparationService::class)->prepareAndQueue(
                $order,
                $template,
                new OrderEmailPreparationData(...$secondArguments),
            );
            $this->fail('A changed intent must not reuse an existing message.');
        } catch (OrderEmailException $exception) {
            $this->assertSame('idempotency_conflict', $exception->safeCode());
        }

        $this->assertDatabaseCount('order_email_messages', 1);
        Queue::assertPushed(SendOrderEmailJob::class, 1);
    }

    public function test_reused_idempotency_key_rejects_changed_automation_run_and_retry_reference(): void
    {
        $order = $this->order();
        $template = $this->template();
        $firstRun = $this->automationRun($order);
        $secondRun = $this->automationRun($order);
        $automationKey = (string) Str::uuid();

        app(OrderEmailPreparationService::class)->prepareAndQueue(
            $order,
            $template,
            new OrderEmailPreparationData(
                toEmail: 'klient@example.test',
                toName: null,
                source: OrderEmailMessageSource::Automation,
                idempotencyKey: $automationKey,
                automationRun: $firstRun,
            ),
        );

        $this->assertIntentConflict(fn () => app(OrderEmailPreparationService::class)->prepareAndQueue(
            $order,
            $template,
            new OrderEmailPreparationData(
                toEmail: 'klient@example.test',
                toName: null,
                source: OrderEmailMessageSource::Automation,
                idempotencyKey: $automationKey,
                automationRun: $secondRun,
            ),
        ));

        $user = User::factory()->create();
        $firstOriginal = app(OrderEmailPreparationService::class)->prepareAndQueue(
            $order,
            $template,
            new OrderEmailPreparationData(
                toEmail: 'klient@example.test',
                toName: null,
                source: OrderEmailMessageSource::Manual,
                idempotencyKey: (string) Str::uuid(),
                user: $user,
            ),
        );
        $secondOriginal = app(OrderEmailPreparationService::class)->prepareAndQueue(
            $order,
            $template,
            new OrderEmailPreparationData(
                toEmail: 'klient@example.test',
                toName: null,
                source: OrderEmailMessageSource::Manual,
                idempotencyKey: (string) Str::uuid(),
                user: $user,
            ),
        );
        $retryKey = (string) Str::uuid();

        app(OrderEmailPreparationService::class)->prepareAndQueue(
            $order,
            $template,
            new OrderEmailPreparationData(
                toEmail: 'klient@example.test',
                toName: null,
                source: OrderEmailMessageSource::Manual,
                idempotencyKey: $retryKey,
                user: $user,
                retryOf: $firstOriginal,
            ),
        );

        $this->assertIntentConflict(fn () => app(OrderEmailPreparationService::class)->prepareAndQueue(
            $order,
            $template,
            new OrderEmailPreparationData(
                toEmail: 'klient@example.test',
                toName: null,
                source: OrderEmailMessageSource::Manual,
                idempotencyKey: $retryKey,
                user: $user,
                retryOf: $secondOriginal,
            ),
        ));
    }

    public function test_template_edit_does_not_change_an_existing_operation_intent(): void
    {
        $order = $this->order();
        $template = $this->template();
        $data = $this->data((string) Str::uuid());
        $message = app(OrderEmailPreparationService::class)->prepareAndQueue($order, $template, $data);

        $template->update([
            'subject_template' => 'Nowy temat [id_zamowienia]',
            'body_template' => '<p>Nowa treść</p>',
        ]);

        $same = app(OrderEmailPreparationService::class)->prepareAndQueue($order, $template->fresh(), $data);

        $this->assertTrue($message->is($same));
        $this->assertSame('Zamówienie 1', $same->subject);
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

    public function test_sender_accepts_a_sealed_empty_attachment_manifest(): void
    {
        $order = $this->order();
        $template = $this->template();
        $message = app(OrderEmailPreparationService::class)->prepareAndQueue(
            $order,
            $template,
            $this->data((string) Str::uuid()),
        );
        $transport = new FakeOrderEmailTransport;
        $this->app->instance(OrderEmailTransport::class, $transport);

        app(OrderEmailSender::class)->send($message->getKey());

        $this->assertSame(0, $message->attachment_count);
        $this->assertSame(hash('sha256', '[]'), $message->attachment_manifest_sha256);
        $this->assertSame(OrderEmailMessageStatus::Sent, $message->fresh()->status);
        $this->assertSame(1, $transport->sendCount);
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

    public function test_deleted_attachment_record_is_rejected_by_manifest_before_transport(): void
    {
        $message = $this->preparedMessage();
        $message->attachments->sole()->delete();
        $transport = new FakeOrderEmailTransport;
        $this->app->instance(OrderEmailTransport::class, $transport);

        app(OrderEmailSender::class)->send($message->getKey());

        $message->refresh();
        $this->assertSame(OrderEmailMessageStatus::Failed, $message->status);
        $this->assertSame('attachment_manifest_mismatch', $message->safe_error_code);
        $this->assertSame(0, $transport->sendCount);
    }

    public function test_added_attachment_record_is_rejected_by_manifest_before_transport(): void
    {
        $message = $this->preparedMessage();
        $attachment = $message->attachments->sole();
        $message->attachments()->create([
            'position' => 2,
            'kind' => $attachment->kind,
            'disk' => $attachment->disk,
            'file_path' => $attachment->file_path,
            'file_name' => 'unexpected.txt',
            'mime_type' => $attachment->mime_type,
            'file_size' => $attachment->file_size,
            'checksum_sha256' => $attachment->checksum_sha256,
        ]);
        $transport = new FakeOrderEmailTransport;
        $this->app->instance(OrderEmailTransport::class, $transport);

        app(OrderEmailSender::class)->send($message->getKey());

        $message->refresh();
        $this->assertSame(OrderEmailMessageStatus::Failed, $message->status);
        $this->assertSame('attachment_manifest_mismatch', $message->safe_error_code);
        $this->assertSame(0, $transport->sendCount);
    }

    #[DataProvider('smtpTransportChangeProvider')]
    public function test_transport_configuration_change_blocks_sending(string $attribute, mixed $value): void
    {
        $message = $this->preparedMessage();
        $message->emailAccount->update([$attribute => $value]);
        $transport = new FakeOrderEmailTransport;
        $this->app->instance(OrderEmailTransport::class, $transport);

        app(OrderEmailSender::class)->send($message->getKey());

        $message->refresh();
        $this->assertSame(OrderEmailMessageStatus::Failed, $message->status);
        $this->assertSame('email_account_changed', $message->safe_error_code);
        $this->assertStringNotContainsString('FAKE_CHANGED_PASSWORD', (string) $message->safe_error_message);
        $this->assertSame(0, $transport->sendCount);
    }

    public function test_account_display_name_change_does_not_block_sending(): void
    {
        $message = $this->preparedMessage();
        $message->emailAccount->update(['name' => 'Nowa nazwa opisowa']);
        $transport = new FakeOrderEmailTransport;
        $this->app->instance(OrderEmailTransport::class, $transport);

        app(OrderEmailSender::class)->send($message->getKey());

        $this->assertSame(OrderEmailMessageStatus::Sent, $message->fresh()->status);
        $this->assertSame(1, $transport->sendCount);
    }

    public function test_legacy_message_without_integrity_fingerprints_fails_before_transport(): void
    {
        $message = $this->preparedMessage();
        DB::table('order_email_messages')->where('id', $message->getKey())->update([
            'smtp_account_fingerprint' => null,
            'attachment_count' => null,
            'attachment_manifest_sha256' => null,
        ]);
        $transport = new FakeOrderEmailTransport;
        $this->app->instance(OrderEmailTransport::class, $transport);

        app(OrderEmailSender::class)->send($message->getKey());

        $message->refresh();
        $this->assertSame(OrderEmailMessageStatus::Failed, $message->status);
        $this->assertSame('email_account_changed', $message->safe_error_code);
        $this->assertSame(0, $transport->sendCount);
    }

    public function test_message_without_attachment_seal_fails_as_manifest_mismatch(): void
    {
        $message = $this->preparedMessage();
        DB::table('order_email_messages')->where('id', $message->getKey())->update([
            'attachment_count' => null,
            'attachment_manifest_sha256' => null,
        ]);
        $transport = new FakeOrderEmailTransport;
        $this->app->instance(OrderEmailTransport::class, $transport);

        app(OrderEmailSender::class)->send($message->getKey());

        $message->refresh();
        $this->assertSame(OrderEmailMessageStatus::Failed, $message->status);
        $this->assertSame('attachment_manifest_mismatch', $message->safe_error_code);
        $this->assertSame(0, $transport->sendCount);
    }

    public function test_missing_smtp_password_fails_safely_before_transport(): void
    {
        $message = $this->preparedMessage();
        $message->emailAccount->update(['smtp_password' => null]);
        $transport = new FakeOrderEmailTransport;
        $this->app->instance(OrderEmailTransport::class, $transport);

        app(OrderEmailSender::class)->send($message->getKey());

        $message->refresh();
        $this->assertSame(OrderEmailMessageStatus::Failed, $message->status);
        $this->assertSame('email_account_unavailable', $message->safe_error_code);
        $this->assertStringNotContainsString(
            'FAKE_SMTP_PASSWORD_MUST_NOT_BE_SERIALIZED',
            (string) $message->safe_error_message,
        );
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

    private function automationRun(Order $order): AutomationRun
    {
        return AutomationRun::query()->create([
            'order_id' => $order->getKey(),
            'event_id' => (string) Str::uuid(),
            'event_name' => 'order_status_changed',
            'chain_id' => (string) Str::uuid(),
            'depth' => 0,
            'status' => AutomationRun::STATUS_RUNNING,
            'event_payload' => [],
            'rule_snapshot' => [],
        ]);
    }

    private function assertIntentConflict(callable $callback): void
    {
        try {
            $callback();
            $this->fail('A changed intent must not reuse an existing message.');
        } catch (OrderEmailException $exception) {
            $this->assertSame('idempotency_conflict', $exception->safeCode());
        }
    }

    /** @return array<string, array{string}> */
    public static function intentConflictProvider(): array
    {
        return [
            'subject override' => ['subject'],
            'body override' => ['body'],
            'format override' => ['format'],
            'actor' => ['actor'],
            'recipient name' => ['to_name'],
        ];
    }

    /** @return array<string, array{string, mixed}> */
    public static function smtpTransportChangeProvider(): array
    {
        return [
            'host' => ['smtp_host', 'changed.example.test'],
            'port' => ['smtp_port', 2525],
            'username' => ['smtp_username', 'changed@example.test'],
            'password' => ['smtp_password', 'FAKE_CHANGED_PASSWORD'],
            'encryption' => ['encryption', EmailAccountEncryption::Tls],
            'sender email' => ['email', 'changed-sender@example.test'],
        ];
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
