<?php

namespace Tests\Feature\Invoices;

use Illuminate\Http\Request;
use Modules\Invoices\Support\InvoiceReturnContext;
use Tests\TestCase;

class InvoiceReturnContextTest extends TestCase
{
    public function test_list_context_keeps_only_supported_valid_filters(): void
    {
        $request = Request::create('/invoices', 'GET', [
            'series_id' => '12',
            'number' => '34',
            'month' => '8',
            'year' => '2026',
            'full_number' => 'BL 34/2026',
            'buyer' => 'Jan Kowalski',
            'company' => 'Przyklad sp. z o.o.',
            'tax_id' => '1234567890',
            'order_id' => '56',
            'total_from' => '-10.50',
            'total_to' => '200.00',
            'issue_from' => '2026-08-01',
            'issue_to' => '2026-08-31',
            'sale_from' => '2026-07-01',
            'sale_to' => '2026-07-31',
            'source' => 'manual',
            'currency' => 'eur',
            'sort' => 'gross',
            'direction' => 'asc',
            'per_page' => '100',
            'page' => '3',
            'unexpected' => 'remove-me',
            'return_to' => 'order',
            'return_query' => 'buyer=Other',
        ]);

        $context = InvoiceReturnContext::forList($request, InvoiceReturnContext::INVOICES);
        parse_str($context->query(), $query);

        $this->assertSame('invoices', $context->returnTo());
        $this->assertSame('EUR', $query['currency']);
        $this->assertSame('gross', $query['sort']);
        $this->assertSame('asc', $query['direction']);
        $this->assertSame('100', $query['per_page']);
        $this->assertSame('3', $query['page']);
        $this->assertArrayNotHasKey('unexpected', $query);
        $this->assertArrayNotHasKey('return_to', $query);
        $this->assertArrayNotHasKey('return_query', $query);
    }

    public function test_untrusted_return_query_cannot_change_the_internal_destination(): void
    {
        $context = InvoiceReturnContext::fromRequest(Request::create('/invoices/1/edit', 'GET', [
            'return_to' => 'invoices',
            'return_query' => http_build_query([
                'redirect' => 'https://evil.example/foo',
                'sort' => 'unsupported',
                'direction' => 'sideways',
                'per_page' => '999',
                'buyer' => 'ABC',
                'page' => '2',
            ]),
        ]));

        $this->assertSame(route('invoices.index', [
            'page' => 2,
            'buyer' => 'ABC',
        ]), $context->url(123));
        $this->assertStringNotContainsString('evil.example', $context->url(123));
    }

    public function test_malformed_context_falls_back_without_throwing_an_exception(): void
    {
        $externalUrl = InvoiceReturnContext::fromRequest(Request::create('/invoices/1/edit', 'GET', [
            'return_to' => 'invoices',
            'return_query' => 'https://evil.example/foo',
        ]));
        $arrayQuery = InvoiceReturnContext::fromRequest(Request::create('/invoices/1/edit', 'GET', [
            'return_to' => 'invoices',
            'return_query' => ['page' => 3],
        ]));
        $unknownTarget = InvoiceReturnContext::fromRequest(Request::create('/invoices/1/edit', 'GET', [
            'return_to' => 'https://evil.example/foo',
            'return_query' => 'page=3',
        ]));

        $this->assertSame(route('invoices.index'), $externalUrl->url(123));
        $this->assertSame(route('invoices.index'), $arrayQuery->url(123));
        $this->assertSame(route('orders.show', 123), $unknownTarget->url(123));
        $this->assertSame([], $unknownTarget->parameters());
    }
}
