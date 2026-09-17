<?php

namespace Tests\Feature\Invoices;

use App\Models\OrderStatusSetting;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Models\JpkTaxpayerProfile;
use Modules\Invoices\Services\JpkTaxOfficeCatalog;
use Modules\Invoices\Services\JpkTaxpayerProfileService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Invoices\IsolatesSalesRegisterFiles;
use Tests\TestCase;

class SalesRegisterJpkProfileTest extends TestCase
{
    use Concerns\CreatesInvoiceStage2CDocuments;
    use IsolatesSalesRegisterFiles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        OrderStatusSetting::orderedSettings();
    }

    public function test_profile_is_created_only_by_explicit_save_and_does_not_store_export_parameters(): void
    {
        $this->get(route('invoices.jpk-profile.edit'))->assertOk()->assertSee('Osoba fizyczna / JDG');
        $this->get(route('invoices.sales-register.create'))->assertOk();
        $this->assertDatabaseCount('jpk_taxpayer_profiles', 0);
        $this->post(route('invoices.jpk-profile.save'), $this->person() + ['jpk_year' => '2026', 'jpk_month' => '9',
            'jpk_purpose' => '2', 'jpk_markers' => [1 => 'DI'], 'document_ids' => '[1]', 'gtu_codes' => ['GTU_06']])->assertRedirect();
        $profile = app(JpkTaxpayerProfileService::class)->current();
        $this->assertSame('0202', $profile->office);
        $this->assertSame('person', $profile->type);
        $this->assertSame(1, $profile->lock_version);
        $this->assertNull($profile->name);
        $this->assertSame(['singleton_key', 'type', 'nip', 'name', 'first_name', 'last_name', 'birth_date', 'email', 'phone', 'office', 'lock_version', 'created_at', 'updated_at'], array_keys($profile->getAttributes()));
        $this->assertDatabaseCount('jpk_taxpayer_profiles', 1);
        Http::assertNothingSent();
    }

    public function test_type_change_removes_inactive_personal_data_and_uses_one_record(): void
    {
        $this->savePerson();
        $this->post(route('invoices.jpk-profile.save'), array_replace($this->person(), [
            'expected_lock_version' => 1, 'jpk_type' => 'organization', 'jpk_name' => 'Fikcyjna spółka',
        ]))->assertRedirect();
        $profile = app(JpkTaxpayerProfileService::class)->current();
        $this->assertSame('Fikcyjna spółka', $profile->name);
        foreach (['first_name', 'last_name', 'birth_date'] as $field) {
            $this->assertNull($profile->$field);
        }
        $this->post(route('invoices.jpk-profile.save'), array_replace($this->person(), [
            'expected_lock_version' => 2, 'jpk_name' => 'Unneeded organization name',
        ]))->assertRedirect();
        $this->assertNull(app(JpkTaxpayerProfileService::class)->current()->name);
        $this->assertDatabaseCount('jpk_taxpayer_profiles', 1);
    }

    public function test_stale_creation_and_update_cannot_overwrite_profile(): void
    {
        $this->savePerson();
        $before = app(JpkTaxpayerProfileService::class)->current()->getAttributes();
        $this->post(route('invoices.jpk-profile.save'), array_replace($this->person(), ['jpk_email' => 'other@example.test']))->assertStatus(409);
        $this->assertSame($before, app(JpkTaxpayerProfileService::class)->current()->getAttributes());
        $this->post(route('invoices.jpk-profile.save'), array_replace($this->person(), ['expected_lock_version' => 1]))->assertRedirect();
        $this->post(route('invoices.jpk-profile.save'), array_replace($this->person(), ['expected_lock_version' => 1, 'jpk_email' => 'other@example.test']))->assertStatus(409);
        $this->assertSame('jan@example.test', app(JpkTaxpayerProfileService::class)->current()->email);
    }

    public function test_database_rejects_a_second_singleton_key(): void
    {
        $this->savePerson();
        $row = app(JpkTaxpayerProfileService::class)->current()->getAttributes();
        $this->expectException(QueryException::class);
        DB::table('jpk_taxpayer_profiles')->insert(array_replace($row, ['singleton_key' => 'other']));
    }

    #[DataProvider('invalidProfiles')]
    public function test_invalid_save_is_atomic_and_preserves_entered_values(array $changes): void
    {
        $this->savePerson();
        $before = app(JpkTaxpayerProfileService::class)->current()->getAttributes();
        $payload = array_replace($this->person(), ['expected_lock_version' => 1], $changes);
        $response = $this->post(route('invoices.jpk-profile.save'), $payload)->assertStatus(422);
        foreach ($changes as $field => $value) {
            $this->assertSame(is_string($value) ? $value : '', $response->viewData('values')[$field]);
        }
        $this->assertSame($before, app(JpkTaxpayerProfileService::class)->current()->getAttributes());
    }

    public static function invalidProfiles(): array
    {
        return [
            [['jpk_nip' => ['1234563218']]], [['jpk_nip' => '']], [['jpk_nip' => '1234567890']],
            [['jpk_email' => '']], [['jpk_email' => 'invalid']], [['jpk_office' => '9999']],
            [['jpk_office' => 202]], [['jpk_type' => 'other']], [['jpk_first_name' => '']],
            [['jpk_first_name' => str_repeat('A', 31)]], [['jpk_last_name' => str_repeat('A', 82)]],
            [['jpk_birth_date' => '2026-02-30']], [['jpk_birth_date' => '1899-12-31']],
            [['jpk_phone' => ['123']]], [['jpk_phone' => str_repeat('1', 17)]],
        ];
    }

    public function test_profile_is_initial_hint_but_posted_empty_values_and_export_period_are_not_replaced(): void
    {
        $this->savePerson();
        $response = $this->get(route('invoices.sales-register.create'))->assertOk();
        $this->assertSame('jan@example.test', $response->viewData('values')['jpk_email']);
        $this->assertSame('', $response->viewData('values')['jpk_month']);
        $payload = ['mode' => 'ids', 'document_ids' => '[]', 'format' => 'jpk_v7m3', 'jpk_action' => 'review',
            'jpk_email' => '', 'jpk_nip' => '', 'jpk_first_name' => 'Zmienione', 'jpk_month' => '3', 'jpk_purpose' => '2'];
        $response = $this->post(route('invoices.sales-register.export'), $payload)->assertStatus(422);
        foreach (['jpk_email', 'jpk_nip', 'jpk_first_name', 'jpk_month', 'jpk_purpose'] as $key) {
            $this->assertSame($payload[$key], $response->viewData('values')[$key]);
        }
        $response = $this->post(route('invoices.sales-register.profile'), $payload)->assertOk();
        $this->assertSame('jan@example.test', $response->viewData('values')['jpk_email']);
        $this->assertSame('3', $response->viewData('values')['jpk_month']);
        $this->assertSame('2', $response->viewData('values')['jpk_purpose']);
        $this->assertSame(1, app(JpkTaxpayerProfileService::class)->current()->lock_version);
    }

    public function test_suggestions_require_explicit_series_and_never_guess_person_or_office(): void
    {
        $first = $this->createDocumentSeries(attributes: ['seller_tax_id' => '1234563218', 'seller_email' => 'first@example.test']);
        $second = $this->createDocumentSeries(attributes: ['seller_tax_id' => 'PL1234563218', 'seller_email' => 'second@example.test', 'seller_name' => 'Firma a nie imię']);
        $this->get(route('invoices.jpk-profile.edit'))->assertOk()->assertViewHas('values', fn ($v) => $v['jpk_nip'] === '' && $v['jpk_first_name'] === '');
        $this->post(route('invoices.jpk-profile.suggest'), $this->person())->assertStatus(422);
        $response = $this->post(route('invoices.jpk-profile.suggest'), $this->person() + ['source_series_id' => (string) $second->id])->assertOk();
        $this->assertSame($second->name, $response->viewData('source'));
        $values = $response->viewData('values');
        $this->assertSame('second@example.test', $values['jpk_email']);
        $this->assertSame('1234563218', $values['jpk_nip']);
        $this->assertSame('Jan', $values['jpk_first_name']);
        $this->assertSame('1980-01-01', $values['jpk_birth_date']);
        $this->assertSame('0202', $values['jpk_office']);
        $this->assertSame('', $values['jpk_name']);
        $response = $this->post(route('invoices.jpk-profile.suggest'), array_replace($this->person(), [
            'source_series_id' => (string) $second->id, 'jpk_type' => 'organization',
        ]))->assertOk();
        $this->assertSame('Firma a nie imię', $response->viewData('values')['jpk_name']);
        $this->assertDatabaseCount('jpk_taxpayer_profiles', 0);
        $this->assertSame('first@example.test', $first->fresh()->seller_email);
        Http::assertNothingSent();
    }

    public function test_offices_match_local_dictionary_and_unknown_saved_code_is_not_replaced(): void
    {
        $offices = app(JpkTaxOfficeCatalog::class)->all();
        $this->assertSame('URZĄD SKARBOWY W BOLESŁAWCU', $offices['0202']);
        $this->assertGreaterThan(350, count($offices));
        $this->assertFalse(app(JpkTaxOfficeCatalog::class)->contains('9999'));
        $this->savePerson();
        JpkTaxpayerProfile::query()->whereKey('default')->update(['office' => '9999']);
        $response = $this->get(route('invoices.jpk-profile.edit'))->assertOk()->assertSee('9999 — nieznany kod, wybierz urząd ponownie')
            ->assertSee('data-office-search', false)->assertSee('ArrowDown')->assertSee('Escape');
        $this->assertSame('9999', $response->viewData('values')['jpk_office']);
        Http::assertNothingSent();
    }

    public function test_profile_pages_escape_values_and_have_separate_post_forms(): void
    {
        $this->post(route('invoices.jpk-profile.save'), array_replace($this->person(), ['jpk_first_name' => '<img src=x onerror=alert(1)>']))->assertRedirect();
        $response = $this->get(route('invoices.jpk-profile.edit'))->assertOk()->assertDontSee('<img src=x', false);
        $this->assertSame(1, substr_count($response->getContent(), 'name="expected_lock_version"'));
        $response->assertSee('name="_token"', false)->assertSee('method="POST"', false)->assertHeader('Cache-Control', 'no-store, private');
    }

    private function savePerson(): void
    {
        $this->post(route('invoices.jpk-profile.save'), $this->person())->assertRedirect(route('invoices.jpk-profile.edit'));
    }

    private function person(): array
    {
        return ['expected_lock_version' => 0, 'jpk_type' => 'person', 'jpk_nip' => '1234563218',
            'jpk_first_name' => 'Jan', 'jpk_last_name' => 'Testowy', 'jpk_birth_date' => '1980-01-01',
            'jpk_email' => 'jan@example.test', 'jpk_phone' => '', 'jpk_office' => '0202'];
    }
}
