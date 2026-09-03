<?php

namespace Tests\Unit\Ksef;

use Modules\Ksef\Exceptions\KsefApiException;
use Modules\Ksef\Services\Fa3\KsefFa3IssueDateReader;
use Modules\Ksef\Services\Fa3\KsefFa3XmlBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KsefFa3IssueDateReaderTest extends TestCase
{
    public function test_it_reads_exactly_one_issue_date_using_the_fa3_namespace(): void
    {
        $xml = sprintf(
            '<fa:Faktura xmlns:fa="%s"><fa:Fa><fa:P_1>2026-09-03</fa:P_1></fa:Fa></fa:Faktura>',
            KsefFa3XmlBuilder::NAMESPACE,
        );

        $this->assertSame('2026-09-03', (new KsefFa3IssueDateReader)->read($xml));
    }

    #[DataProvider('invalidXmlCases')]
    public function test_it_fails_closed_without_exposing_invalid_xml(string $xml): void
    {
        try {
            (new KsefFa3IssueDateReader)->read($xml);
            $this->fail('Expected invalid FA(3) issue date error.');
        } catch (KsefApiException $exception) {
            $this->assertSame('ksef_online_submission_issue_date_invalid', $exception->safeCode);
            $this->assertStringNotContainsString('SECRET_XML_CONTENT', $exception->getMessage());
        }
    }

    public static function invalidXmlCases(): array
    {
        $namespace = KsefFa3XmlBuilder::NAMESPACE;

        return [
            'malformed XML' => ['<Faktura>SECRET_XML_CONTENT'],
            'wrong namespace' => ['<Faktura xmlns="urn:wrong"><Fa><P_1>2026-09-03</P_1></Fa></Faktura>'],
            'missing P_1' => [sprintf('<Faktura xmlns="%s"><Fa/></Faktura>', $namespace)],
            'ambiguous P_1' => [sprintf('<Faktura xmlns="%s"><Fa><P_1>2026-09-03</P_1><P_1>2026-09-03</P_1></Fa></Faktura>', $namespace)],
            'invalid calendar date' => [sprintf('<Faktura xmlns="%s"><Fa><P_1>2026-02-30</P_1></Fa></Faktura>', $namespace)],
            'DTD' => [sprintf('<!DOCTYPE Faktura [<!ENTITY secret "SECRET_XML_CONTENT">]><Faktura xmlns="%s"><Fa><P_1>2026-09-03</P_1></Fa></Faktura>', $namespace)],
        ];
    }
}
