<?php

namespace Tests\Feature;

use App\Enums\EmailAccountEncryption;
use App\Enums\EmailTemplateAttachmentType;
use App\Enums\EmailTemplateDocumentType;
use App\Enums\EmailTemplateFormat;
use App\Models\EmailAccount;
use App\Models\EmailTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmailTemplateSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_templates_page_exposes_accounts_and_order_variables(): void
    {
        $account = $this->account();

        $this->get(route('orders.email-templates.index'))
            ->assertOk()
            ->assertSee('Nowy szablon e-mail')
            ->assertSee($account->email)
            ->assertSee('[id_zamowienia]')
            ->assertSee('[imie_i_nazwisko]')
            ->assertSee('[numer_faktury]')
            ->assertSee('Załącznik 1')
            ->assertSee('Załącznik 2')
            ->assertSee('Brak załącznika')
            ->assertSee('Wybierz plik z dysku')
            ->assertSee('Załącz fakturę/dokument, plik lub wybrany wydruk')
            ->assertDontSee('Zdjęcie paczki')
            ->assertDontSee('Załącznik 3')
            ->assertSee('data-variable-token="[status_zamowienia]"', false);
    }

    public function test_template_can_be_created_with_known_variables(): void
    {
        $account = $this->account();

        $this->post(route('orders.email-templates.store'), $this->payload($account))
            ->assertRedirect(route('orders.email-templates.index'))
            ->assertSessionHas('success', 'Szablon e-mail został zapisany.');

        $template = EmailTemplate::query()->sole();

        $this->assertSame($account->getKey(), $template->email_account_id);
        $this->assertSame('Potwierdzenie zamówienia', $template->name);
        $this->assertSame('Zamówienie [id_zamowienia]', $template->subject_template);
        $this->assertSame(EmailTemplateFormat::PlainText, $template->format);
        $this->assertSame("Dzień dobry [imie_i_nazwisko].\nStatus: [status_zamowienia]", $template->body_template);
        $this->assertFalse($template->is_hidden);
    }

    public function test_unknown_variables_and_missing_account_are_rejected(): void
    {
        $this->from(route('orders.email-templates.index'))
            ->post(route('orders.email-templates.store'), [
                'email_account_id' => '',
                'name' => 'Niepoprawny szablon',
                'subject_template' => 'Temat [nieznany_temat]',
                'format' => EmailTemplateFormat::PlainText->value,
                'body_template' => 'Treść [nieznana_tresc]',
            ])
            ->assertRedirect(route('orders.email-templates.index'))
            ->assertSessionHasErrors([
                'email_account_id',
                'subject_template',
                'body_template',
            ]);

        $this->assertDatabaseCount('email_templates', 0);
    }

    public function test_template_can_be_updated_and_html_is_not_rendered_on_the_list(): void
    {
        $account = $this->account();
        $template = $this->template($account);
        $html = '<strong>Witaj [imie_i_nazwisko]</strong>';

        $this->patch(route('orders.email-templates.update', $template), $this->payload($account, [
            'email_template_id' => $template->getKey(),
            'name' => 'Faktura do zamówienia',
            'format' => EmailTemplateFormat::Html->value,
            'body_template' => $html,
            'is_hidden' => '1',
        ]))->assertRedirect(route('orders.email-templates.index'));

        $template->refresh();

        $this->assertSame('Faktura do zamówienia', $template->name);
        $this->assertSame(EmailTemplateFormat::Html, $template->format);
        $this->assertSame($html, $template->body_template);
        $this->assertTrue($template->is_hidden);

        $this->get(route('orders.email-templates.index'))
            ->assertOk()
            ->assertSee('Faktura do zamówienia')
            ->assertSee('Ukryty')
            ->assertSee('Witaj [imie_i_nazwisko]')
            ->assertDontSee('<strong>', false);
    }

    public function test_template_can_be_duplicated_and_deleted(): void
    {
        $account = $this->account();
        $template = $this->template($account);

        $this->post(route('orders.email-templates.duplicate', $template))
            ->assertRedirect(route('orders.email-templates.index', ['edit' => 2]));

        $copy = EmailTemplate::query()->whereKeyNot($template->getKey())->sole();

        $this->assertSame('Kopia - Potwierdzenie zamówienia', $copy->name);
        $this->assertSame($template->subject_template, $copy->subject_template);
        $this->assertSame($template->body_template, $copy->body_template);
        $this->assertSame($template->email_account_id, $copy->email_account_id);

        $this->delete(route('orders.email-templates.destroy', $copy))
            ->assertRedirect(route('orders.email-templates.index'));

        $this->assertDatabaseMissing('email_templates', ['id' => $copy->getKey()]);
        $this->assertDatabaseHas('email_templates', ['id' => $template->getKey()]);
    }

    public function test_template_can_store_uploaded_and_document_attachments(): void
    {
        Storage::fake('local');
        $account = $this->account();

        $this->post(route('orders.email-templates.store'), $this->payload($account, [
            'attachment_1_type' => EmailTemplateAttachmentType::Upload->value,
            'attachment_1_file' => UploadedFile::fake()->createWithContent(
                'regulamin.pdf',
                '%PDF-1.7 fake email attachment',
            ),
            'attachment_2_type' => EmailTemplateAttachmentType::Document->value,
            'attachment_2_document_type' => EmailTemplateDocumentType::InvoicePdf->value,
        ]))->assertRedirect(route('orders.email-templates.index'));

        $template = EmailTemplate::query()->with('attachments')->sole();
        $uploaded = $template->attachments->firstWhere('slot', 1);
        $document = $template->attachments->firstWhere('slot', 2);

        $this->assertSame(EmailTemplateAttachmentType::Upload, $uploaded->type);
        $this->assertSame('regulamin.pdf', $uploaded->original_name);
        $this->assertNotNull($uploaded->file_path);
        Storage::disk('local')->assertExists($uploaded->file_path);
        $this->assertSame(EmailTemplateAttachmentType::Document, $document->type);
        $this->assertSame(EmailTemplateDocumentType::InvoicePdf, $document->document_type);
        $this->assertNull($document->file_path);
    }

    public function test_existing_upload_is_preserved_or_removed_without_leaving_private_file(): void
    {
        Storage::fake('local');
        $account = $this->account();

        $this->post(route('orders.email-templates.store'), $this->payload($account, [
            'attachment_1_type' => EmailTemplateAttachmentType::Upload->value,
            'attachment_1_file' => UploadedFile::fake()->createWithContent(
                'instrukcja.pdf',
                '%PDF-1.7 fake instruction',
            ),
        ]))->assertRedirect(route('orders.email-templates.index'));

        $template = EmailTemplate::query()->with('attachments')->sole();
        $path = $template->attachments->sole()->file_path;

        $this->patch(route('orders.email-templates.update', $template), $this->payload($account, [
            'email_template_id' => $template->getKey(),
            'attachment_1_type' => EmailTemplateAttachmentType::Upload->value,
        ]))->assertRedirect(route('orders.email-templates.index'));

        Storage::disk('local')->assertExists($path);
        $this->assertDatabaseHas('email_template_attachments', [
            'email_template_id' => $template->getKey(),
            'slot' => 1,
            'file_path' => $path,
        ]);

        $this->patch(route('orders.email-templates.update', $template), $this->payload($account, [
            'email_template_id' => $template->getKey(),
            'attachment_1_type' => EmailTemplateAttachmentType::None->value,
        ]))->assertRedirect(route('orders.email-templates.index'));

        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseMissing('email_template_attachments', [
            'email_template_id' => $template->getKey(),
            'slot' => 1,
        ]);
    }

    public function test_duplicating_upload_creates_an_independent_private_copy(): void
    {
        Storage::fake('local');
        $account = $this->account();

        $this->post(route('orders.email-templates.store'), $this->payload($account, [
            'attachment_1_type' => EmailTemplateAttachmentType::Upload->value,
            'attachment_1_file' => UploadedFile::fake()->createWithContent(
                'warunki.pdf',
                '%PDF-1.7 fake terms',
            ),
        ]));

        $template = EmailTemplate::query()->with('attachments')->sole();
        $originalPath = $template->attachments->sole()->file_path;

        $this->post(route('orders.email-templates.duplicate', $template))
            ->assertRedirect(route('orders.email-templates.index', ['edit' => 2]));

        $copy = EmailTemplate::query()->with('attachments')->whereKeyNot($template->getKey())->sole();
        $copyPath = $copy->attachments->sole()->file_path;

        $this->assertNotSame($originalPath, $copyPath);
        $this->assertSame(
            Storage::disk('local')->get($originalPath),
            Storage::disk('local')->get($copyPath),
        );

        $this->delete(route('orders.email-templates.destroy', $copy));

        Storage::disk('local')->assertExists($originalPath);
        Storage::disk('local')->assertMissing($copyPath);
    }

    public function test_upload_attachment_requires_a_safe_supported_file(): void
    {
        Storage::fake('local');
        $account = $this->account();

        $this->from(route('orders.email-templates.index'))
            ->post(route('orders.email-templates.store'), $this->payload($account, [
                'attachment_1_type' => EmailTemplateAttachmentType::Upload->value,
                'attachment_1_file' => UploadedFile::fake()->create(
                    'program.exe',
                    1,
                    'application/x-msdownload',
                ),
            ]))
            ->assertRedirect(route('orders.email-templates.index'))
            ->assertSessionHasErrors('attachment_1_file');

        $this->assertDatabaseCount('email_templates', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_document_attachment_requires_a_document_choice(): void
    {
        $account = $this->account();

        $this->from(route('orders.email-templates.index'))
            ->post(route('orders.email-templates.store'), $this->payload($account, [
                'attachment_2_type' => EmailTemplateAttachmentType::Document->value,
                'attachment_2_document_type' => '',
            ]))
            ->assertRedirect(route('orders.email-templates.index'))
            ->assertSessionHasErrors('attachment_2_document_type');

        $this->assertDatabaseCount('email_templates', 0);
    }

    public function test_deleting_account_preserves_template_and_clears_relation(): void
    {
        $account = $this->account();
        $template = $this->template($account);

        $this->delete(route('orders.email-accounts.destroy', $account))
            ->assertRedirect(route('orders.email-accounts.index'));

        $template->refresh();

        $this->assertNull($template->email_account_id);
        $this->assertDatabaseHas('email_templates', ['id' => $template->getKey()]);

        $this->get(route('orders.email-templates.index'))
            ->assertOk()
            ->assertSee('Brak konta');
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
        return EmailTemplate::query()->create($this->payload($account));
    }

    private function payload(EmailAccount $account, array $overrides = []): array
    {
        return array_merge([
            'email_account_id' => $account->getKey(),
            'name' => 'Potwierdzenie zamówienia',
            'subject_template' => 'Zamówienie [id_zamowienia]',
            'format' => EmailTemplateFormat::PlainText->value,
            'body_template' => "Dzień dobry [imie_i_nazwisko].\nStatus: [status_zamowienia]",
            'is_hidden' => false,
        ], $overrides);
    }
}
