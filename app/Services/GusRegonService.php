<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;

class GusRegonService
{
    private const ACTION_BASE = 'http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/';

    public function findCompanyByNip(string $nip): ?array
    {
        $nip = preg_replace('/\D+/', '', $nip);
        $sid = $this->login();

        try {
            return $this->searchByNip($sid, $nip);
        } finally {
            $this->logout($sid);
        }
    }

    private function login(): string
    {
        $apiKey = (string) config('services.gus.key');

        if ($apiKey === '') {
            throw new RuntimeException('Brak konfiguracji GUS_API_KEY.');
        }

        $body = $this->envelope(sprintf(
            '<ns:Zaloguj><ns:pKluczUzytkownika>%s</ns:pKluczUzytkownika></ns:Zaloguj>',
            e($apiKey)
        ));

        $sid = $this->extractSoapValue($this->request('Zaloguj', $body), 'ZalogujResult');

        if ($sid === '') {
            throw new RuntimeException('GUS nie zwrocil identyfikatora sesji.');
        }

        return $sid;
    }

    private function searchByNip(string $sid, string $nip): ?array
    {
        $body = $this->envelope(sprintf(
            '<ns:DaneSzukajPodmioty><ns:pParametryWyszukiwania><dat:Nip>%s</dat:Nip></ns:pParametryWyszukiwania></ns:DaneSzukajPodmioty>',
            e($nip)
        ), $sid);

        $result = $this->extractSoapValue($this->request('DaneSzukajPodmioty', $body, $sid), 'DaneSzukajPodmiotyResult');

        if (trim($result) === '') {
            return null;
        }

        $dataXml = @simplexml_load_string(html_entity_decode($result, ENT_QUOTES | ENT_XML1, 'UTF-8'));

        if (! $dataXml instanceof SimpleXMLElement) {
            return null;
        }

        $rows = $dataXml->xpath('//*[local-name()="dane"]');
        $row = $rows[0] ?? null;

        if (! $row instanceof SimpleXMLElement) {
            return null;
        }

        $value = function (array $names) use ($row): string {
            foreach ($names as $name) {
                $nodes = $row->xpath('./*[local-name()="'.$name.'"]');
                $value = trim((string) ($nodes[0] ?? ''));

                if ($value !== '') {
                    return $value;
                }
            }

            return '';
        };

        return [
            'nip' => $value(['Nip', 'nip']) ?: $nip,
            'regon' => $value(['Regon', 'regon']),
            'name' => $value(['Nazwa', 'nazwa']),
            'street' => $value(['Ulica', 'ulica']),
            'buildingNumber' => $value(['NrNieruchomosci', 'nrNieruchomosci']),
            'apartmentNumber' => $value(['NrLokalu', 'nrLokalu']),
            'postalCode' => $value(['KodPocztowy', 'kodPocztowy']),
            'city' => $value(['Miejscowosc', 'MiejscowoscPoczty', 'miejscowosc']),
            'country' => 'Polska',
        ];
    }

    private function logout(string $sid): void
    {
        if ($sid === '') {
            return;
        }

        $body = $this->envelope(sprintf(
            '<ns:Wyloguj><ns:pIdentyfikatorSesji>%s</ns:pIdentyfikatorSesji></ns:Wyloguj>',
            e($sid)
        ));

        try {
            $this->request('Wyloguj', $body, $sid);
        } catch (\Throwable) {
            // Wylogowanie z GUS nie powinno blokowac odpowiedzi dla uzytkownika.
        }
    }

    private function request(string $action, string $body, ?string $sid = null): string
    {
        $headers = [
            'Content-Type' => 'application/soap+xml; charset=utf-8; action="'.self::ACTION_BASE.$action.'"',
            'SOAPAction' => self::ACTION_BASE.$action,
        ];

        if ($sid) {
            $headers['sid'] = $sid;
        }

        $response = Http::timeout((int) config('services.gus.timeout', 10))
            ->withHeaders($headers)
            ->withBody($body, 'application/soap+xml; charset=utf-8')
            ->post((string) config('services.gus.url'));

        if (! $response->successful()) {
            throw new RuntimeException('GUS zwrocil blad HTTP '.$response->status().'.');
        }

        return $response->body();
    }

    private function envelope(string $body, ?string $sid = null): string
    {
        $header = $sid
            ? sprintf('<s:Header><ns:sid>%s</ns:sid></s:Header>', e($sid))
            : '<s:Header />';

        return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" xmlns:ns="http://CIS/BIR/PUBL/2014/07" xmlns:dat="http://CIS/BIR/PUBL/2014/07/DataContract">
    {$header}
    <s:Body>{$body}</s:Body>
</s:Envelope>
XML;
    }

    private function extractSoapValue(string $xml, string $element): string
    {
        $document = @simplexml_load_string($xml);

        if (! $document instanceof SimpleXMLElement) {
            throw new RuntimeException('GUS zwrocil niepoprawna odpowiedz XML.');
        }

        $nodes = $document->xpath('//*[local-name()="'.$element.'"]');

        return trim((string) ($nodes[0] ?? ''));
    }
}
