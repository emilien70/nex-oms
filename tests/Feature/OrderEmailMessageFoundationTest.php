<?php

namespace Tests\Feature;

use App\Enums\EmailAccountEncryption;
use App\Enums\EmailTemplateFormat;
use App\Enums\OrderEmailAttachmentKind;
use App\Enums\OrderEmailMessageSource;
use App\Enums\OrderEmailMessageStatus;
use App\Models\EmailAccount;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class OrderEmailMessageFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_outbound_message_stores_casted_snapshots_and_private_attachment_metadata(): void
    {
        $order = $this->order();
        $account = $this->account();
        $template = $this->template($account);
        $user = User::factory()->create(['name' => 'Operator testowy']);
        $queuedAt = now()->startOfSecond();

        $message = $order->emailMessages()->create($this->messagePayload([
            'email_template_id' => $template->getKey(),
            'email_account_id' => $account->getKey(),
            'created_by_user_id' => $user->getKey(),
            'actor_snapshot' => [
                'type' => 'user',
                'id' => $user->getKey(),
                'name' => $user->name,
            ],
            'queued_at' => $queuedAt,
        ]));

        $attachment = $message->attachments()->create([
            'position' => 1,
            'kind' => OrderEmailAttachmentKind::InvoicePdf,
            'disk' => 'local',
            'file_path' => 'order-email-messages/1/faktura.pdf',
            'file_name' => 'Faktura_BL_1_2026.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 12345,
            'checksum_sha256' => hash('sha256', 'FAKE_PDF_CONTENT'),
            'source_snapshot' => [
                'document_type' => 'invoice',
                'document_number' => 'BL 1/2026',
            ],
        ]);

        $message->refresh();

        $this->assertTrue($message->order->is($order));
        $this->assertTrue($message->emailTemplate->is($template));
        $this->assertTrue($message->emailAccount->is($account));
        $this->assertTrue($message->createdByUser->is($user));
        $this->assertSame(OrderEmailMessageSource::Manual, $message->source);
        $this->assertSame(OrderEmailMessageStatus::Queued, $message->status);
        $this->assertSame(EmailTemplateFormat::Html, $message->format);
        $this->assertSame('Operator testowy', $message->actor_snapshot['name']);
        $this->assertTrue($message->queued_at->equalTo($queuedAt));
        $this->assertFalse($message->status->isTerminal());
        $this->assertSame(OrderEmailAttachmentKind::InvoicePdf, $attachment->kind);
        $this->assertSame('local', $attachment->disk);
        $this->assertSame(12345, $attachment->file_size);
        $this->assertSame('BL 1/2026', $attachment->source_snapshot['document_number']);
        $this->assertSame($attachment->getKey(), $message->attachments->sole()->getKey());
        $this->assertFalse(Schema::hasColumn('order_email_messages', 'smtp_password'));
        $this->assertFalse(Schema::hasColumn('order_email_messages', 'smtp_username'));
        $this->assertFalse(Schema::hasColumn('order_email_messages', 'smtp_host'));
    }

    public function test_source_records_can_be_deleted_without_changing_message_snapshot(): void
    {
        $order = $this->order();
        $account = $this->account();
        $template = $this->template($account);
        $user = User::factory()->create(['name' => 'Usunięty operator']);
        $message = $order->emailMessages()->create($this->messagePayload([
            'email_template_id' => $template->getKey(),
            'email_account_id' => $account->getKey(),
            'created_by_user_id' => $user->getKey(),
            'actor_snapshot' => ['name' => 'Usunięty operator'],
        ]));

        $template->delete();
        $account->delete();
        $user->delete();
        $message->refresh();

        $this->assertNull($message->email_template_id);
        $this->assertNull($message->email_account_id);
        $this->assertNull($message->created_by_user_id);
        $this->assertSame('Potwierdzenie zamówienia', $message->template_name_snapshot);
        $this->assertSame('Sklep testowy', $message->account_name_snapshot);
        $this->assertSame('Usunięty operator', $message->actor_snapshot['name']);
        $this->assertSame('sprzedaz@example.test', $message->from_email);
        $this->assertSame('klient@example.test', $message->to_email);
        $this->assertSame('Zamówienie TEST-1', $message->subject);
        $this->assertSame('<p>Treść historyczna.</p>', $message->body_html);
    }

    public function test_idempotency_key_prevents_duplicate_message_records(): void
    {
        $order = $this->order();
        $idempotencyKey = (string) Str::uuid();

        $order->emailMessages()->create($this->messagePayload([
            'idempotency_key' => $idempotencyKey,
        ]));

        $this->expectException(QueryException::class);

        $order->emailMessages()->create($this->messagePayload([
            'idempotency_key' => $idempotencyKey,
        ]));
    }

    public function test_deleting_original_message_removes_attachments_and_detaches_retry(): void
    {
        $order = $this->order();
        $original = $order->emailMessages()->create($this->messagePayload());
        $retry = $order->emailMessages()->create($this->messagePayload([
            'retry_of_message_id' => $original->getKey(),
        ]));

        $attachment = $original->attachments()->create([
            'position' => 1,
            'kind' => OrderEmailAttachmentKind::Upload,
            'disk' => 'local',
            'file_path' => 'order-email-messages/1/regulamin.pdf',
            'file_name' => 'regulamin.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 512,
            'checksum_sha256' => hash('sha256', 'FAKE_ATTACHMENT'),
        ]);

        $original->delete();
        $retry->refresh();

        $this->assertDatabaseMissing('order_email_message_attachments', ['id' => $attachment->getKey()]);
        $this->assertNull($retry->retry_of_message_id);
    }

    public function test_force_deleting_order_removes_its_message_history(): void
    {
        $order = $this->order();
        $message = $order->emailMessages()->create($this->messagePayload());
        $attachment = $message->attachments()->create([
            'position' => 1,
            'kind' => OrderEmailAttachmentKind::Upload,
            'disk' => 'local',
            'file_path' => 'order-email-messages/1/warunki.pdf',
            'file_name' => 'warunki.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 768,
            'checksum_sha256' => hash('sha256', 'FAKE_TERMS'),
        ]);

        $order->forceDelete();

        $this->assertDatabaseMissing('order_email_messages', ['id' => $message->getKey()]);
        $this->assertDatabaseMissing('order_email_message_attachments', ['id' => $attachment->getKey()]);
    }

    public function test_message_statuses_expose_stable_labels_and_terminal_semantics(): void
    {
        $this->assertSame('Ręcznie', OrderEmailMessageSource::Manual->label());
        $this->assertSame('Automatyczna akcja', OrderEmailMessageSource::Automation->label());
        $this->assertSame('W kolejce', OrderEmailMessageStatus::Queued->label());
        $this->assertSame('Wysyłanie', OrderEmailMessageStatus::Sending->label());
        $this->assertSame('Wysłana', OrderEmailMessageStatus::Sent->label());
        $this->assertSame('Błąd wysyłki', OrderEmailMessageStatus::Failed->label());
        $this->assertSame('Niepewny wynik wysyłki', OrderEmailMessageStatus::Unknown->label());
        $this->assertFalse(OrderEmailMessageStatus::Sending->isTerminal());
        $this->assertTrue(OrderEmailMessageStatus::Sent->isTerminal());
        $this->assertTrue(OrderEmailMessageStatus::Failed->isTerminal());
        $this->assertTrue(OrderEmailMessageStatus::Unknown->isTerminal());
    }

    public function test_message_snapshot_and_attachment_metadata_cannot_be_changed_after_creation(): void
    {
        $order = $this->order();
        $message = $order->emailMessages()->create($this->messagePayload());
        $attachment = $message->attachments()->create([
            'position' => 1,
            'kind' => OrderEmailAttachmentKind::Upload,
            'disk' => 'local',
            'file_path' => 'order-email-messages/1/regulamin.pdf',
            'file_name' => 'regulamin.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 512,
            'checksum_sha256' => hash('sha256', 'FAKE_ATTACHMENT'),
        ]);

        $message->update([
            'status' => OrderEmailMessageStatus::Sent,
            'attempt_count' => 1,
            'sent_at' => now(),
        ]);

        $this->assertSame(OrderEmailMessageStatus::Sent, $message->status);
        $this->assertSame(1, $message->attempt_count);

        try {
            $message->update(['subject' => 'Podmieniony temat']);
            $this->fail('Message snapshot update should have been rejected.');
        } catch (LogicException) {
            $this->assertSame('Zamówienie TEST-1', $message->fresh()->subject);
        }

        $this->expectException(LogicException::class);

        $attachment->update(['file_name' => 'podmieniony.pdf']);
    }

    private function order(): Order
    {
        return Order::query()->create([
            'source' => 'manual',
            'external_id' => 'TEST-1',
            'status' => Order::STATUS_NEW,
            'currency' => 'PLN',
            'customer_email' => 'klient@example.test',
        ]);
    }

    private function account(): EmailAccount
    {
        return EmailAccount::query()->create([
            'name' => 'Sklep testowy',
            'email' => 'sprzedaz@example.test',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_username' => 'sprzedaz@example.test',
            'smtp_password' => 'FAKE_SMTP_PASSWORD',
            'encryption' => EmailAccountEncryption::StartTls,
        ]);
    }

    private function template(EmailAccount $account): EmailTemplate
    {
        return EmailTemplate::query()->create([
            'email_account_id' => $account->getKey(),
            'name' => 'Potwierdzenie zamówienia',
            'subject_template' => 'Zamówienie [id_zamowienia]',
            'format' => EmailTemplateFormat::Html,
            'body_template' => '<p>Treść [id_zamowienia].</p>',
            'is_hidden' => false,
        ]);
    }

    private function messagePayload(array $overrides = []): array
    {
        return array_merge([
            'source' => OrderEmailMessageSource::Manual,
            'status' => OrderEmailMessageStatus::Queued,
            'idempotency_key' => (string) Str::uuid(),
            'actor_snapshot' => ['type' => 'user', 'name' => 'Operator testowy'],
            'template_name_snapshot' => 'Potwierdzenie zamówienia',
            'account_name_snapshot' => 'Sklep testowy',
            'from_email' => 'sprzedaz@example.test',
            'from_name' => 'Sklep testowy',
            'reply_to_email' => 'sprzedaz@example.test',
            'to_email' => 'klient@example.test',
            'to_name' => 'Klient testowy',
            'subject' => 'Zamówienie TEST-1',
            'format' => EmailTemplateFormat::Html,
            'body_text' => 'Treść historyczna.',
            'body_html' => '<p>Treść historyczna.</p>',
            'attempt_count' => 0,
        ], $overrides);
    }
}
