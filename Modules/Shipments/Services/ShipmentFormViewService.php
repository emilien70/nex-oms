<?php

namespace Modules\Shipments\Services;

use App\Models\Order;
use DomainException;
use Modules\Integrations\AllegroShipping\Services\AllegroShippingProposalService;
use Modules\Integrations\DPD\Services\DpdServiceResolver;
use Modules\Integrations\InPost\Services\InPostCourierServiceResolver;
use Modules\Shipments\Models\CourierAccount;

class ShipmentFormViewService
{
    public function __construct(
        private readonly ShipmentFormDefaultsService $defaults,
        private readonly InPostCourierServiceResolver $courierServiceResolver,
        private readonly InPostCourierParcelTemplateService $parcelTemplates,
        private readonly DpdServiceResolver $dpdServiceResolver,
        private readonly AllegroShippingProposalService $allegroProposals,
    ) {}

    public function make(Order $order, CourierAccount $account): array
    {
        $fields = $account->provider === CourierAccount::PROVIDER_ALLEGRO_SHIPPING
            ? $this->allegroProposals->formData($order, $account)
            : $this->defaults->for($order, $account);
        $data = [
            'order' => $order,
            'account' => $account,
            'fields' => $fields,
            'parcelTemplates' => in_array($account->provider, [
                CourierAccount::PROVIDER_INPOST_COURIER,
                CourierAccount::PROVIDER_DPD,
                CourierAccount::PROVIDER_ALLEGRO_SHIPPING,
            ], true)
                ? $this->parcelTemplates->all($account)
                : [],
        ];

        $html = match ($account->provider) {
            CourierAccount::PROVIDER_INPOST_LOCKERS => view(
                'orders.shipments.forms.inpost-lockers',
                $data,
            )->render(),
            CourierAccount::PROVIDER_INPOST_COURIER => view(
                'orders.shipments.forms.inpost-courier',
                $data + ['serviceLabels' => $this->courierServiceResolver->serviceLabels()],
            )->render(),
            CourierAccount::PROVIDER_DPD => view(
                'orders.shipments.forms.dpd',
                $data + ['serviceLabels' => $this->dpdServiceResolver->serviceLabels()],
            )->render(),
            CourierAccount::PROVIDER_ALLEGRO_SHIPPING => view(
                'orders.shipments.forms.allegro-shipping',
                $data,
            )->render(),
            default => throw new DomainException('Wybrany kurier nie udostepnia formularza nadania.'),
        };

        return [
            'fields' => $fields,
            'html' => $html,
        ];
    }
}
