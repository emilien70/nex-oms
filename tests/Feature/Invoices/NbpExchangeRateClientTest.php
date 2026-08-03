<?php

namespace Tests\Feature\Invoices;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Invoices\Exceptions\InvoiceDomainException;
use Modules\Invoices\Services\NbpExchangeRateClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NbpExchangeRateClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['nbp.retries' => 0, 'nbp.retry_delay_ms' => 0]);
        Http::preventStrayRequests();
    }

    public function test_fetches_table_a_rate_from_bounded_range_and_selects_latest_prior_unsorted_record(): void
    {
        Http::fake(function (Request $request) {
            $this->assertStringContainsString('/A/EUR/2026-04-18/2026-07-19/', $request->url());
            $this->assertSame('xml', $request['format']);

            return Http::response($this->xml('A', 'EUR', [
                ['137/A/NBP/2026', '2026-07-17', '4.3420'],
                ['135/A/NBP/2026', '2026-07-15', '4.3128'],
                ['138/A/NBP/2026', '2026-07-20', '9.9999'],
                ['136/A/NBP/2026', '2026-07-16', '4.3000'],
            ]), 200, ['Content-Type' => 'application/xml']);
        });

        $rate = app(NbpExchangeRateClient::class)->fetch('EUR', 'A', '2026-07-20');

        $this->assertSame('NBP', $rate->source);
        $this->assertSame('137/A/NBP/2026', $rate->tableNumber);
        $this->assertSame('2026-07-17', $rate->effectiveDate);
        $this->assertSame('2026-07-20', $rate->referenceDate);
        $this->assertSame('4.3420', $rate->rate);
        Http::assertSentCount(1);
    }

    #[DataProvider('exactRates')]
    public function test_preserves_exact_mid_for_table_a_b_and_display_unit_currencies(
        string $currency,
        string $table,
        string $mid,
    ): void {
        Http::fake([
            '*' => Http::response($this->xml($table, $currency, [
                ["100/{$table}/NBP/2026", '2026-07-17', $mid],
            ])),
        ]);

        $rate = app(NbpExchangeRateClient::class)->fetch($currency, $table, '2026-07-20');

        $this->assertSame($mid, $rate->rate);
        $this->assertSame($table, $rate->tableType);
    }

    /** @return array<string, array{string, string, string}> */
    public static function exactRates(): array
    {
        return [
            'four decimal places' => ['EUR', 'A', '4.3128'],
            'trailing zero' => ['USD', 'A', '4.3420'],
            'six decimal places' => ['AFN', 'B', '0.011809'],
            'HUF direct Mid' => ['HUF', 'A', '0.010920'],
            'JPY direct Mid' => ['JPY', 'A', '0.027431'],
            'IDR direct Mid' => ['IDR', 'B', '0.000236'],
        ];
    }

    #[DataProvider('invalidXmlResponses')]
    public function test_rejects_invalid_structure_identity_and_missing_fields(string $xml): void
    {
        Http::fake(['*' => Http::response($xml)]);

        $this->expectDomainCode('invoice_exchange_rate_invalid_response', function (): void {
            app(NbpExchangeRateClient::class)->fetch('EUR', 'A', '2026-07-20');
        });
    }

    /** @return array<string, array{string}> */
    public static function invalidXmlResponses(): array
    {
        $validRate = '<Rate><No>1/A/NBP/2026</No><EffectiveDate>2026-07-17</EffectiveDate><Mid>4.3420</Mid></Rate>';

        return [
            'malformed xml' => ['<broken'],
            'missing code' => ["<ExchangeRatesSeries><Table>A</Table><Rates>{$validRate}</Rates></ExchangeRatesSeries>"],
            'other code' => ["<ExchangeRatesSeries><Table>A</Table><Code>USD</Code><Rates>{$validRate}</Rates></ExchangeRatesSeries>"],
            'other table' => ["<ExchangeRatesSeries><Table>B</Table><Code>EUR</Code><Rates>{$validRate}</Rates></ExchangeRatesSeries>"],
            'missing table number' => ['<ExchangeRatesSeries><Table>A</Table><Code>EUR</Code><Rates><Rate><EffectiveDate>2026-07-17</EffectiveDate><Mid>4.3420</Mid></Rate></Rates></ExchangeRatesSeries>'],
            'missing effective date' => ['<ExchangeRatesSeries><Table>A</Table><Code>EUR</Code><Rates><Rate><No>1/A/NBP/2026</No><Mid>4.3420</Mid></Rate></Rates></ExchangeRatesSeries>'],
            'missing mid' => ['<ExchangeRatesSeries><Table>A</Table><Code>EUR</Code><Rates><Rate><No>1/A/NBP/2026</No><EffectiveDate>2026-07-17</EffectiveDate></Rate></Rates></ExchangeRatesSeries>'],
        ];
    }

    #[DataProvider('invalidRates')]
    public function test_rejects_invalid_rate_values(string $mid): void
    {
        Http::fake(['*' => Http::response($this->xml('A', 'EUR', [['1/A/NBP/2026', '2026-07-17', $mid]]))]);

        $this->expectDomainCode('invoice_exchange_rate_invalid_response', function (): void {
            app(NbpExchangeRateClient::class)->fetch('EUR', 'A', '2026-07-20');
        });
    }

    /** @return array<string, array{string}> */
    public static function invalidRates(): array
    {
        return [
            'zero' => ['0'],
            'zero decimals' => ['0.0000'],
            'negative' => ['-1'],
            'comma' => ['4,1234'],
            'scientific notation' => ['1e-5'],
            'NaN' => ['NaN'],
            'INF' => ['INF'],
            'currency suffix' => ['4.20 PLN'],
            'empty' => [''],
        ];
    }

    public function test_rejects_equal_or_later_dates_when_no_prior_publication_exists(): void
    {
        Http::fake(['*' => Http::response($this->xml('A', 'EUR', [
            ['1/A/NBP/2026', '2026-07-20', '4.10'],
            ['2/A/NBP/2026', '2026-07-21', '4.20'],
        ]))]);

        $this->expectDomainCode('invoice_exchange_rate_not_found', function (): void {
            app(NbpExchangeRateClient::class)->fetch('EUR', 'A', '2026-07-20');
        });
    }

    public function test_rejects_publication_older_than_requested_historical_range(): void
    {
        Http::fake(['*' => Http::response($this->xml('A', 'EUR', [
            ['1/A/NBP/2026', '2026-04-17', '4.10'],
        ]))]);

        $this->expectDomainCode('invoice_exchange_rate_not_found', function (): void {
            app(NbpExchangeRateClient::class)->fetch('EUR', 'A', '2026-07-20');
        });
    }

    public function test_400_is_not_retried(): void
    {
        config(['nbp.retries' => 2]);
        Http::fake(['*' => Http::response('', 400)]);

        $this->expectDomainCode('invoice_exchange_rate_unavailable', function (): void {
            app(NbpExchangeRateClient::class)->fetch('EUR', 'A', '2026-07-20');
        });
        Http::assertSentCount(1);
    }

    public function test_404_is_not_retried_and_returns_not_found(): void
    {
        config(['nbp.retries' => 2]);
        Http::fake(['*' => Http::response('', 404)]);

        $this->expectDomainCode('invoice_exchange_rate_not_found', function (): void {
            app(NbpExchangeRateClient::class)->fetch('EUR', 'A', '2026-07-20');
        });
        Http::assertSentCount(1);
    }

    public function test_500_is_retried_and_returns_controlled_unavailable_error(): void
    {
        config(['nbp.retries' => 2]);
        Http::fake(['*' => Http::response('', 500)]);

        $this->expectDomainCode('invoice_exchange_rate_unavailable', function (): void {
            app(NbpExchangeRateClient::class)->fetch('EUR', 'A', '2026-07-20');
        });
        Http::assertSentCount(3);
    }

    public function test_connection_failure_returns_controlled_unavailable_error(): void
    {
        Http::fake(['*' => Http::failedConnection()]);

        $this->expectDomainCode('invoice_exchange_rate_unavailable', function (): void {
            app(NbpExchangeRateClient::class)->fetch('EUR', 'A', '2026-07-20');
        });
    }

    public function test_connection_failure_is_retried_and_can_recover(): void
    {
        config(['nbp.retries' => 1]);
        Http::fakeSequence()
            ->pushFailedConnection()
            ->push($this->xml('A', 'EUR', [['1/A/NBP/2026', '2026-07-17', '4.3420']]));

        $rate = app(NbpExchangeRateClient::class)->fetch('EUR', 'A', '2026-07-20');

        $this->assertSame('4.3420', $rate->rate);
        Http::assertSentCount(2);
    }

    private function expectDomainCode(string $code, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Nie zgłoszono błędu {$code}.");
        } catch (InvoiceDomainException $exception) {
            $this->assertSame($code, $exception->errorCode());
        }
    }

    /** @param array<int, array{string, string, string}> $rates */
    private function xml(string $table, string $currency, array $rates): string
    {
        $rows = implode('', array_map(
            static fn (array $rate): string => '<Rate><No>'.htmlspecialchars($rate[0], ENT_XML1)
                .'</No><EffectiveDate>'.$rate[1].'</EffectiveDate><Mid>'.$rate[2].'</Mid></Rate>',
            $rates,
        ));

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?><ExchangeRatesSeries><Table>{$table}</Table><Code>{$currency}</Code><Rates>{$rows}</Rates></ExchangeRatesSeries>";
    }
}
