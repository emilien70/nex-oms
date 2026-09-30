<?php

namespace Tests\Feature;

use App\Enums\EmailAccountEncryption;
use App\Enums\EmailTemplateAttachmentType;
use App\Enums\EmailTemplateFormat;
use App\Jobs\SendOrderEmailJob;
use App\Models\EmailAccount;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\OrderEmailMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderEmailManualUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('local');
    }

    public function test_order_view_shows_optional_template_required_default_account_and_future_sms_option(): void
    {
        $order = $this->order();
        $secondAccount = $this->account([
            'name' => 'Drugie konto',
            'email' => 'z@example.test',
        ]);
        $defaultAccount = $this->account([
            'name' => 'Domyślne konto',
            'email' => 'a@example.test',
        ]);
        $visibleTemplate = $this->template($secondAccount, ['name' => 'Widoczny szablon']);
        $this->template($secondAccount, ['name' => 'Ukryty szablon', 'is_hidden' => true]);

        $response = $this->get(route('orders.show', $order));

        $response
            ->assertOk()
            ->assertSee('Wymiana wiadomości')
            ->assertSee('Informacje dodatkowe')
            ->assertSee('Nie wybrano')
            ->assertSee('Widoczny szablon')
            ->assertDontSee('Ukryty szablon')
            ->assertSee('Typ wiadomości:')
            ->assertSee('E-mail')
            ->assertSee('SMS')
            ->assertSee('name="email_account_id"', false)
            ->assertSee('value="'.$defaultAccount->getKey().'" selected', false)
            ->assertSee(route('orders.email-templates.preview', [$order, $visibleTemplate]), false)
            ->assertDontSee('Wczytanie szablonu zastąpi wpisany temat i treść. Kontynuować?');
    }

    public function test_template_preview_renders_order_variables_and_sanitizes_html(): void
    {
        $order = $this->order(['shipping_name' => 'Anna [literal] Nowak']);
        $account = $this->account();
        $template = $this->template($account, [
            'subject_template' => 'Zamówienie [id_zamowienia]',
            'body_template' => '<p>Witaj [imie_i_nazwisko]</p><script>alert(1)</script>',
        ]);

        $this->getJson(route('orders.email-templates.preview', [$order, $template]))
            ->assertOk()
            ->assertJsonPath('order_id', $order->getKey())
            ->assertJsonPath('email_account_id', $account->getKey())
            ->assertJsonPath('subject', 'Zamówienie '.$order->getKey())
            ->assertJsonPath('content_rendered', true)
            ->assertJsonPath('body', fn (string $body): bool => str_contains($body, 'Anna [literal] Nowak')
                && ! str_contains($body, '[imie_i_nazwisko]')
                && ! str_contains($body, '<script'));
    }

    public function test_plain_text_preview_preserves_lines_and_visible_content_is_not_rendered_twice(): void
    {
        $order = $this->order(['shipping_name' => 'Anna [literal] Nowak']);
        $account = $this->account();
        $template = $this->template($account, [
            'subject_template' => 'Zamówienie [id_zamowienia]',
            'format' => EmailTemplateFormat::PlainText,
            'body_template' => "Witaj [imie_i_nazwisko],\r\nDziękujemy za zamówienie.\r\n\r\nPozdrawiamy",
        ]);

        $preview = $this->getJson(route('orders.email-templates.preview', [$order, $template]))
            ->assertOk()
            ->assertJsonPath('format', EmailTemplateFormat::PlainText->value)
            ->assertJsonPath('content_rendered', true)
            ->assertJsonPath('body', "Witaj Anna [literal] Nowak,\nDziękujemy za zamówienie.\n\nPozdrawiamy")
            ->json();

        $this->postJson(route('orders.email-messages.store', $order), [
            'message_type' => 'email',
            'email_template_id' => $template->getKey(),
            'email_account_id' => $account->getKey(),
            'subject' => $preview['subject'],
            'format' => $preview['format'],
            'body' => $preview['body'],
            'content_rendered' => true,
            'sending_option' => 'page_and_email',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();

        $message = OrderEmailMessage::query()->sole();
        $this->assertSame('Zamówienie '.$order->getKey(), $message->subject);
        $this->assertSame($preview['body'], $message->body_text);
        $this->assertNull($message->body_html);
    }

    public function test_template_preview_returns_safe_error_when_rendered_body_is_empty(): void
    {
        $order = $this->order(['shipping_company_name' => null]);
        $account = $this->account();
        $template = $this->template($account, [
            'format' => EmailTemplateFormat::PlainText,
            'body_template' => '[firma]',
        ]);

        $this->getJson(route('orders.email-templates.preview', [$order, $template]))
            ->assertUnprocessable()
            ->assertJsonPath('safe_code', 'body_empty')
            ->assertJsonPath('message', 'Treść wiadomości nie może być pusta.');
    }

    public function test_manual_message_without_template_uses_selected_account_and_queues_snapshot(): void
    {
        $order = $this->order();
        $account = $this->account();
        $key = (string) Str::uuid();

        $response = $this->postJson(route('orders.email-messages.store', $order), [
            'message_type' => 'email',
            'email_template_id' => null,
            'email_account_id' => $account->getKey(),
            'subject' => 'Wiadomość do zamówienia [id_zamowienia]',
            'format' => EmailTemplateFormat::Html->value,
            'body' => '<p><font color="#ff0000">Witaj [imie_i_nazwisko_nabywcy]</font></p><script>alert(1)</script>',
            'sending_option' => 'page_and_email',
            'idempotency_key' => $key,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('polling_finished', false)
            ->assertJsonPath('html', fn (string $html): bool => str_contains($html, 'W kolejce')
                && str_contains($html, 'Operator NEX-OMS'));

        $message = OrderEmailMessage::query()->sole();
        $this->assertNull($message->email_template_id);
        $this->assertSame($account->getKey(), $message->email_account_id);
        $this->assertSame('klient@example.test', $message->to_email);
        $this->assertSame('Wiadomość do zamówienia '.$order->getKey(), $message->subject);
        $this->assertStringContainsString('Jan Kowalski', (string) $message->body_html);
        $this->assertStringContainsString('<font color="#ff0000">', (string) $message->body_html);
        $this->assertStringNotContainsString('<script', (string) $message->body_html);
        $this->assertSame('Operator NEX-OMS', data_get($message->actor_snapshot, 'name'));
        $this->assertSame(0, $message->attachment_count);
        Queue::assertPushed(SendOrderEmailJob::class, 1);
    }

    public function test_template_attachment_is_frozen_and_download_is_scoped_to_order(): void
    {
        $order = $this->order();
        $otherOrder = $this->order(['customer_email' => 'inny@example.test']);
        $account = $this->account();
        $template = $this->template($account);
        $sourcePath = "email-templates/{$template->getKey()}/attachments/instrukcja.pdf";
        Storage::disk('local')->put($sourcePath, '%PDF-FAKE-CONTENT');
        $template->attachments()->create([
            'slot' => 1,
            'type' => EmailTemplateAttachmentType::Upload,
            'file_path' => $sourcePath,
            'original_name' => 'instrukcja.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => strlen('%PDF-FAKE-CONTENT'),
        ]);

        $this->postJson(route('orders.email-messages.store', $order), [
            'message_type' => 'email',
            'email_template_id' => $template->getKey(),
            'email_account_id' => $account->getKey(),
            'subject' => $template->subject_template,
            'format' => $template->format->value,
            'body' => $template->body_template,
            'sending_option' => 'page_and_email',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();

        $message = OrderEmailMessage::query()->with('attachments')->sole();
        $attachment = $message->attachments->sole();

        $this->get(route('orders.email-messages.attachments.show', [$order, $message, $attachment]))
            ->assertOk()
            ->assertDownload('instrukcja.pdf');
        $this->get(route('orders.email-messages.attachments.show', [$otherOrder, $message, $attachment]))
            ->assertNotFound();
    }

    public function test_manual_send_requires_account_and_valid_recipient(): void
    {
        $order = $this->order(['customer_email' => null]);

        $this->postJson(route('orders.email-messages.store', $order), [
            'message_type' => 'email',
            'subject' => 'Temat',
            'format' => EmailTemplateFormat::Html->value,
            'body' => '<p>Treść</p>',
            'sending_option' => 'page_and_email',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('email_account_id');

        $account = $this->account();

        $this->postJson(route('orders.email-messages.store', $order), [
            'message_type' => 'email',
            'email_account_id' => $account->getKey(),
            'subject' => 'Temat',
            'format' => EmailTemplateFormat::Html->value,
            'body' => '<p>Treść</p>',
            'sending_option' => 'page_and_email',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonPath('safe_code', 'recipient_invalid');

        $this->assertDatabaseCount('order_email_messages', 0);
        Queue::assertNothingPushed();
    }

    public function test_same_manual_idempotency_key_cannot_select_another_smtp_account(): void
    {
        $order = $this->order();
        $firstAccount = $this->account();
        $secondAccount = $this->account([
            'name' => 'Drugie konto',
            'email' => 'drugie@example.test',
        ]);
        $key = (string) Str::uuid();
        $payload = [
            'message_type' => 'email',
            'email_account_id' => $firstAccount->getKey(),
            'subject' => 'Temat',
            'format' => EmailTemplateFormat::Html->value,
            'body' => '<p>Treść</p>',
            'sending_option' => 'page_and_email',
            'idempotency_key' => $key,
        ];

        $this->postJson(route('orders.email-messages.store', $order), $payload)->assertCreated();

        $this->postJson(route('orders.email-messages.store', $order), [
            ...$payload,
            'email_account_id' => $secondAccount->getKey(),
        ])->assertUnprocessable()->assertJsonPath('safe_code', 'idempotency_conflict');

        $this->assertDatabaseCount('order_email_messages', 1);
        Queue::assertPushed(SendOrderEmailJob::class, 1);
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'source' => 'manual',
            'status' => Order::STATUS_NEW,
            'currency' => 'PLN',
            'total_gross' => '0.00',
            'payment_status' => 'unpaid',
            'customer_email' => 'klient@example.test',
            'billing_name' => 'Jan Kowalski',
        ], $overrides));
    }

    private function account(array $overrides = []): EmailAccount
    {
        return EmailAccount::query()->create(array_merge([
            'name' => 'Sklep testowy',
            'email' => 'sprzedaz@example.test',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_username' => 'sprzedaz@example.test',
            'smtp_password' => 'FAKE_SMTP_PASSWORD',
            'encryption' => EmailAccountEncryption::StartTls,
        ], $overrides));
    }

    private function template(EmailAccount $account, array $overrides = []): EmailTemplate
    {
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
