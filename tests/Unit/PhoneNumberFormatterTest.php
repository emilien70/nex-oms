<?php

namespace Tests\Unit;

use App\Support\PhoneNumberFormatter;
use PHPUnit\Framework\TestCase;

class PhoneNumberFormatterTest extends TestCase
{
    public function test_it_adds_polish_prefix_to_local_number(): void
    {
        $this->assertSame('+48 501 294 368', PhoneNumberFormatter::normalize('501294368'));
    }

    public function test_it_keeps_foreign_prefix_and_adds_plus_when_missing(): void
    {
        $this->assertSame('+49 501 294 368', PhoneNumberFormatter::normalize('49501294368'));
    }

    public function test_it_keeps_explicit_plus_prefix(): void
    {
        $this->assertSame('+49 501 294 368', PhoneNumberFormatter::normalize('+49 501 294 368'));
    }

    public function test_empty_phone_stays_null(): void
    {
        $this->assertNull(PhoneNumberFormatter::normalize(''));
    }
}
