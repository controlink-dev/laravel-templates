<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Output\Pdf\DocumentComposer;
use Controlink\Templates\Output\Pdf\PageStructure;
use Controlink\Templates\Output\Pdf\PdfRenderer;
use Controlink\Templates\Output\Pdf\RenderedPdf;
use Controlink\Templates\Support\PageGeometry;
use Controlink\Templates\Templates;
use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Types\TemplateType;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/** Roll (talao) render: one page, height measured. Needs the engine and poppler. */
class RollRenderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $finder = new ExecutableFinder;

        foreach (['pdfinfo', 'pdftoppm'] as $tool) {
            if (! $finder->find($tool)) {
                $this->markTestSkipped('poppler-utils not installed');
            }
        }
    }

    private function carrier(array $pdf): TemplateType
    {
        $base = Templates::type('invoice');

        return new TemplateType(
            $base->code, $base->label, $base->output, $base->seeded, $base->layoutRevision,
            $base->bindings, $base->nodes, $base->fixture, $base->fixtureRepeats,
            $base->initialLayout, $pdf, [],
        );
    }

    private const ROLL_80 = [
        'medium' => 'roll', 'width_mm' => 80, 'fit' => 'measure',
        'margins' => ['top' => 3, 'right' => 3, 'bottom' => 3, 'left' => 3],
        'max_height_mm' => 4000,
    ];

    public function test_roll_geometry_carries_its_column_grid(): void
    {
        $this->assertSame(40, PageGeometry::fromType(self::ROLL_80)->columns);
        $this->assertSame(32, PageGeometry::fromType(['width_mm' => 58] + self::ROLL_80)->columns);
        $this->assertSame(42, PageGeometry::fromType(['columns' => 42] + self::ROLL_80)->columns);
        $this->assertEqualsWithDelta(1.85, PageGeometry::fromType(self::ROLL_80)->charWidthMm(), 0.0001);
        $this->assertEqualsWithDelta(3.0833, PageGeometry::fromType(self::ROLL_80)->fontSizeMm(), 0.001);
        $this->assertNull(PageGeometry::fromType(['paper_format' => 'A4'])->columns);
    }

    public function test_roll_styles_set_the_monospace_grid_and_the_esc_vocabulary(): void
    {
        $composer = new DocumentComposer;
        $roll     = $composer->geometryStyles(PageGeometry::fromType(self::ROLL_80));

        $this->assertStringContainsString('html .page{font-family:"Liberation Mono","Courier New",monospace;font-size:3.083mm;line-height:1.25}', $roll);
        // The dashed rule is the roll's own: on a sheet a rule block is a filled box.
        $this->assertStringContainsString('.page hr,.page [data-tpl-type="rule"]{border:0;border-top:1px dashed #000;margin:0.5em 0}', $roll);
        $this->assertStringContainsString('font-size:2.708mm', $composer->geometryStyles(PageGeometry::fromType(['width_mm' => 58] + self::ROLL_80)));

        $print = (string) file_get_contents(__DIR__.'/../../resources/css/print.css');
        $this->assertStringContainsString('.esc-double{font-size:2em', $print);
        $this->assertStringContainsString('.esc-cell[data-tpl-cols="40"]{flex:0 0 40ch}', $print);
        $this->assertStringContainsString('.esc-cell[data-tpl-cols="48"]{flex:0 0 48ch}', $print);
        $this->assertStringNotContainsString('data-tpl-type="rule"', $print);

        // A sheet and a label keep the bytes they had before the roll grid existed.
        $this->assertSame(
            '@page{size: A4;margin:0}html .page{width: 210mm;height: 297mm}',
            $composer->geometryStyles(PageGeometry::fromType(['paper_format' => 'A4'])),
        );
        $this->assertSame(
            '@page{size: 50mm 30mm;margin:0}.page{width: 50mm;height: 30mm;overflow: hidden;box-sizing: border-box;padding: 2mm 2mm 2mm 2mm}',
            $composer->geometryStyles(PageGeometry::fromType([
                'medium' => 'sheet', 'width_mm' => 50, 'height_mm' => 30,
                'margins' => ['top' => 2, 'right' => 2, 'bottom' => 2, 'left' => 2],
            ])),
        );
    }

    public function test_roll_line_table_text_cells_wrap_on_words(): void
    {
        $columns = htmlspecialchars((string) json_encode([
            ['key' => 'description', 'label' => 'Designação', 'width' => '', 'format' => ''],
            ['key' => 'total', 'label' => 'Total', 'width' => '10ch', 'format' => 'money'],
        ]), ENT_QUOTES);
        $html = '<body><div class="zone zone--body"><table id="tpl-lines" class="lines" data-tpl-type="line-table"'
            .' data-tpl-collection="lines" data-tpl-columns="'.$columns.'"><tbody><tr>'
            .'<td>'.str_repeat('Chapa de aço galvanizado ', 6).'</td><td>1,00</td></tr></tbody></table></div></body>';
        $rule = '#tpl-lines td:nth-child(1){white-space:pre-line;overflow-wrap:anywhere;text-overflow:clip;line-height:1.25}';

        // The description (1st) breaks into lines on the grid; the money column (2nd) never does.
        $css = app(PageStructure::class)->rollWrapCss($html);
        $this->assertSame($rule, $css);

        // And the composed roll carries it; a sheet's composed document has no such rule.
        $composer = app(DocumentComposer::class);
        $this->assertStringContainsString($rule, $composer->compose($html, '', $this->carrier(self::ROLL_80), []));
        $this->assertStringNotContainsString('nth-child', $composer->compose($html, '', $this->carrier(['paper_format' => 'A4']), []));
    }

    #[Group('render')]
    public function test_roll_pdf_really_uses_the_monospace_grid(): void
    {
        Http::allowStrayRequests();

        try {
            Http::timeout(3)->get(rtrim((string) config('templates.pdf.gotenberg.url'), '/').'/health');
        } catch (\Throwable) {
            $this->markTestSkipped('The PDF engine is not reachable.');
        }

        $type = $this->carrier(self::ROLL_80);
        $html = '<body><div class="zone zone--body">'
            .'<div style="overflow-wrap:anywhere">'.str_repeat('0', 40).'</div>'
            .'<div style="overflow-wrap:anywhere">'.str_repeat('1', 41).'</div>'
            .'</div></body>';

        $out = app(PdfRenderer::class)->render(app(DocumentComposer::class)->compose($html, '', $type, []), $type);

        $path = tempnam(sys_get_temp_dir(), 'roll-').'.pdf';
        file_put_contents($path, $out['pdf']);
        $fonts = new Process(['pdffonts', $path]);
        $fonts->run();
        @unlink($path);
        $this->assertStringContainsString('LiberationMono', $fonts->getOutput());

        RenderedPdf::open($out['pdf'], function (RenderedPdf $pdf): void {
            $words = $pdf->wordsOn(1);
            $forty = array_values(array_filter($words, fn (array $w): bool => str_contains($w['text'], '0')));
            $ones  = array_values(array_filter($words, fn (array $w): bool => str_contains($w['text'], '1')));

            // Forty characters fill the 74 mm between the margins on one line.
            $this->assertCount(1, $forty);
            $this->assertSame(str_repeat('0', 40), $forty[0]['text']);
            $this->assertEqualsWithDelta(74.0, $forty[0]['right'] - $forty[0]['left'], 1.0);

            // Forty-one do not: the last one goes down a line.
            $this->assertCount(2, $ones);
            $this->assertGreaterThan($ones[0]['top'], $ones[1]['top']);
            $this->assertSame(41, strlen($ones[0]['text'].$ones[1]['text']));
        });
    }

    public function test_a_roll_renders_as_one_page_of_measured_height(): void
    {
        Http::allowStrayRequests();

        try {
            Http::timeout(3)->get(rtrim((string) config('templates.pdf.gotenberg.url'), '/').'/health');
        } catch (\Throwable) {
            $this->markTestSkipped('The PDF engine is not reachable.');
        }

        $type = $this->carrier([
            'medium' => 'roll', 'width_mm' => 80, 'fit' => 'measure',
            'margins' => ['top' => 3, 'right' => 3, 'bottom' => 3, 'left' => 3],
            'max_height_mm' => 4000,
        ]);

        $html = '<body><div class="zone zone--body"><div style="height:250mm;background:#000"></div></div></body>';

        $document = app(DocumentComposer::class)->compose($html, '', $type, []);
        $out      = app(PdfRenderer::class)->render($document, $type);

        RenderedPdf::open($out['pdf'], fn (RenderedPdf $pdf) => $this->assertSame(1, $pdf->pageCount()));

        $path = tempnam(sys_get_temp_dir(), 'roll-').'.pdf';
        file_put_contents($path, $out['pdf']);
        $info = new Process(['pdfinfo', $path]);
        $info->run();
        @unlink($path);

        $this->assertSame(1, preg_match('/Page size:\s+([\d.]+) x ([\d.]+) pts/', $info->getOutput(), $m));
        $heightMm = (float) $m[2] / (72 / 25.4);
        fwrite(STDERR, "\nROLL_HEIGHT_MM=".round($heightMm, 2)."\n");

        $this->assertGreaterThan(250.0, $heightMm);
        $this->assertLessThan(500.0, $heightMm);
    }
}
