<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Codes\Code128Fit;
use Controlink\Templates\Output\Labels\LabelBatchFailed;
use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Toolkit\Documents;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;

/**
 * A batch of labels as ZPL (CGE-98): per label, the screenshot as a 1-bit graphic and the
 * barcodes on top as native Code 128, at the place and size the PDF has them.
 *
 * The render tests need the engine; the refusals must not reach it at all.
 */
class ZplLabelsTest extends TestCase
{
    /** A 100x50 mm label with no margins: a 10 mm black square at 10,10 mm and a barcode slot. */
    private function layout(): array
    {
        return [
            'html' => '<div class="page"><div class="zone zone--body" data-tpl-zone="body">'
                .'<div id="tpl-square" class="block"></div>'
                .'<div data-tpl-type="data-field" data-tpl-binding="article.name" data-tpl-format="" data-tpl-legal="0" id="tpl-name" class="block block--field">{{ article.name }}</div>'
                .'<img data-tpl-type="code-slot" data-tpl-binding="article.barcode" id="tpl-bc" class="block" alt="">'
                .'</div></div>',
            'css' => '.page{color:#000;font-family:sans-serif}'
                .'#tpl-square{position:absolute;left:10mm;top:10mm;width:10mm;height:10mm;background:#000}'
                .'#tpl-name{position:absolute;left:30mm;top:10mm;width:60mm;font-size:12pt}'
                .'#tpl-bc{position:absolute;left:5mm;top:30mm;width:90mm;height:10mm}',
        ];
    }

    private function fields(): array
    {
        return [
            ['key' => 'article.name', 'label' => 'Name', 'type' => 'text'],
            ['key' => 'article.barcode', 'label' => 'Barcode', 'type' => 'barcode'],
        ];
    }

    private function geometry(): array
    {
        return [
            'medium'    => 'sheet',
            'width_mm'  => 100,
            'height_mm' => 50,
            'margins'   => ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0],
            'max_pages' => 1,
            'zones'     => ['header_height' => 0.0, 'footer_height' => 0.0],
        ];
    }

    /**
     * The label's graphic field decoded back to its rows of packed bits.
     *
     * @return list<string>
     */
    private function bitmap(string $label): array
    {
        $this->assertSame(1, preg_match('/\^GFA,(\d+),\d+,(\d+),:Z64:([A-Za-z0-9+\/=]+):[0-9A-F]{4}\^FS/', $label, $match));

        $bits = (string) gzuncompress((string) base64_decode($match[3], true));

        $this->assertSame((int) $match[1], strlen($bits));

        return str_split($bits, (int) $match[2]);
    }

    /** @param  list<string>  $rows */
    private function black(array $rows, int $x, int $y): bool
    {
        return (ord($rows[$y][$x >> 3]) & (0x80 >> ($x & 7))) !== 0;
    }

    #[Group('render')]
    public function test_three_datasets_give_three_labels_with_native_barcodes_where_the_pdf_has_them(): void
    {
        $zpl = app(Documents::class)->zpl($this->layout(), [
            ['article.name' => 'Parafuso', 'article.barcode' => 'ART-0000000001'],
            ['article.name' => 'Porca ção', 'article.barcode' => 'ART-0000000002'],
            ['article.name' => 'Parafuso', 'article.barcode' => 'ART-0000000001'],
        ], $this->geometry(), '', $this->fields());

        $this->assertSame(3, substr_count($zpl, '^XA'));

        $labels = explode("\n", $zpl);
        $this->assertCount(3, $labels);

        // 90 mm = 720 dots / 209 modules: 3 dots per module, as the PDF draws it. The
        // image starts at the slot (5 mm = 40 dots) and its first bar after the
        // 10-module quiet zone; the bars are as tall as the slot (10 mm = 80 dots).
        $module = Code128Fit::moduleDots('ART-0000000001', 90.0);
        $this->assertSame(3, $module);
        $x = 40 + Code128Fit::QUIET_ZONE * $module;

        foreach (['ART-0000000001', 'ART-0000000002', 'ART-0000000001'] as $index => $code) {
            $label = $labels[$index];

            $this->assertStringStartsWith('^XA^CI28^PW800^LL400', $label);
            $this->assertMatchesRegularExpression(
                '/\^FO('.($x - 1).'|'.$x.'|'.($x + 1).'),(239|240|241)\^BY3\^BCN,(79|80|81),N,N,N,N\^FD>:'.$code.'\^FS\^XZ$/',
                $label,
            );

            $rows = $this->bitmap($label);
            $this->assertCount(400, $rows);

            // The 10 mm square at 10,10 mm: dots 80..159 (one dot of slack at each edge).
            $this->assertTrue($this->black($rows, 85, 85));
            $this->assertTrue($this->black($rows, 155, 155));
            $this->assertTrue($this->black($rows, 120, 82));
            $this->assertFalse($this->black($rows, 77, 120));
            $this->assertFalse($this->black($rows, 162, 120));
            $this->assertFalse($this->black($rows, 120, 77));
            $this->assertFalse($this->black($rows, 120, 162));

            // The barcode's box is blank in the picture: the printer draws the bars.
            for ($y = 242; $y < 318; $y += 4) {
                for ($dx = 40; $dx < 40 + 209 * 3; $dx += 3) {
                    $this->assertFalse($this->black($rows, $dx, $y), "dot {$dx},{$y} of label {$index}");
                }
            }
        }
    }

    #[Group('render')]
    public function test_a_batch_over_one_screenshot_keeps_every_label_in_order(): void
    {
        // 30 labels per engine call: label 31 and 32 come from a second screenshot.
        $datasets = [];
        for ($i = 1; $i <= 32; $i++) {
            $datasets[] = ['article.name' => 'N'.$i, 'article.barcode' => sprintf('ART-%010d', $i)];
        }

        $labels = explode("\n", app(Documents::class)->zpl($this->layout(), $datasets, $this->geometry(), '', $this->fields()));

        $this->assertCount(32, $labels);

        foreach ($labels as $index => $label) {
            $this->assertStringContainsString(sprintf('^FD>:ART-%010d^FS', $index + 1), $label);

            $rows = $this->bitmap($label);
            $this->assertTrue($this->black($rows, 120, 120), "square of label {$index}");
            $this->assertFalse($this->black($rows, 120, 77), "above the square of label {$index}");
        }
    }

    public function test_a_barcode_that_does_not_fit_refuses_the_batch_before_the_engine(): void
    {
        Http::fake();

        try {
            app(Documents::class)->zpl($this->layout(), [
                ['article.name' => 'A', 'article.barcode' => 'ART-1'],
                ['article.name' => 'B', 'article.barcode' => str_repeat('X', 40)],
            ], $this->geometry(), '', $this->fields());

            $this->fail('A barcode wider than its slot must refuse the batch.');
        } catch (LabelBatchFailed $failed) {
            $this->assertSame([['index' => 1, 'content' => str_repeat('X', 40), 'reason' => 'does_not_fit']], $failed->failures);
        }

        Http::assertNothingSent();
    }

    public function test_a_page_sized_sheet_is_not_a_label(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(Documents::class)->zpl($this->layout(), [['article.barcode' => 'A1']], ['paper_format' => 'A4'], '', $this->fields());
    }

    public function test_no_dataset_is_no_batch(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(Documents::class)->zpl($this->layout(), [], $this->geometry(), '', $this->fields());
    }
}
