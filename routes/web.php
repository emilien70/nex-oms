<?php

use App\Http\Controllers\EmailAccountController;
use App\Http\Controllers\EmailTemplateController;
use App\Http\Controllers\GusCompanyController;
use App\Http\Controllers\Integrations\AllegroShippingParcelTemplateController;
use App\Http\Controllers\Integrations\CourierIntegrationController;
use App\Http\Controllers\Integrations\DpdParcelTemplateController;
use App\Http\Controllers\Integrations\InPostCourierParcelTemplateController;
use App\Http\Controllers\Integrations\IntegrationController;
use App\Http\Controllers\OrderCatalogProductController;
use App\Http\Controllers\OrderEmailMessageController;
use App\Http\Controllers\OrderMetaController;
use App\Http\Controllers\OrderProductController;
use App\Http\Controllers\OrderReturnController;
use App\Http\Controllers\OrderReturnRefundController;
use App\Http\Controllers\OrderSavedViewController;
use App\Http\Controllers\OrderScanController;
use App\Http\Controllers\OrdersController;
use App\Http\Controllers\OrderSectionController;
use App\Http\Controllers\OrderStatusController;
use App\Http\Controllers\ReturnStatusSettingsController;
use App\Http\Controllers\SettingsOrderStatusesController;
use App\Http\Controllers\SettingsVariablesController;
use App\Http\Controllers\ShipmentController;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Analytics\Http\Controllers\BasicStatisticsController;
use Modules\Analytics\Http\Controllers\BasicStatisticsCsvController;
use Modules\Automation\Http\Controllers\AutomationActivityController;
use Modules\Automation\Http\Controllers\AutomationRuleController;
use Modules\CustomerPortal\Http\Controllers\PortalController;
use Modules\CustomerPortal\Http\Controllers\PortalPaymentController;
use Modules\CustomerPortal\Http\Controllers\PortalSettingsController;
use Modules\CustomerPortal\Services\PortalUrlService;
use Modules\Integrations\Allegro\Http\Controllers\AllegroAutomaticOrdersController;
use Modules\Integrations\Allegro\Http\Controllers\AllegroCommissionRefundController;
use Modules\Integrations\Allegro\Http\Controllers\AllegroInvoiceExportController;
use Modules\Integrations\Allegro\Http\Controllers\AllegroInvoiceSettingsController;
use Modules\Integrations\Allegro\Http\Controllers\AllegroOrderImportController;
use Modules\Integrations\Allegro\Http\Controllers\AllegroOrderImportSettingsController;
use Modules\Integrations\Allegro\Http\Controllers\AllegroOrderInformationController;
use Modules\Integrations\Allegro\Http\Controllers\AllegroProductLinkController;
use Modules\Integrations\Allegro\Http\Controllers\AllegroRefundCommandController;
use Modules\Integrations\Allegro\Http\Controllers\AllegroRefundSettingsController;
use Modules\Integrations\Allegro\Http\Controllers\AllegroReturnSettingsController;
use Modules\Integrations\Allegro\Http\Controllers\AllegroSettingsController;
use Modules\Integrations\Allegro\Http\Controllers\AllegroShipmentExportController;
use Modules\Integrations\Allegro\Http\Controllers\AllegroShipmentSettingsController;
use Modules\Integrations\Allegro\Http\Controllers\AllegroStatusMappingController;
use Modules\Integrations\Allegro\Http\Controllers\AllegroStatusSyncController;
use Modules\Integrations\NexPrinter\Http\Controllers\NexPrinterClientController;
use Modules\Integrations\NexPrinter\Http\Controllers\NexPrinterInvoiceController;
use Modules\Integrations\NexPrinter\Http\Controllers\NexPrinterSettingsController;
use Modules\Integrations\NexPrinter\Http\Controllers\NexPrinterShipmentController;
use Modules\Integrations\NexPrinter\Http\Middleware\AuthenticateNexPrinterAgent;
use Modules\Integrations\PayNow\Http\Controllers\PayNowConnectionTestController;
use Modules\Integrations\PayNow\Http\Controllers\PayNowCustomerReturnController;
use Modules\Integrations\PayNow\Http\Controllers\PayNowPaymentLinkController;
use Modules\Integrations\PayNow\Http\Controllers\PayNowSettingsController;
use Modules\Integrations\PayNow\Http\Controllers\PayNowShortLinkController;
use Modules\Integrations\PayNow\Http\Controllers\PayNowWebhookController;
use Modules\Integrations\Prestashop\Http\Controllers\PrestashopConnectionTestController;
use Modules\Integrations\Prestashop\Http\Controllers\PrestashopOrderDiscoveryController;
use Modules\Integrations\Prestashop\Http\Controllers\PrestashopOrderImportController;
use Modules\Integrations\Prestashop\Http\Controllers\PrestashopOrderImportRetryController;
use Modules\Integrations\Prestashop\Http\Controllers\PrestashopOrderImportSettingsController;
use Modules\Integrations\Prestashop\Http\Controllers\PrestashopPaymentSyncController;
use Modules\Integrations\Prestashop\Http\Controllers\PrestashopPaymentSyncSettingsController;
use Modules\Integrations\Prestashop\Http\Controllers\PrestashopProductLinkController;
use Modules\Integrations\Prestashop\Http\Controllers\PrestashopSettingsController;
use Modules\Integrations\Prestashop\Http\Controllers\PrestashopStatusMappingController;
use Modules\Integrations\Prestashop\Http\Controllers\PrestashopStatusSyncController;
use Modules\Invoices\Http\Controllers\CorrectionController;
use Modules\Invoices\Http\Controllers\InvoiceBulkDeletionController;
use Modules\Invoices\Http\Controllers\InvoiceBulkPdfController;
use Modules\Invoices\Http\Controllers\InvoiceController;
use Modules\Invoices\Http\Controllers\InvoiceDeletionController;
use Modules\Invoices\Http\Controllers\InvoiceEditController;
use Modules\Invoices\Http\Controllers\InvoiceItemController;
use Modules\Invoices\Http\Controllers\InvoiceOrderItemsCopyController;
use Modules\Invoices\Http\Controllers\InvoicePdfController;
use Modules\Invoices\Http\Controllers\InvoiceSeriesController;
use Modules\Invoices\Http\Controllers\InvoiceSeriesNextNumberController;
use Modules\Invoices\Http\Controllers\JpkTaxpayerProfileController;
use Modules\Invoices\Http\Controllers\OrderInvoiceController;
use Modules\Invoices\Http\Controllers\OrderProformaController;
use Modules\Invoices\Http\Controllers\SalesRegisterController;
use Modules\Ksef\Http\Controllers\KsefConnectionTestController;
use Modules\Ksef\Http\Controllers\KsefInvoiceSubmissionController;
use Modules\Ksef\Http\Controllers\KsefLatarniaSyncController;
use Modules\Ksef\Http\Controllers\KsefMonthlyInvoiceExportController;
use Modules\Ksef\Http\Controllers\KsefOfflineCertificateController;
use Modules\Ksef\Http\Controllers\KsefOfflineInvoiceSubmissionController;
use Modules\Ksef\Http\Controllers\KsefOfflineIssuanceController;
use Modules\Ksef\Http\Controllers\KsefOfflinePresentationController;
use Modules\Ksef\Http\Controllers\KsefOfflineTechnicalCorrectionController;
use Modules\Ksef\Http\Controllers\KsefPaymentTypeSettingsController;
use Modules\Ksef\Http\Controllers\KsefSeriesSettingsController;
use Modules\Ksef\Http\Controllers\KsefSettingsController;
use Modules\Products\Http\Controllers\ProductBundleController;
use Modules\Products\Http\Controllers\ProductController;

Route::get('/', function (Request $request, PortalUrlService $portalUrls, PortalController $portal) {
    if ($portalUrls->isCustomerLanding($request)) {
        return $portal->unavailable();
    }

    $dashboardStats = [
        'newOrders' => Order::query()
            ->where('status', Order::STATUS_NEW)
            ->count(),
        'pending' => Order::query()
            ->where('status', Order::STATUS_PENDING)
            ->count(),
        'shippedToday' => Order::query()
            ->where('status', Order::STATUS_SHIPPED)
            ->whereDate('updated_at', today())
            ->count(),
        'cancelled' => Order::query()
            ->where('status', Order::STATUS_CANCELLED)
            ->count(),
    ];

    return view('dashboard', [
        'dashboardStats' => $dashboardStats,
    ]);
});

Route::get('/analytics/basic', BasicStatisticsController::class)->name('analytics.basic');
Route::get('/analytics/basic/csv', BasicStatisticsCsvController::class)->name('analytics.basic.csv');

Route::post('/api/gus/company-by-nip', [GusCompanyController::class, 'show'])->name('gus.company-by-nip');
Route::post('/api/paynow/v1/notifications', PayNowWebhookController::class)
    ->middleware('throttle:paynow-webhook')
    ->name('api.paynow.notifications');

Route::get('/paynow/powrot', [PayNowCustomerReturnController::class, 'show'])
    ->middleware('throttle:paynow-customer')->name('paynow.return');
Route::get('/pay/{token}', PayNowShortLinkController::class)
    ->where('token', '[A-Za-z0-9_-]{32}')
    ->middleware('throttle:paynow-customer')->name('paynow.short-link');
Route::prefix('{orderNumber}/{token}')->where(['orderNumber' => '[1-9][0-9]{0,17}', 'token' => '[A-Za-z0-9]{10}'])->middleware('throttle:customer-portal')->group(function (): void {
    Route::get('/', [PortalController::class, 'show'])->name('customer-portal.show');
    Route::get('/dane', [PortalController::class, 'details'])->name('customer-portal.details');
    Route::post('/weryfikacja', [PortalController::class, 'verify'])->name('customer-portal.verify');
    Route::get('/dokumenty/{invoice}', [PortalController::class, 'document'])->whereNumber('invoice')->name('customer-portal.document');
    Route::get('/produkty/{item}/zdjecie', [PortalController::class, 'image'])->whereNumber('item')->name('customer-portal.image');
    Route::get('/logo', [PortalController::class, 'logo'])->name('customer-portal.logo');
    Route::post('/platnosc/{action}', PortalPaymentController::class)->whereIn('action', ['pay', 'status', 'retry'])
        ->middleware('throttle:customer-portal-payment')->name('customer-portal.payment');
});
Route::get('/settings/customer-portal', [PortalSettingsController::class, 'edit'])->name('customer-portal.settings');
Route::put('/settings/customer-portal', [PortalSettingsController::class, 'update'])->name('customer-portal.settings.update');
Route::get('/settings/customer-portal/preview', [PortalSettingsController::class, 'selectPreview'])->name('customer-portal.preview-select');
Route::prefix('settings/customer-portal/preview/{order}')->group(function (): void {
    Route::get('/', [PortalController::class, 'preview'])->name('customer-portal.preview');
    Route::get('/dokumenty/{invoice}', [PortalController::class, 'previewDocument'])->whereNumber('invoice')->name('customer-portal.preview.document');
    Route::get('/produkty/{item}/zdjecie', [PortalController::class, 'previewImage'])->whereNumber('item')->name('customer-portal.preview.image');
    Route::get('/logo', [PortalController::class, 'previewLogo'])->name('customer-portal.preview.logo');
});
Route::post('/orders/{order}/customer-portal/link', [PortalSettingsController::class, 'issue'])->name('customer-portal.issue');
Route::post('/orders/{order}/customer-portal/revoke', [PortalSettingsController::class, 'revoke'])->name('customer-portal.revoke');
Route::post('/paynow/powrot/status', [PayNowCustomerReturnController::class, 'status'])
    ->middleware('throttle:paynow-customer-status')->name('paynow.return.status');
Route::post('/paynow/powrot/ponow', [PayNowCustomerReturnController::class, 'retry'])
    ->middleware('throttle:paynow-customer-retry')->name('paynow.return.retry');

Route::get('/integrations', [IntegrationController::class, 'index'])->name('integrations.index');
Route::get('/integrations/allegro', [AllegroSettingsController::class, 'edit'])->name('integrations.allegro.edit');
Route::put('/integrations/allegro', [AllegroSettingsController::class, 'update'])->name('integrations.allegro.update');
Route::match(['post', 'put'], '/integrations/allegro/device/start', [AllegroSettingsController::class, 'startDeviceAuthorization'])->name('integrations.allegro.device.start');
Route::post('/integrations/allegro/device/poll', [AllegroSettingsController::class, 'pollDeviceAuthorization'])->name('integrations.allegro.device.poll');
Route::match(['post', 'put'], '/integrations/allegro/device/cancel', [AllegroSettingsController::class, 'cancelDeviceAuthorization'])->name('integrations.allegro.device.cancel');
Route::post('/integrations/allegro/test-connection', [AllegroSettingsController::class, 'testConnection'])->name('integrations.allegro.test-connection');
Route::delete('/integrations/allegro/disconnect', [AllegroSettingsController::class, 'disconnect'])->name('integrations.allegro.disconnect');
Route::post('/integrations/allegro/orders/import', AllegroOrderImportController::class)->name('integrations.allegro.orders.import');
Route::put('/integrations/allegro/orders/settings', AllegroOrderImportSettingsController::class)->name('integrations.allegro.orders.settings');
Route::put('/integrations/allegro/statuses', [AllegroStatusMappingController::class, 'update'])->name('integrations.allegro.statuses.update');
Route::put('/integrations/allegro/shipments/settings', [AllegroShipmentSettingsController::class, 'update'])->name('integrations.allegro.shipments.settings');
Route::post('/orders/{order}/allegro/shipments/{export}/retry', [AllegroShipmentExportController::class, 'retry'])->name('orders.allegro.shipments.retry');
Route::put('/integrations/allegro/invoices/settings', [AllegroInvoiceSettingsController::class, 'update'])->name('integrations.allegro.invoices.settings');
Route::put('/integrations/allegro/corrections/settings', [AllegroInvoiceSettingsController::class, 'updateCorrections'])->name('integrations.allegro.corrections.settings');
Route::post('/orders/{order}/allegro/invoices/{invoice}', [AllegroInvoiceExportController::class, 'store'])->name('orders.allegro.invoices.store');
Route::post('/orders/{order}/allegro/invoice-exports/{export}/retry', [AllegroInvoiceExportController::class, 'retry'])->name('orders.allegro.invoices.retry');
Route::post('/orders/{order}/allegro/status/retry', [AllegroStatusSyncController::class, 'retry'])->name('orders.allegro.status.retry');
Route::put('/integrations/allegro/orders/automatic', [AllegroAutomaticOrdersController::class, 'settings'])->name('integrations.allegro.orders.automatic');
Route::post('/integrations/allegro/orders/scan', [AllegroAutomaticOrdersController::class, 'scan'])->name('integrations.allegro.orders.scan');
Route::post('/integrations/allegro/orders/events/{allegroOrderImportEvent}/retry', [AllegroAutomaticOrdersController::class, 'retry'])->name('integrations.allegro.orders.retry');
Route::post('/integrations/allegro/orders/history/preview', [AllegroAutomaticOrdersController::class, 'historyPreview'])->name('integrations.allegro.orders.history.preview');
Route::post('/integrations/allegro/orders/history/import', [AllegroAutomaticOrdersController::class, 'historyImport'])->name('integrations.allegro.orders.history.import');
Route::post('/orders/{order}/allegro-information', AllegroOrderInformationController::class)
    ->middleware('throttle:20,1')->name('orders.allegro-information.refresh');
Route::post('/orders/{order}/allegro-fees', [AllegroOrderInformationController::class, 'fees'])
    ->middleware('throttle:20,1')->name('orders.allegro-fees.refresh');
Route::post('/products/{product}/integrations/allegro/{allegroAccount}', [AllegroProductLinkController::class, 'store'])->name('products.integrations.allegro.store');
Route::patch('/products/{product}/integrations/allegro/{allegroAccount}/{allegroProductLink}', [AllegroProductLinkController::class, 'update'])->name('products.integrations.allegro.update');
Route::delete('/products/{product}/integrations/allegro/{allegroAccount}/{allegroProductLink}', [AllegroProductLinkController::class, 'destroy'])->name('products.integrations.allegro.destroy');
Route::get('/integrations/nex-printer', [NexPrinterSettingsController::class, 'index'])->name('integrations.nex-printer.index');
Route::post('/integrations/nex-printer/release/download', [NexPrinterSettingsController::class, 'downloadInstaller'])->name('integrations.nex-printer.release.download');
Route::post('/integrations/nex-printer/pairing-codes', [NexPrinterSettingsController::class, 'createPairingCode'])->name('integrations.nex-printer.pairing-codes.store');
Route::patch('/integrations/nex-printer/printers/{printer}/enabled', [NexPrinterSettingsController::class, 'updatePrinterEnabled'])->name('integrations.nex-printer.printers.enabled');
Route::delete('/integrations/nex-printer/agents/{agent}/remove', [NexPrinterSettingsController::class, 'removeAgent'])->name('integrations.nex-printer.agents.remove');
Route::delete('/integrations/nex-printer/agents/{agent}', [NexPrinterSettingsController::class, 'revokeAgent'])->name('integrations.nex-printer.agents.destroy');
Route::get('/integrations/paynow', [PayNowSettingsController::class, 'edit'])->name('integrations.paynow.edit');
Route::put('/integrations/paynow', [PayNowSettingsController::class, 'update'])->name('integrations.paynow.update');
Route::post('/integrations/paynow/test-connection', PayNowConnectionTestController::class)->name('integrations.paynow.test-connection');
Route::get('/integrations/prestashop', [PrestashopSettingsController::class, 'edit'])->name('integrations.prestashop.edit');
Route::put('/integrations/prestashop', [PrestashopSettingsController::class, 'update'])->name('integrations.prestashop.update');
Route::put('/integrations/prestashop/status-mappings', [PrestashopStatusMappingController::class, 'update'])->name('integrations.prestashop.status-mappings.update');
Route::post('/integrations/prestashop/status-sync', PrestashopStatusSyncController::class)->name('integrations.prestashop.status-sync');
Route::post('/integrations/prestashop/test-connection', PrestashopConnectionTestController::class)->name('integrations.prestashop.test-connection');
Route::post('/integrations/prestashop/orders/import', PrestashopOrderImportController::class)->name('integrations.prestashop.orders.import');
Route::put('/integrations/prestashop/order-import', [PrestashopOrderImportSettingsController::class, 'update'])->name('integrations.prestashop.order-import.update');
Route::post('/integrations/prestashop/order-import/discover', PrestashopOrderDiscoveryController::class)->name('integrations.prestashop.order-import.discover');
Route::post('/integrations/prestashop/order-import/attempts/{attempt}/retry', PrestashopOrderImportRetryController::class)->name('integrations.prestashop.order-import.retry');
Route::put('/integrations/prestashop/payment-sync', [PrestashopPaymentSyncSettingsController::class, 'update'])->name('integrations.prestashop.payment-sync.update');
Route::post('/integrations/prestashop/payment-sync/run', PrestashopPaymentSyncController::class)->name('integrations.prestashop.payment-sync.run');
Route::post('/orders/{order}/paynow/payment-link', PayNowPaymentLinkController::class)->name('orders.paynow.payment-link');
Route::get('/integrations/ksef', [KsefSettingsController::class, 'edit'])->name('integrations.ksef.edit');
Route::put('/integrations/ksef', [KsefSettingsController::class, 'update'])->name('integrations.ksef.update');
Route::post('/integrations/ksef/test-connection', KsefConnectionTestController::class)->name('integrations.ksef.test-connection');
Route::post('/integrations/ksef/latarnia/refresh', KsefLatarniaSyncController::class)->name('integrations.ksef.latarnia.refresh');
Route::post('/integrations/ksef/export', KsefMonthlyInvoiceExportController::class)->name('integrations.ksef.export');
Route::put('/integrations/ksef/series', [KsefSeriesSettingsController::class, 'update'])->name('integrations.ksef.series.update');
Route::put('/integrations/ksef/payment-types', [KsefPaymentTypeSettingsController::class, 'update'])->name('integrations.ksef.payment-types.update');
Route::post('/integrations/ksef/offline-certificates', [KsefOfflineCertificateController::class, 'store'])->name('integrations.ksef.offline-certificates.store');
Route::post('/integrations/ksef/offline-certificates/{offlineCertificate}/verify', [KsefOfflineCertificateController::class, 'verify'])->name('integrations.ksef.offline-certificates.verify');
Route::put('/integrations/ksef/offline-certificates/{offlineCertificate}/preferred', [KsefOfflineCertificateController::class, 'prefer'])->name('integrations.ksef.offline-certificates.prefer');
Route::delete('/integrations/ksef/offline-certificates/{offlineCertificate}', [KsefOfflineCertificateController::class, 'destroy'])->name('integrations.ksef.offline-certificates.destroy');
Route::get('/integrations/couriers', [CourierIntegrationController::class, 'index'])->name('integrations.couriers.index');
Route::get('/integrations/couriers/inpost-lockers', [CourierIntegrationController::class, 'editInPostLockers'])->name('integrations.couriers.inpost-lockers.edit');
Route::put('/integrations/couriers/inpost-lockers', [CourierIntegrationController::class, 'updateInPostLockers'])->name('integrations.couriers.inpost-lockers.update');
Route::post('/integrations/couriers/inpost-lockers/test', [CourierIntegrationController::class, 'testInPostLockers'])->name('integrations.couriers.inpost-lockers.test');
Route::post('/integrations/couriers/inpost-lockers/shipments/refresh', [ShipmentController::class, 'bulkRefresh'])->name('integrations.couriers.inpost-lockers.shipments.refresh');
Route::post('/integrations/couriers/inpost-lockers/shipments/delete', [ShipmentController::class, 'bulkDelete'])->name('integrations.couriers.inpost-lockers.shipments.delete');
Route::get('/integrations/couriers/inpost-courier', [CourierIntegrationController::class, 'editInPostCourier'])->name('integrations.couriers.inpost-courier.edit');
Route::put('/integrations/couriers/inpost-courier', [CourierIntegrationController::class, 'updateInPostCourier'])->name('integrations.couriers.inpost-courier.update');
Route::post('/integrations/couriers/inpost-courier/test', [CourierIntegrationController::class, 'testInPostCourier'])->name('integrations.couriers.inpost-courier.test');
Route::post('/integrations/couriers/inpost-courier/shipments/refresh', [ShipmentController::class, 'bulkRefreshInPostCourier'])->name('integrations.couriers.inpost-courier.shipments.refresh');
Route::post('/integrations/couriers/inpost-courier/shipments/delete', [ShipmentController::class, 'bulkDeleteInPostCourier'])->name('integrations.couriers.inpost-courier.shipments.delete');
Route::get('/integrations/couriers/dpd', [CourierIntegrationController::class, 'editDpd'])->name('integrations.couriers.dpd.edit');
Route::put('/integrations/couriers/dpd', [CourierIntegrationController::class, 'updateDpd'])->name('integrations.couriers.dpd.update');
Route::post('/integrations/couriers/dpd/test', [CourierIntegrationController::class, 'testDpd'])->name('integrations.couriers.dpd.test');
Route::post('/integrations/couriers/dpd/shipments/refresh', [ShipmentController::class, 'bulkRefreshDpd'])->name('integrations.couriers.dpd.shipments.refresh');
Route::post('/integrations/couriers/dpd/shipments/delete', [ShipmentController::class, 'bulkDeleteDpd'])->name('integrations.couriers.dpd.shipments.delete');
Route::get('/integrations/couriers/allegro-shipping', [CourierIntegrationController::class, 'editAllegroShipping'])->name('integrations.couriers.allegro-shipping.edit');
Route::put('/integrations/couriers/allegro-shipping', [CourierIntegrationController::class, 'updateAllegroShipping'])->name('integrations.couriers.allegro-shipping.update');
Route::match(['post', 'put'], '/integrations/couriers/allegro-shipping/test', [CourierIntegrationController::class, 'testAllegroShipping'])->name('integrations.couriers.allegro-shipping.test');
Route::match(['post', 'put'], '/integrations/couriers/allegro-shipping/device/start', [CourierIntegrationController::class, 'startAllegroShippingDevice'])->name('integrations.couriers.allegro-shipping.device.start');
Route::post('/integrations/couriers/allegro-shipping/device/poll', [CourierIntegrationController::class, 'pollAllegroShippingDevice'])->name('integrations.couriers.allegro-shipping.device.poll');
Route::match(['post', 'put'], '/integrations/couriers/allegro-shipping/device/cancel', [CourierIntegrationController::class, 'cancelAllegroShippingDevice'])->name('integrations.couriers.allegro-shipping.device.cancel');
Route::post('/integrations/couriers/allegro-shipping/shipments/refresh', [ShipmentController::class, 'bulkRefreshAllegroShipping'])->name('integrations.couriers.allegro-shipping.shipments.refresh');
Route::post('/integrations/couriers/allegro-shipping/shipments/delete', [ShipmentController::class, 'bulkDeleteAllegroShipping'])->name('integrations.couriers.allegro-shipping.shipments.delete');
Route::post('/integrations/couriers/allegro-shipping/templates', [AllegroShippingParcelTemplateController::class, 'store'])->name('integrations.couriers.allegro-shipping.templates.store');
Route::put('/integrations/couriers/allegro-shipping/templates/{templateId}', [AllegroShippingParcelTemplateController::class, 'update'])->name('integrations.couriers.allegro-shipping.templates.update');
Route::delete('/integrations/couriers/allegro-shipping/templates/{templateId}', [AllegroShippingParcelTemplateController::class, 'destroy'])->name('integrations.couriers.allegro-shipping.templates.destroy');
Route::post('/integrations/couriers/dpd/templates', [DpdParcelTemplateController::class, 'store'])->name('integrations.couriers.dpd.templates.store');
Route::put('/integrations/couriers/dpd/templates/{templateId}', [DpdParcelTemplateController::class, 'update'])->name('integrations.couriers.dpd.templates.update');
Route::delete('/integrations/couriers/dpd/templates/{templateId}', [DpdParcelTemplateController::class, 'destroy'])->name('integrations.couriers.dpd.templates.destroy');
Route::post('/integrations/couriers/inpost-courier/templates', [InPostCourierParcelTemplateController::class, 'store'])->name('integrations.couriers.inpost-courier.templates.store');
Route::put('/integrations/couriers/inpost-courier/templates/{templateId}', [InPostCourierParcelTemplateController::class, 'update'])->name('integrations.couriers.inpost-courier.templates.update');
Route::delete('/integrations/couriers/inpost-courier/templates/{templateId}', [InPostCourierParcelTemplateController::class, 'destroy'])->name('integrations.couriers.inpost-courier.templates.destroy');

Route::get('/settings/order-statuses', [SettingsOrderStatusesController::class, 'index'])->name('settings.order-statuses.index');
Route::post('/settings/order-statuses', [SettingsOrderStatusesController::class, 'store'])->name('settings.order-statuses.store');
Route::patch('/settings/order-statuses/order', [SettingsOrderStatusesController::class, 'updateOrder'])->name('settings.order-statuses.order');
Route::patch('/settings/order-statuses/{orderStatusSetting}', [SettingsOrderStatusesController::class, 'update'])->name('settings.order-statuses.update');
Route::delete('/settings/order-statuses/{orderStatusSetting}', [SettingsOrderStatusesController::class, 'destroy'])->name('settings.order-statuses.destroy');
Route::get('/settings/variables', [SettingsVariablesController::class, 'index'])->name('settings.variables.index');

Route::get('/products', [ProductController::class, 'index'])->name('products.index');
Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
Route::post('/products', [ProductController::class, 'store'])->name('products.store');
Route::get('/products/bundles/create', [ProductBundleController::class, 'create'])->name('product-bundles.create');
Route::post('/products/bundles', [ProductBundleController::class, 'store'])->name('product-bundles.store');
Route::get('/products/bundles/{bundle}/edit', [ProductBundleController::class, 'edit'])->name('product-bundles.edit');
Route::patch('/products/bundles/{bundle}', [ProductBundleController::class, 'update'])->name('product-bundles.update');
Route::get('/products/{product}/image/{variant}', [ProductController::class, 'image'])
    ->where('variant', 'original|thumbnail')
    ->name('products.image');
Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
Route::patch('/products/{product}', [ProductController::class, 'update'])->name('products.update');
Route::patch('/products/{product}/stock', [ProductController::class, 'updateStock'])->name('products.stock');
Route::patch('/products/{product}/active', [ProductController::class, 'updateActive'])->name('products.active');
Route::post('/products/{product}/integrations/prestashop/{prestashopAccount}/links', [PrestashopProductLinkController::class, 'store'])
    ->name('products.integrations.prestashop.store');
Route::patch('/products/{product}/integrations/prestashop/{prestashopAccount}/links/{prestashopProductLink}', [PrestashopProductLinkController::class, 'update'])
    ->name('products.integrations.prestashop.update');
Route::delete('/products/{product}/integrations/prestashop/{prestashopAccount}/links/{prestashopProductLink}', [PrestashopProductLinkController::class, 'destroy'])
    ->name('products.integrations.prestashop.destroy');

Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
Route::get('/invoices/sales-register', [SalesRegisterController::class, 'create'])->name('invoices.sales-register.create');
Route::post('/invoices/sales-register/selected', [SalesRegisterController::class, 'selected'])->name('invoices.sales-register.selected');
Route::post('/invoices/sales-register/export', [SalesRegisterController::class, 'export'])->name('invoices.sales-register.export');
Route::post('/invoices/print-selected', InvoiceBulkPdfController::class)->name('invoices.bulk-pdf');
Route::delete('/invoices/delete-selected', InvoiceBulkDeletionController::class)->name('invoices.bulk-delete');
Route::get('/invoices/proformas', [InvoiceController::class, 'proformas'])->name('invoices.proformas.index');
Route::post('/invoices/proformas/print-selected', [InvoiceBulkPdfController::class, 'proformas'])->name('invoices.proformas.bulk-pdf');
Route::delete('/invoices/proformas/delete-selected', [InvoiceBulkDeletionController::class, 'proformas'])->name('invoices.proformas.bulk-delete');
Route::get('/invoices/corrections', [InvoiceController::class, 'corrections'])->name('invoices.corrections.index');
Route::post('/invoices/corrections/print-selected', [InvoiceBulkPdfController::class, 'corrections'])->name('invoices.corrections.bulk-pdf');
Route::delete('/invoices/corrections/delete-selected', [InvoiceBulkDeletionController::class, 'corrections'])->name('invoices.corrections.bulk-delete');
Route::get('/invoices/{invoice}/corrections/create', [CorrectionController::class, 'create'])->name('invoices.corrections.create');
Route::post('/invoices/{invoice}/corrections', [CorrectionController::class, 'store'])->name('invoices.corrections.store');
Route::get('/invoices/corrections/{correction}/edit', [CorrectionController::class, 'edit'])->name('invoices.corrections.edit');
Route::patch('/invoices/corrections/{correction}', [CorrectionController::class, 'update'])->name('invoices.corrections.update');
Route::post('/invoices/corrections/{correction}/finalize', [CorrectionController::class, 'finalize'])->name('invoices.corrections.finalize');
Route::post('/invoices/{invoice}/ksef/submissions/first-attempt', [KsefInvoiceSubmissionController::class, 'firstAttempt'])->name('invoices.ksef.submissions.first-attempt');
Route::post('/invoices/{invoice}/ksef/offline24', [KsefOfflineIssuanceController::class, 'offline24'])->name('invoices.ksef.offline24.issue');
Route::post('/invoices/{invoice}/ksef/offline-unavailability', [KsefOfflineIssuanceController::class, 'plannedUnavailability'])->name('invoices.ksef.offline-unavailability.issue');
Route::post('/invoices/{invoice}/ksef/emergency', [KsefOfflineIssuanceController::class, 'failure'])->name('invoices.ksef.emergency.issue');
Route::post('/invoices/{invoice}/ksef/offline-issuances/{issuance}/submissions', KsefOfflineInvoiceSubmissionController::class)->name('invoices.ksef.offline-issuances.submissions.store');
Route::post('/invoices/{invoice}/ksef/offline-issuances/{issuance}/submissions/{submission}/technical-correction', [KsefOfflineTechnicalCorrectionController::class, 'prepare'])->name('invoices.ksef.offline-technical-corrections.prepare');
Route::post('/invoices/{invoice}/ksef/offline-technical-corrections/{technicalCorrection}/submissions', [KsefOfflineTechnicalCorrectionController::class, 'submit'])->name('invoices.ksef.offline-technical-corrections.submit');
Route::get('/invoices/{invoice}/ksef/offline-issuances/{issuance}/invoice-pdf', [KsefOfflinePresentationController::class, 'invoice'])->name('invoices.ksef.offline-issuances.invoice-pdf');
Route::get('/invoices/{invoice}/ksef/offline-issuances/{issuance}/transaction-confirmation', [KsefOfflinePresentationController::class, 'transactionConfirmation'])->name('invoices.ksef.offline-issuances.transaction-confirmation');
Route::get('/invoices/{invoice}/ksef/offline-issuances/{issuance}/submissions/{submission}/accepted-pdf', [KsefOfflinePresentationController::class, 'acceptedInvoice'])->name('invoices.ksef.offline-issuances.accepted-pdf');
Route::post('/invoices/{invoice}/ksef/submissions', [KsefInvoiceSubmissionController::class, 'store'])->name('invoices.ksef.submissions.store');
Route::post('/invoices/{invoice}/ksef/submissions/{submission}/refresh', [KsefInvoiceSubmissionController::class, 'refresh'])->name('invoices.ksef.submissions.refresh');
Route::post('/invoices/{invoice}/ksef/submissions/{submission}/reconcile', [KsefInvoiceSubmissionController::class, 'reconcile'])->name('invoices.ksef.submissions.reconcile');
Route::post('/invoices/{invoice}/ksef/submissions/{submission}/upo', [KsefInvoiceSubmissionController::class, 'fetchUpo'])->name('invoices.ksef.submissions.upo.fetch');
Route::get('/invoices/{invoice}/ksef/submissions/{submission}/upo', [KsefInvoiceSubmissionController::class, 'downloadUpo'])->name('invoices.ksef.submissions.upo.download');
Route::get('/invoices/{invoice}/ksef/submissions/{submission}/invoice', [KsefInvoiceSubmissionController::class, 'downloadInvoiceSource'])->name('invoices.ksef.submissions.invoice.download');
Route::get('/invoices/{invoice}/edit', [InvoiceEditController::class, 'edit'])->name('invoices.edit');
Route::delete('/invoices/{invoice}', InvoiceDeletionController::class)->name('invoices.destroy');
Route::patch('/invoices/{invoice}/buyer', [InvoiceEditController::class, 'updateBuyer'])->name('invoices.buyer.update');
Route::patch('/invoices/{invoice}/recipient', [InvoiceEditController::class, 'updateRecipient'])->name('invoices.recipient.update');
Route::patch('/invoices/{invoice}/details', [InvoiceEditController::class, 'updateDetails'])->name('invoices.details.update');
Route::post('/invoices/{invoice}/items/copy-from-order', [InvoiceOrderItemsCopyController::class, 'store'])->name('invoices.items.copy-from-order');
Route::post('/invoices/{invoice}/items', [InvoiceItemController::class, 'store'])->name('invoices.items.store');
Route::patch('/invoices/{invoice}/items/{invoiceItem}', [InvoiceItemController::class, 'update'])->name('invoices.items.update');
Route::delete('/invoices/{invoice}/items/{invoiceItem}', [InvoiceItemController::class, 'destroy'])->name('invoices.items.destroy');
Route::get('/invoices/{invoice}/pdf', [InvoicePdfController::class, 'show'])->name('invoices.pdf');
Route::post('/invoices/{invoice}/nex-printer', [NexPrinterInvoiceController::class, 'store'])->name('invoices.nex-printer.store');
Route::get('/invoices/settings/series', [InvoiceSeriesController::class, 'index'])->name('invoices.series.index');
Route::get('/invoices/settings/jpk', [JpkTaxpayerProfileController::class, 'form'])->name('invoices.jpk-profile.edit');
Route::post('/invoices/settings/jpk', [JpkTaxpayerProfileController::class, 'save'])->name('invoices.jpk-profile.save');
Route::post('/invoices/settings/jpk/suggest', [JpkTaxpayerProfileController::class, 'suggest'])->name('invoices.jpk-profile.suggest');
Route::post('/invoices/sales-register/profile', [SalesRegisterController::class, 'loadProfile'])->name('invoices.sales-register.profile');
Route::get('/invoices/settings/series/form', [InvoiceSeriesController::class, 'form'])->name('invoices.series.form');
Route::post('/invoices/settings/series', [InvoiceSeriesController::class, 'store'])->name('invoices.series.store');
Route::get('/invoices/settings/series/{series}/edit', [InvoiceSeriesController::class, 'edit'])->name('invoices.series.edit');
Route::get('/invoices/settings/series/{series}/next-number', [InvoiceSeriesNextNumberController::class, 'show'])->name('invoices.series.next-number.form');
Route::get('/invoices/settings/series/{series}/next-number/preview', [InvoiceSeriesNextNumberController::class, 'preview'])->name('invoices.series.next-number.preview');
Route::post('/invoices/settings/series/{series}/next-number', [InvoiceSeriesNextNumberController::class, 'store'])->name('invoices.series.next-number.store');
Route::patch('/invoices/settings/series/{series}', [InvoiceSeriesController::class, 'update'])->name('invoices.series.update');
Route::patch('/invoices/settings/series/{series}/active', [InvoiceSeriesController::class, 'updateActive'])->name('invoices.series.active');
Route::delete('/invoices/settings/series/{series}', [InvoiceSeriesController::class, 'destroy'])->name('invoices.series.destroy');

Route::prefix('/api/nex-printer/v1')->name('nex-printer.api.')->group(function (): void {
    Route::post('/pair', [NexPrinterClientController::class, 'pair'])
        ->middleware('throttle:nex-printer-pairing')
        ->name('pair');

    Route::middleware([
        AuthenticateNexPrinterAgent::class,
        'throttle:nex-printer-agent',
    ])->group(function (): void {
        Route::post('/heartbeat', [NexPrinterClientController::class, 'heartbeat'])->name('heartbeat');
        Route::put('/printers', [NexPrinterClientController::class, 'synchronizePrinters'])->name('printers.update');
        Route::post('/jobs/claim', [NexPrinterClientController::class, 'claim'])->name('jobs.claim');
        Route::get('/jobs/{printJob:public_id}/payload', [NexPrinterClientController::class, 'payload'])->name('jobs.payload');
        Route::post('/jobs/{printJob:public_id}/complete', [NexPrinterClientController::class, 'complete'])->name('jobs.complete');
    });
});

Route::get('/orders', [OrdersController::class, 'index'])->name('orders.index');
Route::get('/orders/list-state', [OrdersController::class, 'listState'])->name('orders.list-state');
Route::get('/orders/scan', OrderScanController::class)->name('orders.scan');
Route::post('/orders/saved-views', [OrderSavedViewController::class, 'store'])->name('orders.saved-views.store');
Route::patch('/orders/saved-views/order', [OrderSavedViewController::class, 'reorder'])->name('orders.saved-views.order');
Route::patch('/orders/saved-views/{orderSavedView}', [OrderSavedViewController::class, 'update'])->name('orders.saved-views.update');
Route::delete('/orders/saved-views/{orderSavedView}', [OrderSavedViewController::class, 'destroy'])->name('orders.saved-views.destroy');
Route::get('/automation/activity', [AutomationActivityController::class, 'index'])->name('automation.activity.index');
Route::get('/orders/automatic-actions', [AutomationRuleController::class, 'index'])->name('orders.automatic-actions.index');
Route::get('/orders/automatic-actions/create', [AutomationRuleController::class, 'create'])->name('orders.automatic-actions.create');
Route::post('/orders/automatic-actions', [AutomationRuleController::class, 'store'])->name('orders.automatic-actions.store');
Route::get('/orders/automatic-actions/{automationRule}/edit', [AutomationRuleController::class, 'edit'])->name('orders.automatic-actions.edit');
Route::put('/orders/automatic-actions/{automationRule}', [AutomationRuleController::class, 'update'])->name('orders.automatic-actions.update');
Route::patch('/orders/automatic-actions/{automationRule}/toggle', [AutomationRuleController::class, 'toggle'])->name('orders.automatic-actions.toggle');
Route::delete('/orders/automatic-actions/{automationRule}', [AutomationRuleController::class, 'destroy'])->name('orders.automatic-actions.destroy');
Route::get('/orders/email-templates', [EmailTemplateController::class, 'index'])->name('orders.email-templates.index');
Route::post('/orders/email-templates', [EmailTemplateController::class, 'store'])->name('orders.email-templates.store');
Route::patch('/orders/email-templates/{emailTemplate}', [EmailTemplateController::class, 'update'])->name('orders.email-templates.update');
Route::post('/orders/email-templates/{emailTemplate}/duplicate', [EmailTemplateController::class, 'duplicate'])->name('orders.email-templates.duplicate');
Route::delete('/orders/email-templates/{emailTemplate}', [EmailTemplateController::class, 'destroy'])->name('orders.email-templates.destroy');
Route::get('/orders/email-accounts', [EmailAccountController::class, 'index'])->name('orders.email-accounts.index');
Route::post('/orders/email-accounts', [EmailAccountController::class, 'store'])->name('orders.email-accounts.store');
Route::patch('/orders/email-accounts/{emailAccount}', [EmailAccountController::class, 'update'])->name('orders.email-accounts.update');
Route::post('/orders/email-accounts/{emailAccount}/duplicate', [EmailAccountController::class, 'duplicate'])->name('orders.email-accounts.duplicate');
Route::post('/orders/email-accounts/{emailAccount}/test', [EmailAccountController::class, 'test'])->name('orders.email-accounts.test');
Route::delete('/orders/email-accounts/{emailAccount}', [EmailAccountController::class, 'destroy'])->name('orders.email-accounts.destroy');
Route::get('/orders/create', [OrdersController::class, 'create'])->name('orders.create');
Route::post('/orders', [OrdersController::class, 'store'])->name('orders.store');
Route::post('/orders/empty', [OrdersController::class, 'storeEmpty'])->name('orders.empty-store');
Route::post('/orders/bulk-trash', [OrdersController::class, 'bulkTrash'])->name('orders.bulk-trash');
Route::post('/orders/bulk-restore', [OrdersController::class, 'bulkRestore'])->name('orders.bulk-restore');
Route::post('/orders/bulk-force-delete', [OrdersController::class, 'bulkForceDelete'])->name('orders.bulk-force-delete');
Route::post('/orders/bulk-status', [OrdersController::class, 'bulkUpdateStatus'])->name('orders.bulk-status');
Route::post('/orders/{order}/create-for-customer', [OrdersController::class, 'createForCustomer'])->name('orders.create-for-customer');
Route::post('/orders/{order}/duplicate', [OrdersController::class, 'duplicate'])->name('orders.duplicate');
Route::post('/orders/{order}/invoice', [OrderInvoiceController::class, 'store'])->name('orders.invoice.store');
Route::post('/orders/{order}/proforma', [OrderProformaController::class, 'store'])->name('orders.proforma.store');
Route::post('/orders/{order}/products', [OrderProductController::class, 'store'])->name('orders.products.store');
Route::get('/orders/{order}/catalog-products', [OrderCatalogProductController::class, 'index'])
    ->name('orders.catalog-products.index');
Route::post('/orders/{order}/catalog-products', [OrderCatalogProductController::class, 'store'])
    ->name('orders.catalog-products.store');
Route::get('/orders/{order}/email-templates/{emailTemplate}/preview', [OrderEmailMessageController::class, 'preview'])
    ->name('orders.email-templates.preview');
Route::post('/orders/{order}/email-messages', [OrderEmailMessageController::class, 'store'])
    ->name('orders.email-messages.store');
Route::get('/orders/{order}/email-messages/{orderEmailMessage}/status', [OrderEmailMessageController::class, 'status'])
    ->name('orders.email-messages.status');
Route::get('/orders/{order}/email-messages/{orderEmailMessage}/attachments/{orderEmailMessageAttachment}', [OrderEmailMessageController::class, 'attachment'])
    ->name('orders.email-messages.attachments.show');
Route::get('/orders/{order}/shipments/{provider}/form', [ShipmentController::class, 'form'])
    ->where('provider', 'inpost_lockers|inpost_courier|dpd|allegro_shipping')
    ->name('orders.shipments.form');
Route::post('/orders/{order}/shipments/inpost', [ShipmentController::class, 'storeInPost'])->name('orders.shipments.inpost.store');
Route::post('/orders/{order}/shipments/inpost-courier', [ShipmentController::class, 'storeInPostCourier'])->name('orders.shipments.inpost-courier.store');
Route::post('/orders/{order}/shipments/dpd', [ShipmentController::class, 'storeDpd'])->name('orders.shipments.dpd.store');
Route::post('/orders/{order}/shipments/allegro-shipping', [ShipmentController::class, 'storeAllegroShipping'])->name('orders.shipments.allegro-shipping.store');
Route::get('/shipments/{shipment}/status', [ShipmentController::class, 'status'])->name('shipments.status');
Route::get('/shipment-creation-attempts/{shipmentCreationAttempt}/status', [ShipmentController::class, 'creationAttemptStatus'])
    ->name('shipment-creation-attempts.status');
Route::post('/shipments/{shipment}/refresh', [ShipmentController::class, 'refresh'])->name('shipments.refresh');
Route::post('/shipments/{shipment}/retry', [ShipmentController::class, 'retry'])->name('shipments.retry');
Route::post('/shipments/{shipment}/cancel', [ShipmentController::class, 'cancel'])->name('shipments.cancel');
Route::get('/shipments/{shipment}/label', [ShipmentController::class, 'label'])->name('shipments.label');
Route::post('/shipments/{shipment}/nex-printer', [NexPrinterShipmentController::class, 'store'])->name('shipments.nex-printer.store');
Route::patch('/order-items/{orderItem}', [OrderProductController::class, 'update'])->name('order-items.update');
Route::delete('/order-items/{orderItem}', [OrderProductController::class, 'destroy'])->name('order-items.destroy');
Route::get('/orders/{order}/state', [OrderStatusController::class, 'state'])->name('orders.state');
Route::patch('/orders/{order}/status', [OrderStatusController::class, 'update'])->name('orders.status.update');
Route::patch('/orders/{order}/paid-amount', [OrderMetaController::class, 'updatePaidAmount'])->name('orders.paid-amount.update');
Route::patch('/orders/{order}/recalculate-total', [OrderMetaController::class, 'recalculateTotal'])->name('orders.recalculate-total');
Route::patch('/orders/{order}/pickup-point', [OrderMetaController::class, 'updatePickupPoint'])->name('orders.pickup-point.update');
Route::patch('/orders/{order}/star-color', [OrderMetaController::class, 'updateStarColor'])->name('orders.star-color.update');
Route::patch('/orders/{order}/sections/order-info', [OrderSectionController::class, 'updateOrderInfo'])->name('orders.sections.order-info');
Route::patch('/orders/{order}/sections/shipping-address', [OrderSectionController::class, 'updateShippingAddress'])->name('orders.sections.shipping-address');
Route::patch('/orders/{order}/sections/billing-address', [OrderSectionController::class, 'updateBillingAddress'])->name('orders.sections.billing-address');
Route::patch('/orders/{order}/sections/payment', [OrderSectionController::class, 'updatePayment'])->name('orders.sections.payment');
Route::patch('/orders/{order}/sections/products', [OrderSectionController::class, 'updateProducts'])->name('orders.sections.products');
Route::get('/orders/{order}/edit', [OrdersController::class, 'edit'])->name('orders.edit');
Route::put('/orders/{order}', [OrdersController::class, 'update'])->name('orders.update');
Route::delete('/orders/{order}', [OrdersController::class, 'destroy'])->name('orders.destroy');
Route::get('/orders/returns', [OrderReturnController::class, 'index'])->name('returns.index');
Route::get('/orders/returns/create', [OrderReturnController::class, 'create'])->name('returns.create');
Route::post('/orders/returns', [OrderReturnController::class, 'store'])->name('returns.store');
Route::post('/orders/returns/bulk-status', [OrderReturnController::class, 'bulkStatus'])->name('returns.bulk-status');
Route::patch('/orders/returns/{orderReturn}/status', [OrderReturnController::class, 'status'])->name('returns.status');
Route::patch('/orders/returns/{orderReturn}/star', [OrderReturnController::class, 'star'])->name('returns.star');
Route::patch('/orders/returns/{orderReturn}/refunds/{refund}', [OrderReturnRefundController::class, 'update'])->name('returns.refunds.allocate');
Route::get('/orders/returns/settings/statuses', [ReturnStatusSettingsController::class, 'index'])->name('settings.return-statuses.index');
Route::post('/orders/returns/settings/statuses', [ReturnStatusSettingsController::class, 'store'])->name('settings.return-statuses.store');
Route::patch('/orders/returns/settings/statuses/{returnStatusSetting}', [ReturnStatusSettingsController::class, 'update'])->name('settings.return-statuses.update');
Route::delete('/orders/returns/settings/statuses/{returnStatusSetting}', [ReturnStatusSettingsController::class, 'destroy'])->name('settings.return-statuses.destroy');
Route::get('/orders/returns/{orderReturn}', [OrderReturnController::class, 'show'])->name('returns.show');
Route::get('/orders/{order}', [OrdersController::class, 'show'])->name('orders.show');
Route::put('/integrations/allegro/returns/settings', [AllegroReturnSettingsController::class, 'update'])->name('integrations.allegro.returns.settings');
Route::put('/integrations/allegro/refunds/settings', [AllegroRefundSettingsController::class, 'update'])->name('integrations.allegro.refunds.settings');
Route::put('/integrations/allegro/refund-commands/settings', [AllegroRefundCommandController::class, 'settings'])->name('integrations.allegro.refund-commands.settings');
Route::get('/orders/returns/{orderReturn}/refund-payment', [AllegroRefundCommandController::class, 'create'])->name('returns.refund-payment.create');
Route::post('/orders/returns/{orderReturn}/refund-payment', [AllegroRefundCommandController::class, 'prepare'])->name('returns.refund-payment.prepare');
Route::get('/orders/returns/{orderReturn}/refund-payment/{command}', [AllegroRefundCommandController::class, 'confirmation'])->name('returns.refund-payment.confirmation');
Route::post('/orders/returns/{orderReturn}/refund-payment/{command}/confirm', [AllegroRefundCommandController::class, 'confirm'])->name('returns.refund-payment.confirm');
Route::post('/orders/returns/{orderReturn}/refund-payment/{command}/reconcile', [AllegroRefundCommandController::class, 'reconcile'])->name('returns.refund-payment.reconcile');
Route::get('/orders/returns/{orderReturn}/commission-refund', [AllegroCommissionRefundController::class, 'create'])->name('returns.commission-refund.create');
Route::post('/orders/returns/{orderReturn}/commission-refund', [AllegroCommissionRefundController::class, 'store'])->name('returns.commission-refund.store');
