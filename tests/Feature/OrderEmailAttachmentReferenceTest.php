<?php

namespace Tests\Feature;

use App\Contracts\OrderEmailTransport;
use App\Enums\EmailAccountEncryption;
use App\Enums\EmailTemplateAttachmentType;
use App\Enums\EmailTemplateDocumentType;
use App\Enums\EmailTemplateFormat;
use App\Enums\OrderEmailAttachmentSource;
use App\Enums\OrderEmailMessageSource;
use App\Enums\OrderEmailMessageStatus;
use App\Models\EmailAccount;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\OrderEmailMessage;
use App\Services\OrderEmailPreparationService;
use App\Services\OrderEmailSender;
use App\ValueObjects\OrderEmailPreparationData;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Invoices\Enums\InvoiceDocumentStatus;
use Modules\Invoices\Enums\InvoiceDocumentType;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Models\Invoice;
use Modules\Invoices\Services\InvoiceIssuingService;
use Modules\Invoices\Services\InvoicePdfFilenameGenerator;
use Modules\Invoices\Services\InvoicePdfService;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mime\Email;
use Tests\Feature\Invoices\Concerns\CreatesInvoiceStage2CDocuments;
use Tests\TestCase;

class OrderEmailAttachmentReferenceTest extends TestCase
{
    use CreatesInvoiceStage2CDocuments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('local');
    }

    #[DataProvider('documentTypes')]
    public function test_document_is_resolved_only_when_sending_and_uses_current_pdf(
        InvoiceDocumentType $type,
        string $filename,
    ): void {
        $invoice = $this->document($type);
        $this->mock(InvoicePdfService::class)->shouldReceive('contents')->once()
            ->withArgs(fn (Invoice $current): bool => $current->is($invoice)
                && $current->additional_information_text === 'Updated before sending')
            ->andReturn('%PDF-1.7 current contents');
        $message = $this->documentMessage($invoice);
        $reference = $message->attachments->sole();
        $this->assertSame(OrderEmailAttachmentSource::Document, $reference->source_mode);
        $this->assertNull($reference->file_path);
        $this->assertNull($reference->checksum_sha256);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $invoice->update(['additional_information_text' => 'Updated before sending']);
        $transport = $this->transport();

        app(OrderEmailSender::class)->send($message->id);
        app(OrderEmailSender::class)->send($message->id);

        $this->assertSame(OrderEmailMessageStatus::Sent, $message->fresh()->status);
        $this->assertSame(1, $transport->count);
        $this->assertSame('%PDF-1.7 current contents', $transport->email->getAttachments()[0]->getBody());
        $this->assertSame($filename, $transport->email->getAttachments()[0]->getFilename());
        $this->assertSame($filename, $message->attachments->sole()->file_name);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public static function documentTypes(): array
    {
        return [
            'invoice' => [InvoiceDocumentType::Invoice, 'Faktura N_6-2026.pdf'],
            'correction' => [InvoiceDocumentType::Correction, 'Korekta NK_2-2026.pdf'],
            'proforma' => [InvoiceDocumentType::Proforma, 'Proforma PF_1-2026.pdf'],
        ];
    }

    public function test_deleted_cache_is_recreated_but_no_message_copy_is_created(): void
    {
        $order = $this->createDocumentOrder();
        $this->createDocumentItem($order);
        $invoice = app(InvoiceIssuingService::class)->issue(
            $order, $this->createDocumentSeries(), $this->documentContext(),
        );
        $pdfs = app(InvoicePdfService::class);
        $pdfs->contents($invoice);
        $cache = app(InvoicePdfFilenameGenerator::class)->storagePath($invoice);
        $message = $this->documentMessage($invoice);
        Storage::disk('local')->delete($cache);
        $transport = $this->transport();

        app(OrderEmailSender::class)->send($message->id);

        $this->assertSame(OrderEmailMessageStatus::Sent, $message->fresh()->status);
        Storage::disk('local')->assertExists($cache);
        $this->assertStringStartsWith('%PDF-', $transport->email->getAttachments()[0]->getBody());
        $this->assertSame([], Storage::disk('local')->allFiles('order-email-messages'));
    }

    public function test_missing_document_fails_without_switching_to_a_replacement(): void
    {
        $invoice = $this->document();
        $message = $this->documentMessage($invoice);
        DB::table('invoices')->where('id', $invoice->id)->delete();
        Invoice::query()->create([
            ...$invoice->getAttributes(), 'id' => $invoice->id + 10, 'number' => 'N 7/2026',
        ]);
        $this->mock(InvoicePdfService::class)->shouldNotReceive('contents');
        $transport = $this->transport();

        app(OrderEmailSender::class)->send($message->id);

        $this->assertSame('document_attachment_missing', $message->fresh()->safe_error_code);
        $this->assertSame(OrderEmailMessageStatus::Failed, $message->fresh()->status);
        $this->assertSame(0, $transport->count);
    }

    public function test_pdf_generation_error_does_not_send_an_email_without_the_attachment(): void
    {
        $message = $this->documentMessage($this->document());
        $this->mock(InvoicePdfService::class)->shouldReceive('contents')->once()
            ->andThrow(new InvoiceDomainException('invoice_pdf_generation_failed', 'PDF unavailable'));
        $transport = $this->transport();

        app(OrderEmailSender::class)->send($message->id);

        $this->assertSame('document_attachment_unavailable', $message->fresh()->safe_error_code);
        $this->assertSame(0, $transport->count);
    }

    public function test_legacy_manifest_and_copy_remain_usable_after_the_template_source_is_deleted(): void
    {
        [$message, $sourcePath] = $this->uploadMessage();
        $attachment = $message->attachments->sole();
        $path = "order-email-messages/{$message->id}/attachments/legacy.txt";
        $contents = 'Previously frozen bytes';
        Storage::disk('local')->put($path, $contents);
        Storage::disk('local')->delete($sourcePath);
        DB::table('order_email_message_attachments')->where('id', $attachment->id)->update([
            'source_mode' => null, 'file_path' => $path, 'file_size' => strlen($contents),
            'checksum_sha256' => hash('sha256', $contents),
        ]);
        $legacyManifest = [[
            'position' => 1, 'kind' => 'upload', 'disk' => 'local', 'file_path' => $path,
            'file_name' => $attachment->file_name, 'mime_type' => $attachment->mime_type,
            'file_size' => strlen($contents), 'checksum_sha256' => hash('sha256', $contents),
        ]];
        DB::table('order_email_messages')->where('id', $message->id)->update([
            'attachment_manifest_sha256' => hash('sha256', json_encode(
                $legacyManifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            )),
        ]);
        $before = DB::table('order_email_message_attachments')->where('id', $attachment->id)->first();
        $transport = $this->transport();

        app(OrderEmailSender::class)->send($message->id);

        $this->assertSame(OrderEmailMessageStatus::Sent, $message->fresh()->status);
        $this->assertSame($contents, $transport->email->getAttachments()[0]->getBody());
        $this->assertEquals($before, DB::table('order_email_message_attachments')->where('id', $attachment->id)->first());
        Storage::disk('local')->assertExists($path);
    }

    public function test_document_moved_to_another_order_cannot_be_attached(): void
    {
        $invoice = $this->document();
        $message = $this->documentMessage($invoice);
        $invoice->update(['order_id' => $this->createDocumentOrder()->id]);
        $this->mock(InvoicePdfService::class)->shouldNotReceive('contents');
        $transport = $this->transport();

        app(OrderEmailSender::class)->send($message->id);

        $this->assertSame('document_attachment_missing', $message->fresh()->safe_error_code);
        $this->assertSame(0, $transport->count);
    }

    public function test_pdf_removed_after_loading_into_memory_does_not_interrupt_smtp(): void
    {
        $invoice = $this->document();
        $path = 'invoices/'.$invoice->id.'/cached.pdf';
        Storage::disk('local')->put($path, '%PDF-1.7 memory contents');
        $this->mock(InvoicePdfService::class)->shouldReceive('contents')->once()
            ->andReturnUsing(fn (): string => Storage::disk('local')->get($path));
        $message = $this->documentMessage($invoice);
        $transport = $this->transport();
        $transport->onSend = function (Email $email) use ($path): void {
            Storage::disk('local')->delete($path);
            $this->assertSame('%PDF-1.7 memory contents', $email->getAttachments()[0]->getBody());
        };

        app(OrderEmailSender::class)->send($message->id);

        $this->assertSame(OrderEmailMessageStatus::Sent, $message->fresh()->status);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_template_file_uses_current_bytes_without_a_message_copy(): void
    {
        [$message, $path] = $this->uploadMessage();
        Storage::disk('local')->put($path, 'Updated source file');
        $transport = $this->transport();

        app(OrderEmailSender::class)->send($message->id);

        $this->assertSame(OrderEmailMessageStatus::Sent, $message->fresh()->status);
        $this->assertSame('Updated source file', $transport->email->getAttachments()[0]->getBody());
        $this->assertSame('terms.txt', $transport->email->getAttachments()[0]->getFilename());
        $this->assertSame([], Storage::disk('local')->allFiles('order-email-messages'));
    }

    public function test_missing_template_file_fails_before_smtp(): void
    {
        [$message, $path] = $this->uploadMessage();
        Storage::disk('local')->delete($path);
        $transport = $this->transport();

        app(OrderEmailSender::class)->send($message->id);

        $this->assertSame('attachment_missing', $message->fresh()->safe_error_code);
        $this->assertSame(0, $transport->count);
    }

    #[DataProvider('invalidSizes')]
    public function test_empty_or_oversized_source_file_is_not_sent(int $size): void
    {
        [$message, $path] = $this->uploadMessage();
        Storage::disk('local')->put($path, str_repeat('x', $size));
        $transport = $this->transport();

        app(OrderEmailSender::class)->send($message->id);

        $this->assertSame('attachment_size_invalid', $message->fresh()->safe_error_code);
        $this->assertSame(0, $transport->count);
    }

    public static function invalidSizes(): array
    {
        return ['empty' => [0], 'too large' => [10 * 1024 * 1024 + 1]];
    }

    public function test_deleting_a_message_does_not_delete_its_shared_template_file_or_document_cache(): void
    {
        [$message, $path] = $this->uploadMessage();
        $message->delete();
        Storage::disk('local')->assertExists($path);
        $invoice = $this->document();
        $cache = 'invoices/'.$invoice->id.'/cached.pdf';
        Storage::disk('local')->put($cache, '%PDF-1.7 shared cache');
        $this->documentMessage($invoice)->delete();
        Storage::disk('local')->assertExists($cache);
    }

    #[DataProvider('referenceTampering')]
    public function test_reference_metadata_tampering_is_rejected_before_rendering(string $field): void
    {
        $invoice = $this->document();
        $message = $this->documentMessage($invoice);
        $attachment = $message->attachments->sole();
        $source = $attachment->source_snapshot;
        $values = match ($field) {
            'identity' => ['source_snapshot' => json_encode([...$source, 'invoice_id' => $invoice->id + 1])],
            'identity type' => ['source_snapshot' => json_encode([...$source, 'invoice_id' => (string) $invoice->id])],
            'mode' => ['source_mode' => null],
            'path' => ['file_path' => 'invoices/other.pdf'],
            'disk' => ['disk' => 'public'],
            'name' => ['file_name' => 'other.pdf'],
            'kind' => ['kind' => 'proforma_pdf'],
        };
        DB::table('order_email_message_attachments')->where('id', $attachment->id)->update($values);
        $this->mock(InvoicePdfService::class)->shouldNotReceive('contents');
        $transport = $this->transport();

        app(OrderEmailSender::class)->send($message->id);

        $this->assertSame('attachment_manifest_mismatch', $message->fresh()->safe_error_code);
        $this->assertSame(0, $transport->count);
    }

    public static function referenceTampering(): array
    {
        return array_combine(
            $fields = ['identity', 'identity type', 'mode', 'path', 'disk', 'name', 'kind'],
            array_map(fn (string $field): array => [$field], $fields),
        );
    }

    public function test_json_key_reordering_does_not_invalidate_a_reference(): void
    {
        $message = $this->documentMessage($invoice = $this->document());
        $attachment = $message->attachments->sole();
        $source = array_reverse($attachment->source_snapshot, true);
        DB::table('order_email_message_attachments')->where('id', $attachment->id)
            ->update(['source_snapshot' => json_encode($source)]);
        $this->mock(InvoicePdfService::class)->shouldReceive('contents')->once()->andReturn('%PDF-1.7 sorted source');
        $transport = $this->transport();

        app(OrderEmailSender::class)->send($message->id);

        $this->assertSame(OrderEmailMessageStatus::Sent, $message->fresh()->status);
        $this->assertSame(1, $transport->count);
    }

    public function test_migration_cannot_be_reverted_while_reference_attachments_exist(): void
    {
        $this->documentMessage($this->document());
        $migration = require database_path('migrations/2026_11_03_010000_add_sources_to_order_email_attachments.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    private function document(InvoiceDocumentType $type = InvoiceDocumentType::Invoice): Invoice
    {
        return Invoice::query()->create([
            'order_id' => $this->createDocumentOrder()->id,
            'invoice_series_id' => $this->createDocumentSeries($type)->id,
            'document_type' => $type,
            'status' => InvoiceDocumentStatus::Issued,
            'number' => match ($type) {
                InvoiceDocumentType::Invoice => 'N 6/2026',
                InvoiceDocumentType::Correction => 'NK 2/2026',
                InvoiceDocumentType::Proforma => 'PF 1/2026',
            },
            'issued_at' => now(),
        ]);
    }

    private function documentMessage(Invoice $invoice): OrderEmailMessage
    {
        $template = $this->template();
        $template->attachments()->create([
            'slot' => 1,
            'type' => EmailTemplateAttachmentType::Document,
            'document_type' => match ($invoice->document_type) {
                InvoiceDocumentType::Invoice => EmailTemplateDocumentType::InvoicePdf,
                InvoiceDocumentType::Correction => EmailTemplateDocumentType::LatestCorrectionPdf,
                InvoiceDocumentType::Proforma => EmailTemplateDocumentType::ProformaPdf,
            },
        ]);

        return $this->prepare($invoice->order, $template);
    }

    private function uploadMessage(): array
    {
        $template = $this->template();
        $path = "email-templates/{$template->id}/attachments/terms.txt";
        Storage::disk('local')->put($path, 'Original source file');
        $template->attachments()->create([
            'slot' => 1, 'type' => EmailTemplateAttachmentType::Upload,
            'file_path' => $path, 'original_name' => 'terms.txt', 'mime_type' => 'text/plain',
            'file_size' => strlen('Original source file'),
        ]);

        return [$this->prepare($this->createDocumentOrder(), $template), $path];
    }

    private function prepare(Order $order, EmailTemplate $template): OrderEmailMessage
    {
        return app(OrderEmailPreparationService::class)->prepareAndQueue($order, $template, new OrderEmailPreparationData(
            toEmail: $order->customer_email, toName: 'Test', source: OrderEmailMessageSource::Manual,
            idempotencyKey: (string) Str::uuid(), actorName: 'Test operator',
        ));
    }

    private function template(): EmailTemplate
    {
        $account = EmailAccount::query()->create([
            'name' => 'Test', 'email' => 'sender@example.test', 'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587, 'smtp_username' => 'sender@example.test', 'smtp_password' => 'FAKE_PASSWORD',
            'encryption' => EmailAccountEncryption::StartTls,
        ]);

        return EmailTemplate::query()->create([
            'email_account_id' => $account->id, 'name' => 'Test', 'subject_template' => 'Test',
            'format' => EmailTemplateFormat::PlainText, 'body_template' => 'Test body', 'is_hidden' => false,
        ]);
    }

    private function transport(): ReferenceOrderEmailTransport
    {
        $transport = new ReferenceOrderEmailTransport;
        $this->app->instance(OrderEmailTransport::class, $transport);

        return $transport;
    }
}

final class ReferenceOrderEmailTransport implements OrderEmailTransport
{
    public int $count = 0;

    public ?Email $email = null;

    public ?Closure $onSend = null;

    public function send(EmailAccount $account, Email $email): void
    {
        $this->count++;
        $this->email = clone $email;
        if ($this->onSend !== null) {
            ($this->onSend)($email);
        }
    }
}
