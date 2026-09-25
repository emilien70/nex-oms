<?php

namespace Tests\Feature;

use App\Enums\EmailAccountEncryption;
use App\Enums\EmailAccountTestStatus;
use App\Exceptions\SmtpConnectionException;
use App\Models\EmailAccount;
use App\Services\SmtpConnectionTester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use Tests\TestCase;

class EmailSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_templates_page_is_available_from_orders_menu(): void
    {
        $this->get(route('orders.email-templates.index'))
            ->assertOk()
            ->assertSee('Szablony E-Mail')
            ->assertSee('Konta E-Mail')
            ->assertSee(route('orders.email-templates.index'), false)
            ->assertSee(route('orders.email-accounts.index'), false)
            ->assertSee('aria-current="page"', false);
    }

    public function test_email_accounts_tab_has_its_own_empty_page(): void
    {
        $this->get(route('orders.email-accounts.index'))
            ->assertOk()
            ->assertSee('Szablony E-Mail')
            ->assertSee('Konta E-Mail')
            ->assertSee('Dodaj nowe konto')
            ->assertSee('Nie dodano jeszcze żadnego konta SMTP.')
            ->assertSee('email-settings-tab active', false)
            ->assertSee('aria-current="page"', false);
    }

    public function test_smtp_account_is_validated_encrypted_and_never_rendered(): void
    {
        $password = 'FAKE_SMTP_PASSWORD_MUST_NOT_LEAK';

        $this->post(route('orders.email-accounts.store'), $this->payload([
            'smtp_password' => $password,
        ]))->assertRedirect(route('orders.email-accounts.index'));

        $account = EmailAccount::query()->sole();

        $this->assertTrue(Schema::hasTable('email_accounts'));
        $this->assertSame($password, $account->smtp_password);
        $this->assertNotSame($password, DB::table('email_accounts')->value('smtp_password'));
        $this->assertArrayNotHasKey('smtp_password', $account->toArray());

        $this->get(route('orders.email-accounts.index'))
            ->assertOk()
            ->assertSee('sprzedaz@example.test')
            ->assertSee('Sklep testowy')
            ->assertDontSee($password);

        $this->post(route('orders.email-accounts.store'), $this->payload([
            'smtp_host' => 'https://smtp.example.test:587',
            'smtp_password' => 'ANOTHER_FAKE_SECRET',
        ]))
            ->assertSessionHasErrors('smtp_host');

        $this->assertNull(session()->getOldInput('smtp_password'));
        $this->assertArrayNotHasKey('smtp_password', session()->getOldInput());
    }

    public function test_blank_password_preserves_existing_secret_and_connection_change_clears_test_result(): void
    {
        $account = $this->account([
            'smtp_password' => 'FAKE_EXISTING_SMTP_PASSWORD',
            'last_tested_at' => now(),
            'last_test_status' => EmailAccountTestStatus::Success,
            'last_test_message' => 'Połączenie działa.',
        ]);

        $this->patch(route('orders.email-accounts.update', $account), $this->payload([
            'email_account_id' => $account->getKey(),
            'smtp_host' => 'smtp2.example.test',
            'smtp_password' => '',
        ]))->assertRedirect(route('orders.email-accounts.index'));

        $account->refresh();

        $this->assertSame('FAKE_EXISTING_SMTP_PASSWORD', $account->smtp_password);
        $this->assertSame('smtp2.example.test', $account->smtp_host);
        $this->assertNull($account->last_tested_at);
        $this->assertNull($account->last_test_status);
        $this->assertNull($account->last_test_message);
    }

    public function test_copy_requires_a_new_password_and_account_can_be_deleted(): void
    {
        $account = $this->account(['smtp_password' => 'FAKE_ORIGINAL_SMTP_PASSWORD']);

        $this->post(route('orders.email-accounts.duplicate', $account))
            ->assertRedirect(route('orders.email-accounts.index', ['edit' => 2]));

        $copy = EmailAccount::query()->whereKeyNot($account->getKey())->sole();

        $this->assertSame('Kopia - Sklep testowy', $copy->name);
        $this->assertNull($copy->smtp_password);
        $this->assertFalse($copy->hasConfiguredPassword());

        $this->delete(route('orders.email-accounts.destroy', $copy))
            ->assertRedirect(route('orders.email-accounts.index'));

        $this->assertDatabaseMissing('email_accounts', ['id' => $copy->getKey()]);
        $this->assertDatabaseHas('email_accounts', ['id' => $account->getKey()]);
    }

    public function test_connection_test_records_success_without_sending_a_message(): void
    {
        $account = $this->account();

        $this->mock(SmtpConnectionTester::class, function (MockInterface $mock) use ($account): void {
            $mock->shouldReceive('test')
                ->once()
                ->withArgs(fn (EmailAccount $tested): bool => $tested->is($account));
        });

        $this->post(route('orders.email-accounts.test', $account))
            ->assertRedirect(route('orders.email-accounts.index'))
            ->assertSessionHas('success', 'Połączenie i uwierzytelnienie SMTP działają poprawnie.');

        $account->refresh();

        $this->assertSame(EmailAccountTestStatus::Success, $account->last_test_status);
        $this->assertNotNull($account->last_tested_at);
        $this->assertSame(
            'Połączenie i uwierzytelnienie SMTP działają poprawnie.',
            $account->last_test_message,
        );
    }

    public function test_connection_failure_persists_only_a_safe_message(): void
    {
        $secret = 'FAKE_SMTP_PASSWORD_MUST_NOT_REACH_DIAGNOSTICS';
        $account = $this->account(['smtp_password' => $secret]);
        $safeMessage = 'Nie udało się połączyć lub uwierzytelnić na serwerze SMTP. Sprawdź zapisane ustawienia konta.';

        $this->mock(SmtpConnectionTester::class, function (MockInterface $mock) use ($safeMessage): void {
            $mock->shouldReceive('test')
                ->once()
                ->andThrow(new SmtpConnectionException($safeMessage));
        });

        $this->post(route('orders.email-accounts.test', $account))
            ->assertRedirect(route('orders.email-accounts.index'))
            ->assertSessionHas('error', $safeMessage);

        $account->refresh();

        $this->assertSame(EmailAccountTestStatus::Error, $account->last_test_status);
        $this->assertSame($safeMessage, $account->last_test_message);
        $this->assertStringNotContainsString($secret, $account->last_test_message);

        $this->get(route('orders.email-accounts.index'))
            ->assertOk()
            ->assertSee('Błąd połączenia')
            ->assertDontSee($secret);
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

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'email_account_id' => null,
            'name' => 'Sklep testowy',
            'email' => 'sprzedaz@example.test',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_username' => 'sprzedaz@example.test',
            'smtp_password' => 'FAKE_SMTP_PASSWORD',
            'encryption' => EmailAccountEncryption::StartTls->value,
        ], $overrides);
    }
}
