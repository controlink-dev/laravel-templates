<?php

namespace Controlink\Templates\Tests\Unit;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Controlink\Templates\Codes\BarcodeFailure;
use Controlink\Templates\Codes\CodeImageGenerator;
use Controlink\Templates\Support\PageGeometry;
use Controlink\Templates\Tests\TestCase;

/**
 * Barcode slots, and QR slots on a label, are drawn in whole printer dots (8 per mm).
 */
class CodeImageGeneratorBarcodeTest extends TestCase
{
    private function slot(string $style): string
    {
        return '<img data-tpl-type="code-slot" data-tpl-binding="code" style="'.$style.'">';
    }

    private function decode(string $html): string
    {
        $this->assertSame(1, preg_match('/src="data:image\/svg\+xml;base64,([^"]+)"/', $html, $m));

        return base64_decode($m[1]);
    }

    private function label(): PageGeometry
    {
        return PageGeometry::fromType(['medium' => 'sheet', 'width_mm' => 80, 'height_mm' => 30]);
    }

    public function test_barcode_slot_renders_bars_in_whole_dots(): void
    {
        $html = (new CodeImageGenerator)->inject(
            $this->slot('width:80mm;height:15mm'),
            ['code' => 'ART-0000000003'],
            ['code' => ['type' => 'barcode']],
        );

        $svg = $this->decode($html);

        $this->assertStringContainsString('shape-rendering="crispEdges"', $svg);
        $this->assertSame((209 * 3 / 8).'mm', $this->attribute($svg, 'width'));
        $this->assertSame('15mm', $this->attribute($svg, 'height'));

        preg_match_all('/<rect[^>]*>/', $svg, $rects);
        $this->assertNotEmpty($rects[0]);

        foreach ($rects[0] as $rect) {
            $width = (float) $this->attribute($rect, 'width');
            $this->assertEqualsWithDelta(0.0, fmod($width * 8, 3), 1e-6, "bar width {$width} is not a whole number of 3-dot modules");
        }
    }

    public function test_barcode_bars_follow_code_128_b(): void
    {
        $svg = $this->decode((new CodeImageGenerator)->inject(
            $this->slot('width:80mm;height:15mm'),
            ['code' => 'A'],
            ['code' => ['type' => 'barcode']],
        ));

        // First bar starts after the 10-module quiet zone; Start B is 11 modules of
        // pattern 211214 (bar of 2 modules first).
        $modules = 11 * 3 + 13 + 20;
        $dots    = (int) floor(640 / $modules);

        // The first rect is the white background; the bars are inside the <g>.
        preg_match('/<g[^>]*>(<rect[^>]*>)/', $svg, $first);
        $first[0] = $first[1];
        $this->assertEqualsWithDelta(10 * $dots / 8, (float) $this->attribute($first[0], 'x'), 1e-6);
        $this->assertEqualsWithDelta(2 * $dots / 8, (float) $this->attribute($first[0], 'width'), 1e-6);
    }

    public function test_barcode_slot_with_unencodable_value_throws_barcode_failure(): void
    {
        $this->expectException(BarcodeFailure::class);

        (new CodeImageGenerator)->inject(
            $this->slot('width:80mm;height:15mm'),
            ['code' => 'AÃ‡O-1'],
            ['code' => ['type' => 'barcode']],
        );
    }

    public function test_barcode_slot_too_narrow_throws_barcode_failure(): void
    {
        $this->expectException(BarcodeFailure::class);

        (new CodeImageGenerator)->inject(
            $this->slot('width:40mm;height:15mm'),
            ['code' => 'ART-0000000003'],
            ['code' => ['type' => 'barcode']],
        );
    }

    public function test_qr_on_a_label_uses_whole_dot_modules(): void
    {
        $html = (new CodeImageGenerator)->inject(
            $this->slot('width:20mm;height:20mm'),
            ['code' => 'ART-0000000003'],
            ['code' => ['type' => 'qr']],
            $this->label(),
        );

        $this->decode($html);

        // Matrix modules plus the 4-module quiet zone each side, in whole dots.
        $total = Encoder::encode('ART-0000000003', ErrorCorrectionLevel::L(), 'utf-8')
            ->getMatrix()->getWidth() + 8;
        $dots = max(1, (int) floor(20 * 8 / $total));
        $mm   = rtrim(rtrim(number_format($total * $dots / 8, 4, '.', ''), '0'), '.');

        $this->assertStringContainsString("width:{$mm}mm;height:{$mm}mm", $html);
    }

    public function test_qr_without_geometry_is_unchanged(): void
    {
        $generator = new CodeImageGenerator;
        $html      = $this->slot('width:20mm;height:20mm');
        $bindings  = ['code' => ['type' => 'qr']];

        $this->assertSame(
            $generator->inject($html, ['code' => 'X1'], $bindings),
            $generator->inject($html, ['code' => 'X1'], $bindings, null),
        );
    }

    private function attribute(string $tag, string $name): string
    {
        $this->assertSame(1, preg_match('/\s'.$name.'="([^"]*)"/', $tag, $m), "no {$name} attribute in {$tag}");

        return $m[1];
    }
}
