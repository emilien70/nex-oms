<?php

namespace Tests\Unit\MacroDroid;

use Modules\Integrations\MacroDroid\Services\SmsSegments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SmsSegmentsTest extends TestCase
{
    #[DataProvider('messages')]
    public function test_encoding_and_segment_boundaries(string $text, string $encoding, int $units, int $parts): void
    {
        $this->assertSame(compact('encoding', 'units', 'parts'), (new SmsSegments)->measure($text));
    }

    public static function messages(): array
    {
        return [
            ['Test', 'GSM-7', 4, 1], [str_repeat('a', 160), 'GSM-7', 160, 1],
            [str_repeat('a', 161), 'GSM-7', 161, 2], [str_repeat('a', 306), 'GSM-7', 306, 2],
            [str_repeat('a', 307), 'GSM-7', 307, 3], [str_repeat('^', 80), 'GSM-7', 160, 1],
            [str_repeat('^', 81), 'GSM-7', 162, 2], ['[]{}~\\|^€', 'GSM-7', 18, 1],
            [str_repeat('ą', 70), 'UTF-16', 70, 1], [str_repeat('ą', 71), 'UTF-16', 71, 2],
            [str_repeat('ą', 134), 'UTF-16', 134, 2], [str_repeat('ą', 135), 'UTF-16', 135, 3],
            [str_repeat('😀', 35), 'UTF-16', 70, 1], [str_repeat('😀', 36), 'UTF-16', 72, 2],
            ['@£$¥', 'GSM-7', 4, 1],
            [str_repeat('^', 153), 'GSM-7', 306, 3],
            [str_repeat('😀', 67), 'UTF-16', 134, 3],
        ];
    }
}
