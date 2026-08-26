<?php

namespace App\Services;

use App\Exceptions\GusRegonException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use SimpleXMLElement;

class GusRegonService
{
    private const DIAGNOSTIC_ACTION_BASE = 'http://CIS/BIR/2014/07/IUslugaBIR/';

    private const PUBLIC_ACTION_BASE = 'http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/';

    /** @return list<array<string, string>> */
    public function findCompaniesByNip(string $nip): array
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
            throw new GusRegonException(
                'gus_not_configured',
                'Integracja GUS nie jest skonfigurowana.',
                503,
            );
        }

        $actionUri = self::PUBLIC_ACTION_BASE.'Zaloguj';
        $body = $this->envelope(sprintf(
            '<ns:Zaloguj><ns:pKluczUzytkownika>%s</ns:pKluczUzytkownika></ns:Zaloguj>',
            e($apiKey)
        ), $actionUri);

        $sid = $this->extractSoapValue(
            $this->request($actionUri, $body),
            'ZalogujResult',
        );

        if (strlen($sid) !== 20) {
            throw new GusRegonException(
                'gus_authentication_failed',
                'GUS nie zaakceptował konfiguracji dostępu.',
            );
        }

        return $sid;
    }

    /** @return list<array<string, string>> */
    private function searchByNip(string $sid, string $nip): array
    {
        $actionUri = self::PUBLIC_ACTION_BASE.'DaneSzukajPodmioty';
        $body = $this->envelope(sprintf(
            '<ns:DaneSzukajPodmioty><ns:pParametryWyszukiwania><dat:Nip>%s</dat:Nip></ns:pParametryWyszukiwania></ns:DaneSzukajPodmioty>',
            e($nip)
        ), $actionUri);

        $result = $this->extractSoapValue(
            $this->request($actionUri, $body, $sid),
            'DaneSzukajPodmiotyResult',
        );

        if (trim($result) === '') {
            return $this->emptySearchResult($sid);
        }

        $dataXml = @simplexml_load_string($result, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);

        if (! $dataXml instanceof SimpleXMLElement) {
            throw new GusRegonException(
                'gus_response_invalid',
                'GUS zwrócił nieprawidłową odpowiedź.',
            );
        }

        $rows = $dataXml->xpath('//*[local-name()="dane"]');

        if (! is_array($rows) || $rows === []) {
            throw new GusRegonException(
                'gus_response_invalid',
                'GUS zwrócił nieprawidłową odpowiedź.',
            );
        }

        $companies = [];

        foreach ($rows as $row) {
            if (! $row instanceof SimpleXMLElement) {
                continue;
            }

            $errorCode = $this->rowValue($row, ['ErrorCode']);

            if ($errorCode !== '') {
                if ($errorCode === '4') {
                    continue;
                }

                throw new GusRegonException(
                    'gus_query_failed',
                    'GUS nie może teraz zwrócić danych firmy.',
                );
            }

            $name = $this->rowValue($row, ['Nazwa', 'nazwa']);

            if ($name === '') {
                throw new GusRegonException(
                    'gus_response_invalid',
                    'GUS zwrócił niekompletne dane firmy.',
                );
            }

            $companies[] = [
                'nip' => $this->rowValue($row, ['Nip', 'nip']) ?: $nip,
                'regon' => $this->rowValue($row, ['Regon', 'regon']),
                'name' => $name,
                'street' => $this->rowValue($row, ['Ulica', 'ulica']),
                'buildingNumber' => $this->rowValue($row, ['NrNieruchomosci', 'nrNieruchomosci']),
                'apartmentNumber' => $this->rowValue($row, ['NrLokalu', 'nrLokalu']),
                'postalCode' => $this->rowValue($row, ['KodPocztowy', 'kodPocztowy']),
                'city' => $this->rowValue($row, ['Miejscowosc', 'MiejscowoscPoczty', 'miejscowosc']),
                'province' => $this->rowValue($row, ['Wojewodztwo', 'wojewodztwo']),
                'type' => $this->rowValue($row, ['Typ', 'typ']),
                'siloId' => $this->rowValue($row, ['SilosID', 'silosID']),
                'endedAt' => $this->rowValue($row, ['DataZakonczeniaDzialalnosci', 'dataZakonczeniaDzialalnosci']),
                'countryCode' => 'PL',
            ];
        }

        return array_values(array_unique($companies, SORT_REGULAR));
    }

    private function logout(string $sid): void
    {
        if ($sid === '') {
            return;
        }

        $actionUri = self::PUBLIC_ACTION_BASE.'Wyloguj';
        $body = $this->envelope(sprintf(
            '<ns:Wyloguj><ns:pIdentyfikatorSesji>%s</ns:pIdentyfikatorSesji></ns:Wyloguj>',
            e($sid)
        ), $actionUri);

        try {
            $this->request($actionUri, $body, $sid);
        } catch (\Throwable) {
            // Wylogowanie z GUS nie powinno blokowac odpowiedzi dla uzytkownika.
        }
    }

    private function request(string $actionUri, string $body, ?string $sid = null): string
    {
        $headers = [
            'Content-Type' => 'application/soap+xml; charset=utf-8; action="'.$actionUri.'"',
            'SOAPAction' => $actionUri,
        ];

        if ($sid) {
            $headers['sid'] = $sid;
        }

        try {
            $response = Http::timeout((int) config('services.gus.timeout', 10))
                ->withHeaders($headers)
                ->withBody($body, 'application/soap+xml; charset=utf-8; action="'.$actionUri.'"')
                ->post((string) config('services.gus.url'));
        } catch (ConnectionException) {
            throw new GusRegonException(
                'gus_unavailable',
                'Nie udało się połączyć z GUS. Spróbuj ponownie później.',
            );
        }

        if (! $response->successful()) {
            throw new GusRegonException(
                'gus_http_error',
                'GUS nie może teraz obsłużyć zapytania.',
            );
        }

        return $this->soapResponseBody(
            $response->body(),
            (string) $response->header('Content-Type'),
        );
    }

    private function envelope(string $body, string $actionUri): string
    {
        $endpoint = (string) config('services.gus.url');
        $messageId = 'urn:uuid:'.Str::uuid();

        return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" xmlns:a="http://www.w3.org/2005/08/addressing" xmlns:ns="http://CIS/BIR/PUBL/2014/07" xmlns:dat="http://CIS/BIR/PUBL/2014/07/DataContract">
    <s:Header>
        <a:Action s:mustUnderstand="1">{$actionUri}</a:Action>
        <a:MessageID>{$messageId}</a:MessageID>
        <a:ReplyTo>
            <a:Address>http://www.w3.org/2005/08/addressing/anonymous</a:Address>
        </a:ReplyTo>
        <a:To s:mustUnderstand="1">{$endpoint}</a:To>
    </s:Header>
    <s:Body>{$body}</s:Body>
</s:Envelope>
XML;
    }

    private function soapResponseBody(string $body, string $contentType): string
    {
        if (! str_starts_with(strtolower($contentType), 'multipart/related')) {
            return $body;
        }

        preg_match('/boundary=(?:"([^"]+)"|([^;\s]+))/i', $contentType, $matches);
        $boundary = $matches[1] ?? $matches[2] ?? '';

        if ($boundary === '') {
            throw new GusRegonException(
                'gus_response_invalid',
                'GUS zwrócił nieprawidłową odpowiedź.',
            );
        }

        $parts = preg_split(
            '/(?:\r\n|\n)?--'.preg_quote($boundary, '/').'(?:--)?(?:\r\n|\n)?/',
            $body,
        );

        foreach ($parts ?: [] as $part) {
            $sections = preg_split('/\r?\n\r?\n/', ltrim($part, "\r\n"), 2);

            if (count($sections) !== 2) {
                continue;
            }

            [$headers, $payload] = $sections;
            $headers = strtolower($headers);

            if (str_contains($headers, 'application/xop+xml')
                || str_contains($headers, 'application/soap+xml')) {
                return trim($payload);
            }
        }

        throw new GusRegonException(
            'gus_response_invalid',
            'GUS zwrócił nieprawidłową odpowiedź.',
        );
    }

    private function extractSoapValue(string $xml, string $element): string
    {
        $document = @simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);

        if (! $document instanceof SimpleXMLElement) {
            throw new GusRegonException(
                'gus_response_invalid',
                'GUS zwrócił nieprawidłową odpowiedź.',
            );
        }

        $faults = $document->xpath('//*[local-name()="Fault"]');

        if (is_array($faults) && $faults !== []) {
            throw new GusRegonException(
                'gus_soap_fault',
                'GUS nie może teraz obsłużyć zapytania.',
            );
        }

        $nodes = $document->xpath('//*[local-name()="'.$element.'"]');

        return trim((string) ($nodes[0] ?? ''));
    }

    /** @param  list<string>  $names */
    private function rowValue(SimpleXMLElement $row, array $names): string
    {
        foreach ($names as $name) {
            $nodes = $row->xpath('./*[local-name()="'.$name.'"]');
            $value = trim((string) ($nodes[0] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /** @return list<array<string, string>> */
    private function emptySearchResult(string $sid): array
    {
        $actionUri = self::DIAGNOSTIC_ACTION_BASE.'GetValue';
        $body = $this->envelope(
            '<bir:GetValue xmlns:bir="http://CIS/BIR/2014/07"><bir:pNazwaParametru>KomunikatKod</bir:pNazwaParametru></bir:GetValue>',
            $actionUri,
        );
        $code = $this->extractSoapValue(
            $this->request($actionUri, $body, $sid),
            'GetValueResult',
        );

        if ($code === '4') {
            return [];
        }

        throw new GusRegonException(
            $code === '' || $code === '7' ? 'gus_session_expired' : 'gus_query_failed',
            'GUS nie może teraz zwrócić danych firmy.',
        );
    }
}
