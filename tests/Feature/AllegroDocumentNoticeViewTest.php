<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Modules\Integrations\Allegro\Models\AllegroInvoiceExport;
use Modules\Invoices\Models\Invoice;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AllegroDocumentNoticeViewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    #[DataProvider('documentTypes')]
    public function test_confirmed_invoice_and_correction_hide_the_success_notice(string $type): void
    {
        $export = $this->export($type, 'synced');
        $before = $export->getAttributes();
        $html = $this->render($type, $export);
        $this->assertSame('', trim($html));
        $this->assertSame($before, $export->getAttributes());
        Http::assertNothingSent();
    }

    public static function documentTypes(): array
    {
        return [['invoice'], ['correction']];
    }

    #[DataProvider('pendingStates')]
    public function test_unconfirmed_correction_keeps_its_status_visible(string $state): void
    {
        $export = $this->export('correction', $state);
        $html = $this->render('correction', $export);
        $this->assertStringContainsString(e($export->label()), $html);
        $this->assertStringContainsString('data-allegro-document-id="23"', $html);
        Http::assertNothingSent();
    }

    public static function pendingStates(): array
    {
        return [['waiting'], ['pending'], ['uploading'], ['verifying'], ['failed'], ['uncertain']];
    }

    #[DataProvider('warnings')]
    public function test_confirmed_correction_keeps_important_warnings_without_the_green_badge(array $overrides): void
    {
        $export = $this->export('correction', 'synced');
        $html = $this->render('correction', $export, $overrides);
        $this->assertStringContainsString('text-warning-emphasis', $html);
        $this->assertStringNotContainsString($export->label(), $html);
        $this->assertStringNotContainsString('bi-check-circle', $html);
        Http::assertNothingSent();
    }

    public static function warnings(): array
    {
        return [[['changed' => true]], [['sourceWarning' => true]]];
    }

    public function test_confirmed_correction_keeps_export_and_validation_errors_visible(): void
    {
        $export = $this->export('correction', 'synced');
        $export->error = 'Export warning';
        $errors = new ViewErrorBag;
        $errors->put('default', new MessageBag(['allegro_invoice_export' => 'Validation warning']));
        $html = $this->render('correction', $export, errors: $errors);
        $this->assertStringContainsString('Export warning', $html);
        $this->assertStringContainsString('Validation warning', $html);
        $this->assertStringNotContainsString($export->label(), $html);
        $this->assertStringNotContainsString('bi-check-circle', $html);
        Http::assertNothingSent();
    }

    public function test_unsent_correction_keeps_the_manual_send_action(): void
    {
        $html = $this->render('correction', null, ['canSend' => true]);
        $this->assertStringContainsString(route('orders.allegro.invoices.store', ['order' => 1, 'invoice' => 23]), $html);
        $this->assertStringContainsString('name="confirmed" value="1"', $html);
        $this->assertStringContainsString('name="expected_lock_version" value="2"', $html);
        Http::assertNothingSent();
    }

    private function export(string $type, string $state): AllegroInvoiceExport
    {
        return new AllegroInvoiceExport(['document_type' => $type, 'state' => $state]);
    }

    private function render(string $type, ?AllegroInvoiceExport $export, array $overrides = [], ?ViewErrorBag $errors = null): string
    {
        return view('orders.partials.allegro-invoice-export', [
            'order' => (new Order)->forceFill(['id' => 1]),
            'allegroDocument' => (new Invoice)->forceFill(['id' => 23, 'document_type' => $type, 'lock_version' => 2]),
            'allegroState' => array_replace([
                'export' => $export, 'historical' => null, 'changed' => false,
                'sourceWarning' => false, 'canSend' => false, 'canRetry' => false,
            ], $overrides),
            'errors' => $errors ?? new ViewErrorBag,
        ])->render();
    }
}
