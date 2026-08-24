<?php

use App\Http\Controllers\GusCompanyController;
use App\Http\Controllers\Integrations\AllegroShippingParcelTemplateController;
use App\Http\Controllers\Integrations\CourierIntegrationController;
use App\Http\Controllers\Integrations\DpdParcelTemplateController;
use App\Http\Controllers\Integrations\InPostCourierParcelTemplateController;
use App\Http\Controllers\Integrations\IntegrationController;
use App\Http\Controllers\OrderMetaController;
use App\Http\Controllers\OrderProductController;
use App\Http\Controllers\OrderScanController;
use App\Http\Controllers\OrdersController;
use App\Http\Controllers\OrderSectionController;
use App\Http\Controllers\OrderStatusController;
use App\Http\Controllers\SettingsOrderStatusesController;
use App\Http\Controllers\SettingsVariablesController;
use App\Http\Controllers\ShipmentController;
use App\Models\Order;
use Illuminate\Support\Facades\Route;
use Modules\Automation\Http\Controllers\AutomationActivityController;
use Modules\Automation\Http\Controllers\AutomationRuleController;
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
use Modules\Invoices\Http\Controllers\OrderInvoiceController;
use Modules\Invoices\Http\Controllers\OrderProformaController;
use Modules\Ksef\Http\Controllers\KsefConnectionTestController;
use Modules\Ksef\Http\Controllers\KsefInvoiceSubmissionController;
use Modules\Ksef\Http\Controllers\KsefMonthlyInvoiceExportController;
use Modules\Ksef\Http\Controllers\KsefPaymentTypeSettingsController;
use Modules\Ksef\Http\Controllers\KsefSeriesSettingsController;
use Modules\Ksef\Http\Controllers\KsefSettingsController;

Route::get('/', function () {
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

Route::get('/api/gus/company-by-nip', [GusCompanyController::class, 'show'])->name('gus.company-by-nip');

Route::get('/integrations', [IntegrationController::class, 'index'])->name('integrations.index');
Route::get('/integrations/ksef', [KsefSettingsController::class, 'edit'])->name('integrations.ksef.edit');
Route::put('/integrations/ksef', [KsefSettingsController::class, 'update'])->name('integrations.ksef.update');
Route::post('/integrations/ksef/test-connection', KsefConnectionTestController::class)->name('integrations.ksef.test-connection');
Route::post('/integrations/ksef/export', KsefMonthlyInvoiceExportController::class)->name('integrations.ksef.export');
Route::put('/integrations/ksef/series', [KsefSeriesSettingsController::class, 'update'])->name('integrations.ksef.series.update');
Route::put('/integrations/ksef/payment-types', [KsefPaymentTypeSettingsController::class, 'update'])->name('integrations.ksef.payment-types.update');
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

Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
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
Route::post('/invoices/{invoice}/ksef/submissions', [KsefInvoiceSubmissionController::class, 'store'])->name('invoices.ksef.submissions.store');
Route::post('/invoices/{invoice}/ksef/submissions/{submission}/refresh', [KsefInvoiceSubmissionController::class, 'refresh'])->name('invoices.ksef.submissions.refresh');
Route::post('/invoices/{invoice}/ksef/submissions/{submission}/reconcile', [KsefInvoiceSubmissionController::class, 'reconcile'])->name('invoices.ksef.submissions.reconcile');
Route::post('/invoices/{invoice}/ksef/submissions/{submission}/upo', [KsefInvoiceSubmissionController::class, 'fetchUpo'])->name('invoices.ksef.submissions.upo.fetch');
Route::get('/invoices/{invoice}/ksef/submissions/{submission}/upo', [KsefInvoiceSubmissionController::class, 'downloadUpo'])->name('invoices.ksef.submissions.upo.download');
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
Route::get('/invoices/settings/series', [InvoiceSeriesController::class, 'index'])->name('invoices.series.index');
Route::get('/invoices/settings/series/form', [InvoiceSeriesController::class, 'form'])->name('invoices.series.form');
Route::post('/invoices/settings/series', [InvoiceSeriesController::class, 'store'])->name('invoices.series.store');
Route::get('/invoices/settings/series/{series}/edit', [InvoiceSeriesController::class, 'edit'])->name('invoices.series.edit');
Route::get('/invoices/settings/series/{series}/next-number', [InvoiceSeriesNextNumberController::class, 'show'])->name('invoices.series.next-number.form');
Route::get('/invoices/settings/series/{series}/next-number/preview', [InvoiceSeriesNextNumberController::class, 'preview'])->name('invoices.series.next-number.preview');
Route::post('/invoices/settings/series/{series}/next-number', [InvoiceSeriesNextNumberController::class, 'store'])->name('invoices.series.next-number.store');
Route::patch('/invoices/settings/series/{series}', [InvoiceSeriesController::class, 'update'])->name('invoices.series.update');
Route::patch('/invoices/settings/series/{series}/active', [InvoiceSeriesController::class, 'updateActive'])->name('invoices.series.active');
Route::delete('/invoices/settings/series/{series}', [InvoiceSeriesController::class, 'destroy'])->name('invoices.series.destroy');

Route::get('/orders', [OrdersController::class, 'index'])->name('orders.index');
Route::get('/orders/list-state', [OrdersController::class, 'listState'])->name('orders.list-state');
Route::get('/orders/scan', OrderScanController::class)->name('orders.scan');
Route::get('/automation/activity', [AutomationActivityController::class, 'index'])->name('automation.activity.index');
Route::get('/orders/automatic-actions', [AutomationRuleController::class, 'index'])->name('orders.automatic-actions.index');
Route::get('/orders/automatic-actions/create', [AutomationRuleController::class, 'create'])->name('orders.automatic-actions.create');
Route::post('/orders/automatic-actions', [AutomationRuleController::class, 'store'])->name('orders.automatic-actions.store');
Route::get('/orders/automatic-actions/{automationRule}/edit', [AutomationRuleController::class, 'edit'])->name('orders.automatic-actions.edit');
Route::put('/orders/automatic-actions/{automationRule}', [AutomationRuleController::class, 'update'])->name('orders.automatic-actions.update');
Route::patch('/orders/automatic-actions/{automationRule}/toggle', [AutomationRuleController::class, 'toggle'])->name('orders.automatic-actions.toggle');
Route::delete('/orders/automatic-actions/{automationRule}', [AutomationRuleController::class, 'destroy'])->name('orders.automatic-actions.destroy');
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
Route::get('/orders/{order}', [OrdersController::class, 'show'])->name('orders.show');
