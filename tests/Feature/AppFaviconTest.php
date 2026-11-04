<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppFaviconTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_uses_the_nex_printer_icon_with_a_new_cache_key(): void
    {
        $this->get('/')->assertOk()->assertSee(
            '<link rel="icon" type="image/x-icon" href="'.asset('favicon.ico').'?v=nex-1">',
            false,
        );
        $this->assertSame(
            hash_file('sha256', base_path('clients/NexPrinter.Windows/src/NexPrinter.App/Assets/NexPrinter.ico')),
            hash_file('sha256', public_path('favicon.ico')),
        );
    }

    public function test_favicon_contains_small_and_high_resolution_browser_icons(): void
    {
        $data = file_get_contents(public_path('favicon.ico'));
        $header = unpack('vreserved/vtype/vcount', substr($data, 0, 6));
        $this->assertSame(0, $header['reserved']);
        $this->assertSame(1, $header['type']);
        $sizes = [];
        for ($index = 0; $index < $header['count']; $index++) {
            $entry = unpack('Cwidth/Cheight', substr($data, 6 + 16 * $index, 2));
            $sizes[] = [$entry['width'] ?: 256, $entry['height'] ?: 256];
        }
        $this->assertContains([16, 16], $sizes);
        $this->assertContains([32, 32], $sizes);
        $this->assertContains([256, 256], $sizes);
    }
}
