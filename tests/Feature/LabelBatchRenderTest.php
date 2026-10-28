<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Codes\Code128Fit;
use Controlink\Templates\Output\Pdf\RenderedPdf;
use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Tests\Unit\Code128Test;
use Controlink\Templates\Toolkit\Documents;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\ExecutableFinder;

/**
 * A batch of labels (CGE-98): one PDF, one page per label, in the order asked.
 *
 * The page count is the whole point: on a roll of labels an extra page shifts every label
 * after it, so an overflowing label must be cut at its edges, never spill onto a new page.
 * Needs the engine and poppler.
 */
#[Group('render')]
class LabelBatchRenderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $finder = new ExecutableFinder;

        foreach (['pdfinfo', 'pdftotext'] as $tool) {
            if (! $finder->find($tool)) {
                $this->markTestSkipped('poppler-utils not installed');
            }
        }
    }

    /** The host's article label (100x50 mm), name and code only. */
    private function layout(): array
    {
        return [
            'html' => '<div class="page"><div class="zone zone--body" data-tpl-zone="body">'
                .'<div data-tpl-type="data-field" data-tpl-binding="article.name" data-tpl-format="" data-tpl-legal="0" data-tpl-wrap="1" id="tpl-article-label-name" class="block block--field">{{ article.name }}</div>'
                .'<div data-tpl-type="data-field" data-tpl-binding="article.code" data-tpl-format="" data-tpl-legal="0" id="tpl-article-label-code" class="block block--field">{{ article.code }}</div>'
                .'</div></div>',
            'css' => '.page{color:#000;font-family:sans-serif}'
                .'#tpl-article-label-name{position:absolute;left:0;top:0;width:94mm;height:17mm;font-size:12pt;font-weight:700;line-height:1.15}'
                .'#tpl-article-label-code{position:absolute;left:0;top:20mm;width:94mm;font-size:10pt;color:#000}',
        ];
    }

    private function fields(): array
    {
        return [
            ['key' => 'article.name', 'label' => 'Name', 'type' => 'text'],
            ['key' => 'article.code', 'label' => 'Code', 'type' => 'text'],
        ];
    }

    private function geometry(): array
    {
        return [
            'medium'    => 'sheet',
            'width_mm'  => 100,
            'height_mm' => 50,
            'label'     => true,
            'margins'   => ['top' => 3.0, 'right' => 3.0, 'bottom' => 3.0, 'left' => 3.0],
            'max_pages' => 1,
            'zones'     => ['header_height' => 0.0, 'footer_height' => 0.0],
        ];
    }

    public function test_three_datasets_give_three_pages_in_order(): void
    {
        $pdf = app(Documents::class)->labels($this->layout(), [
            ['article.name' => 'A', 'article.code' => '1001'],
            ['article.name' => 'B', 'article.code' => '1002'],
            ['article.name' => 'A', 'article.code' => '1003'],
        ], $this->geometry(), '', $this->fields());

        RenderedPdf::open($pdf, function (RenderedPdf $pdf): void {
            $this->assertSame(3, $pdf->pageCount());

            $this->assertStringContainsString('A', $pdf->textOn(1));
            $this->assertStringContainsString('1001', $pdf->textOn(1));
            $this->assertStringContainsString('B', $pdf->textOn(2));
            $this->assertStringNotContainsString('A', $pdf->textOn(2));
            $this->assertStringContainsString('1002', $pdf->textOn(2));
            $this->assertStringContainsString('A', $pdf->textOn(3));
            $this->assertStringContainsString('1003', $pdf->textOn(3));

            $size = $pdf->pageSizeMm();
            $this->assertEqualsWithDelta(100.0, $size['width'], 0.5);
            $this->assertEqualsWithDelta(50.0, $size['height'], 0.5);
        });
    }

    public function test_a_barcode_sized_by_the_version_css_renders_one_page_per_label(): void
    {
        // 'ART-00003' fits the 90 mm `#id` box but not the old 30 mm inline-only fallback.
        $layout          = $this->layout();
        $layout['html']  = str_replace('</div></div></div>', '</div><img data-tpl-type="code-slot" data-tpl-binding="article.barcode" id="tpl-bc" class="block" alt=""></div></div>', $layout['html']);
        $layout['css']  .= '#tpl-bc{position:absolute;left:0;top:28mm;width:90mm;height:15mm}';
        $fields          = [...$this->fields(), ['key' => 'article.barcode', 'label' => 'Barcode', 'type' => 'barcode']];

        $pdf = app(Documents::class)->labels($layout, [
            ['article.name' => 'A', 'article.code' => '1001', 'article.barcode' => 'ART-00003'],
            ['article.name' => 'B', 'article.code' => '1002', 'article.barcode' => 'A1'],
        ], $this->geometry(), '', $fields);

        RenderedPdf::open($pdf, fn (RenderedPdf $pdf) => $this->assertSame(2, $pdf->pageCount()));
    }

    public function test_an_overflowing_name_stays_one_page(): void
    {
        // No height on the name: the block grows past the label's bottom edge, so only
        // the label's own clip keeps it from spilling onto a second page.
        $layout        = $this->layout();
        $layout['css'] = str_replace('height:17mm;', '', $layout['css']);

        $pdf = app(Documents::class)->labels($layout, [
            ['article.name' => str_repeat('Parafuso ', 66).'abcdef', 'article.code' => '1001'],
        ], $this->geometry(), '', $this->fields());

        $this->assertSame(600, mb_strlen(str_repeat('Parafuso ', 66).'abcdef'));

        RenderedPdf::open($pdf, fn (RenderedPdf $pdf) => $this->assertSame(1, $pdf->pageCount()));
    }
    /**
     * The barcode the PDF prints decodes back to the article's code: the page is rasterised
     * at two pixels per printer dot, the bars are read off one row through the middle of the
     * slot and decoded through the Code 128 table.
     */
    public function test_the_printed_barcode_decodes_back_to_its_content(): void
    {
        if (! (new ExecutableFinder)->find('pdftoppm')) {
            $this->markTestSkipped('pdftoppm not installed');
        }

        $layout          = $this->layout();
        $layout['html']  = str_replace('</div></div></div>', '</div><img data-tpl-type="code-slot" data-tpl-binding="article.barcode" id="tpl-bc" class="block" alt=""></div></div>', $layout['html']);
        $layout['css']  .= '#tpl-bc{position:absolute;left:0;top:28mm;width:90mm;height:15mm}';
        $fields          = [...$this->fields(), ['key' => 'article.barcode', 'label' => 'Barcode', 'type' => 'barcode']];

        foreach (['ART-00003', '5601234567890'] as $content) {
            $pdf = app(Documents::class)->labels($layout, [
                ['article.name' => 'A', 'article.code' => '1001', 'article.barcode' => $content],
            ], $this->geometry(), '', $fields);

            $runs = RenderedPdf::open($pdf, function (RenderedPdf $pdf): array {
                // Slot at 3 mm margin + 28 mm; one row through its middle, 406 dpi = 2 px a dot.
                $image = imagecreatefromstring($pdf->rasterOf(1, 406, ['left' => 0.0, 'top' => 38.0, 'width' => 100.0, 'height' => 1.0]));
                $y     = intdiv(imagesy($image), 2);
                $runs  = [];
                $dark  = null;

                for ($x = 0; $x < imagesx($image); $x++) {
                    $isDark = (imagecolorat($image, $x, $y) & 0xFF) < 128;

                    if ($dark === null) {
                        if ($isDark) {
                            [$dark, $runs] = [true, [1]];
                        }

                        continue;
                    }

                    if ($isDark === $dark) {
                        $runs[array_key_last($runs)]++;
                    } else {
                        [$dark, $runs[]] = [$isDark, 1];
                    }
                }

                // Drop the trailing white (quiet zone and margin).
                if ($dark === false) {
                    array_pop($runs);
                }

                return $runs;
            });

            // The symbol without its quiet zones spans this many modules.
            $module = array_sum($runs) / (Code128Fit::modules($content) - 2 * Code128Fit::QUIET_ZONE);
            $this->assertSame($content, Code128Test::decodeRuns(array_map(fn (int $px): int => (int) round($px / $module), $runs)), $content);
        }
    }
}
