<?php

namespace Modules\Integrations\DPD\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Integrations\DPD\Exceptions\DpdApiException;
use Modules\Shipments\Models\CourierAccount;
use Modules\Shipments\Models\IntegrationApiLog;
use Modules\Shipments\Models\Shipment;
use Throwable;

class DpdInfoServicesClient
{
    public function latestEvent(CourierAccount $account, Shipment $shipment): ?array
    {
        if (blank($shipment->tracking_number)) {
            return null;
        }

        if (blank($account->resolvedInfoChannel())) {
            throw new DpdApiException('Brak kanalu DPD InfoServices w konfiguracji konta.');
        }

        $requestId = (string) Str::uuid();
        $url = $account->infoServicesUrl();
        $startedAt = hrtime(true);
        $response = null;
        $safeRequest = [
            'waybill' => $shipment->tracking_number,
            'events_select_type' => 'ONLY_LAST',
            'language' => 'PL',
            'login' => $account->resolvedApiLogin(),
            'channel' => $account->resolvedInfoChannel(),
        ];

        try {
            $response = Http::withBody($this->soapRequest($account, $shipment->tracking_number), 'text/xml; charset=utf-8')
                ->accept('text/xml')
                ->withHeaders([
                    'SOAPAction' => 'getEventsForWaybillV1',
                    'X-Request-ID' => $requestId,
                ])
                ->timeout((int) config('services.dpd.timeout', 25))
                ->post($url);

            if (! $response->successful()) {
                throw new DpdApiException('DPD InfoServices zwrocilo blad HTTP '.$response->status().'.', $response->status());
            }

            $event = $this->parseEvent($response->body());

            $this->writeLog(
                $account,
                $shipment,
                $requestId,
                $url,
                $safeRequest,
                $response->status(),
                $event,
                $startedAt,
                true,
                null,
            );

            return $event;
        } catch (DpdApiException $exception) {
            $this->writeLog(
                $account,
                $shipment,
                $requestId,
                $url,
                $safeRequest,
                $response?->status(),
                null,
                $startedAt,
                false,
                $exception->getMessage(),
            );

            throw $exception;
        } catch (Throwable $exception) {
            $this->writeLog(
                $account,
                $shipment,
                $requestId,
                $url,
                $safeRequest,
                $response?->status(),
                null,
                $startedAt,
                false,
                $exception->getMessage(),
            );

            throw new DpdApiException('Nie udalo sie pobrac statusu z DPD InfoServices: '.$exception->getMessage());
        }
    }

    private function soapRequest(CourierAccount $account, string $waybill): string
    {
        $xml = fn (mixed $value): string => htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" '
            .'xmlns:even="http://events.dpdinfoservices.dpd.com.pl/">'
            .'<soapenv:Header/><soapenv:Body><even:getEventsForWaybillV1>'
            .'<waybill>'.$xml($waybill).'</waybill>'
            .'<eventsSelectType>ONLY_LAST</eventsSelectType><language>PL</language>'
            .'<authDataV1><channel>'.$xml($account->resolvedInfoChannel()).'</channel>'
            .'<login>'.$xml($account->resolvedApiLogin()).'</login>'
            .'<password>'.$xml($account->resolvedApiToken()).'</password></authDataV1>'
            .'</even:getEventsForWaybillV1></soapenv:Body></soapenv:Envelope>';
    }

    private function parseEvent(string $xml): ?array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            if (! $document->loadXML($xml, LIBXML_NONET)) {
                throw new DpdApiException('DPD InfoServices zwrocilo nieprawidlowy dokument XML.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new DOMXPath($document);
        $fault = $xpath->query('//*[local-name()="Fault"]')->item(0);

        if ($fault) {
            throw new DpdApiException('DPD InfoServices: '.trim($fault->textContent));
        }

        $nodes = $xpath->query('//*[local-name()="eventsList"]');
        $node = $nodes?->item(max(0, ($nodes?->length ?? 1) - 1));

        if (! $node instanceof DOMElement) {
            return null;
        }

        $value = function (string $name) use ($xpath, $node): ?string {
            $item = $xpath->query('./*[local-name()="'.$name.'"]', $node)->item(0);
            $text = trim((string) $item?->textContent);

            return $text !== '' ? $text : null;
        };

        return [
            'business_code' => $value('businessCode'),
            'description' => $value('description'),
            'event_time' => $value('eventTime'),
            'waybill' => $value('waybill'),
            'depot' => $value('depot'),
            'country' => $value('country'),
        ];
    }

    private function writeLog(
        CourierAccount $account,
        Shipment $shipment,
        string $requestId,
        string $url,
        array $requestPayload,
        ?int $responseStatus,
        ?array $responsePayload,
        int $startedAt,
        bool $successful,
        ?string $errorMessage,
    ): void {
        IntegrationApiLog::query()->create([
            'integration' => CourierAccount::PROVIDER_DPD,
            'operation' => 'get_shipment',
            'order_id' => $shipment->order_id,
            'shipment_id' => $shipment->id,
            'request_id' => $requestId,
            'method' => 'POST',
            'url' => $url,
            'request_payload' => $requestPayload,
            'response_status' => $responseStatus,
            'response_payload' => $responsePayload,
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'successful' => $successful,
            'error_message' => $errorMessage,
        ]);
    }
}
