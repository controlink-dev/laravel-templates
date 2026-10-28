<?php

namespace Controlink\Templates\Tests\Unit;

use Controlink\Templates\Codes\BarcodeFailure;
use Controlink\Templates\Codes\Code128;
use Controlink\Templates\Codes\Code128Fit;
use Controlink\Templates\Codes\CodeImageGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The in-package Code 128 subset B encoder (CGE-98), checked against the standard's table,
 * against a vector frozen from an independent encoder, and by decoding the bars the PDF's
 * image draws back to the content.
 */
class Code128Test extends TestCase
{
    public function test_known_patterns(): void
    {
        $this->assertCount(107, Code128::PATTERNS);
        $this->assertCount(107, array_unique(Code128::PATTERNS));
        $this->assertSame('212222', Code128::PATTERNS[0]);
        $this->assertSame('211214', Code128::PATTERNS[Code128::START_B]);
        $this->assertSame('2331112', Code128::PATTERNS[Code128::STOP]);

        foreach (Code128::PATTERNS as $value => $pattern) {
            $this->assertSame($value === Code128::STOP ? 13 : 11, array_sum(str_split($pattern)), "pattern {$value}");
        }
    }

    public function test_check_character_is_mod_103(): void
    {
        // 'A' = 33: (104 + 1 x 33) mod 103 = 34.
        $this->assertSame([104, 33, 34, 106], Code128::values('A'));
    }

    /**
     * Frozen from picqer/php-barcode-generator 3.2 (TypeCode128B) before it was removed:
     * 310 values, including all of printable ASCII, matched it bar for bar.
     */
    public function test_matches_a_vector_from_an_independent_encoder(): void
    {
        $this->assertSame(
            [2, 1, 1, 2, 1, 4, 1, 1, 1, 3, 2, 3, 2, 3, 1, 1, 3, 1, 2, 1, 3, 3, 1, 1, 1, 2, 2, 1, 3, 2, 1, 2, 3, 1, 2, 2, 1, 2, 3, 1, 2, 2, 1, 2, 3, 1, 2, 2, 1, 2, 3, 1, 2, 2, 1, 2, 3, 1, 2, 2, 1, 2, 3, 1, 2, 2, 1, 2, 3, 1, 2, 2, 1, 2, 3, 1, 2, 2, 1, 2, 3, 1, 2, 2, 2, 2, 1, 1, 3, 2, 2, 3, 1, 1, 3, 1, 2, 3, 3, 1, 1, 1, 2],
            Code128::widths('ART-0000000003'),
        );
    }

    public function test_module_sum_equals_the_fit_arithmetic(): void
    {
        foreach (['A', 'ART-0000000003', '5601234567890', '0123456789', 'a b ~'] as $content) {
            $this->assertSame(
                Code128Fit::modules($content),
                array_sum(Code128::widths($content)) + 2 * Code128Fit::QUIET_ZONE,
                $content,
            );
        }
    }

    public function test_refuses_what_subset_b_cannot_encode(): void
    {
        $this->expectException(BarcodeFailure::class);

        Code128::widths('AÇO-1');
    }

    public function test_the_rendered_barcode_decodes_back_to_its_content(): void
    {
        foreach (['ART-0000000003', '5601234567890', 'Ab-9 ~!'] as $content) {
            $html = (new CodeImageGenerator)->inject(
                '<img data-tpl-type="code-slot" data-tpl-binding="code" style="width:90mm;height:12mm">',
                ['code' => $content],
                ['code' => ['type' => 'barcode']],
            );

            $this->assertSame($content, $this->decodeSvg($html), $content);
        }
    }

    /**
     * Reads the bars back from the SVG (positions and widths in mm), turns them into module
     * runs of bars and spaces, and decodes them through the pattern table, checking start,
     * check character and stop.
     */
    private function decodeSvg(string $html): string
    {
        $this->assertSame(1, preg_match('/src="data:image\/svg\+xml;base64,([^"]+)"/', $html, $m));
        $svg = base64_decode($m[1]);

        $this->assertSame(1, preg_match('/<g fill="#000">(.*)<\/g>/', $svg, $g));
        preg_match_all('/<rect x="([\d.]+)" y="0" width="([\d.]+)"/', $g[1], $rects, PREG_SET_ORDER);

        // The narrowest bar is one module.
        $module = min(array_map(fn (array $r): float => (float) $r[2], $rects));
        $runs   = [];
        $end    = null;

        foreach ($rects as [, $x, $width]) {
            if ($end !== null) {
                $runs[] = (int) round(((float) $x - $end) / $module); // the space before this bar
            }

            $runs[] = (int) round((float) $width / $module);
            $end    = (float) $x + (float) $width;
        }

        return self::decodeRuns($runs);
    }

    /**
     * Decodes Code 128 subset B from its bar and space widths in modules, bar first,
     * without quiet zones, asserting the start, the check character and the stop.
     *
     * @param  list<int>  $runs
     */
    public static function decodeRuns(array $runs): string
    {
        $stop = array_slice($runs, -7);
        self::assertSame(Code128::PATTERNS[Code128::STOP], implode('', $stop));

        $lookup = array_flip(array_slice(Code128::PATTERNS, 0, 106, true));
        $values = array_map(
            fn (array $chunk): int => $lookup[implode('', $chunk)] ?? self::fail('unknown pattern '.implode('', $chunk)),
            array_chunk(array_slice($runs, 0, -7), 6),
        );

        self::assertSame(Code128::START_B, array_shift($values));
        $check = array_pop($values);

        $sum = Code128::START_B;
        foreach ($values as $i => $value) {
            $sum += ($i + 1) * $value;
        }
        self::assertSame($sum % 103, $check);

        return implode('', array_map(fn (int $v): string => chr($v + 32), $values));
    }
}
