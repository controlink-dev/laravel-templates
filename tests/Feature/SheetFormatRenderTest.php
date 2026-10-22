<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Output\Pdf\RenderedPdf;
use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Toolkit\Documents;

/**
 * A sheet that is not A4 prints at its own size (CGE-56).
 *
 * The paper followed the format but the `.page` box stayed at print.css's A4: an A5
 * sheet spilled over several pages and whatever was anchored to the bottom right (the
 * totals) landed off where it was drawn. Through the real engine, because the defect
 * only exists in the rendered PDF.
 */
class SheetFormatRenderTest extends TestCase
{
    public function test_an_a5_sheet_is_one_a5_page_with_its_bottom_block_at_the_bottom(): void
    {
        $this->skipWithoutPdfEngine();

        $layout = [
            'project_data' => [],
            'html'         => '<div class="page">'
                .'<div class="zone zone--header" data-tpl-zone="header">'
                .'<div class="block" id="top-mark">TOPMARK</div></div>'
                .'<div class="zone zone--body" data-tpl-zone="body"></div>'
                .'<div class="zone zone--footer" data-tpl-zone="footer">'
                .'<div class="block" id="bottom-mark">BOTTOMMARK</div></div>'
                .'</div>',
            'css'          => '#top-mark{left:0;top:0;font-size:10pt}'
                .'#bottom-mark{right:0;bottom:0;font-size:10pt}',
        ];

        $geometry = [
            'paper_format' => 'A5',
            'margins'      => ['top' => 10, 'right' => 10, 'bottom' => 10, 'left' => 10],
        ];

        $pdf = app(Documents::class)->render($layout, [], $geometry);

        RenderedPdf::open($pdf, function (RenderedPdf $pdf): void {
            $this->assertSame(1, $pdf->pageCount(), 'The A5 sheet spilled over several pages.');

            $bottom = collect($pdf->wordsOn(1))->firstWhere('text', 'BOTTOMMARK');

            $this->assertNotNull($bottom, 'The bottom-anchored block is not on the page.');
            // A5 is 148 x 210 mm with 10 mm margins: the block ends at the bottom-right corner
            // of the printable area, not of an A4 box (which would put it off this page).
            $this->assertEqualsWithDelta(200.0, $bottom['bottom'], 3.0);
            $this->assertEqualsWithDelta(138.0, $bottom['right'], 3.0);
        });
    }
}
