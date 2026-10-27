<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Output\EscPos\Receipt;
use Controlink\Templates\Output\EscPos\ReceiptBuilder;
use Controlink\Templates\Output\Pdf\DocumentComposer;
use Controlink\Templates\Output\Pdf\PageStructure;
use Controlink\Templates\Output\Pdf\PdfRenderer;
use Controlink\Templates\Output\Pdf\RenderedPdf;
use Controlink\Templates\Support\PageGeometry;
use Controlink\Templates\Templates;
use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Toolkit\Documents;
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
        $this->assertStringContainsString('.page hr,.page [data-tpl-type="rule"]{border:0;border-top:1px dashed #000;background:none;margin:0.5em 0}', $roll);
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
        // And the cells lose their padding (a 12ch column holds 12 characters) and sit on the
        // first line of their row, both as on ESC/POS.
        // And a block is in flow, so what follows the table prints below it.
        // And a code slot and an image are centred, as ESC/POS centres them (ESC a 1).
        // And the zones are in flow with no band height.
        $cells = '.page .block{position:static}.page .zone{position:static;height:auto}'
            .'table.lines th,table.lines td{padding:0;vertical-align:top}'
            .'.page [data-tpl-type="code-slot"],.page [data-tpl-type="image"]{display:block;margin-left:auto;margin-right:auto}';
        $css   = app(PageStructure::class)->rollWrapCss($html);
        $this->assertSame($cells.$rule, $css);

        // And the composed roll carries both, after the base cell rule (which they beat by
        // order); a sheet's composed document has neither.
        $composer = app(DocumentComposer::class);
        $roll     = $composer->compose($html, '', $this->carrier(self::ROLL_80), []);
        $this->assertStringContainsString($cells.$rule, $roll);
        $this->assertGreaterThan(strpos($roll, 'padding:0 1mm'), strpos($roll, $cells));
        $sheet = $composer->compose($html, '', $this->carrier(['paper_format' => 'A4']), []);
        $this->assertStringNotContainsString('nth-child', $sheet);
        $this->assertStringNotContainsString($cells, $sheet);
        $this->assertStringNotContainsString('position:static', $sheet);
        $this->assertStringNotContainsString('margin-left:auto', $sheet);
        $this->assertStringContainsString('padding:0 1mm', $sheet);
    }

    /**
     * The PDF and the ESC/POS receipt break a line table the same way (CGE-97): with no cell
     * padding, the 28-character description column holds 28 characters, and the row grows
     * to the wrapped lines instead of clipping them.
     */
    #[Group('render')]
    public function test_roll_pdf_wraps_a_description_where_escpos_does(): void
    {
        Http::allowStrayRequests();

        try {
            Http::timeout(3)->get(rtrim((string) config('templates.pdf.gotenberg.url'), '/').'/health');
        } catch (\Throwable) {
            $this->markTestSkipped('The PDF engine is not reachable.');
        }

        $columns = htmlspecialchars((string) json_encode([
            ['key' => 'designation', 'label' => 'Artigo', 'width' => '', 'format' => ''],
            ['key' => 'total', 'label' => 'Total', 'width' => '12ch', 'format' => 'money', 'align' => 'right'],
        ]), ENT_QUOTES);
        $html = '<body><div class="zone zone--body"><div class="flow" id="tpl-before">Antes</div>'
            .'<div class="block block--lines" data-tpl-type="line-table"'
            .' data-tpl-binding="lines" data-tpl-columns="'.$columns.'" id="tpl-lines"><table class="lines">'
            .'<thead><tr><th style="text-align:left">Artigo</th><th style="text-align:right;width:12ch">Total</th></tr></thead>'
            .'<tbody>{% for linha in lines %}<tr><td style="text-align:left">{{ linha.designation }}</td>'
            .'<td style="text-align:right">{{ linha.total }}</td></tr>{% endfor %}</tbody></table></div>'
            .'<div class="flow" id="tpl-after">Fim</div></div></body>';
        $dataset = ['lines' => [
            ['designation' => 'Chapa de aço galvanizado com duas faces', 'total' => '1.229.999,98'],
            ['designation' => 'Segundo', 'total' => '1,00'],
        ]];

        $type     = $this->carrier(self::ROLL_80);
        $composed = app(DocumentComposer::class)->compose($html, '', $type, $dataset);

        // ESC/POS: 28 + 12 columns.
        $receipt = (new ReceiptBuilder)->build($composed, $dataset, PageGeometry::fromType(self::ROLL_80));
        $this->assertSame([], $receipt->problems);
        $this->assertSame([
            'Antes'.str_repeat(' ', 35),
            'Artigo'.str_repeat(' ', 29).'Total',
            'Chapa de aço galvanizado com1.229.999,98',
            'duas faces'.str_repeat(' ', 30),
            'Segundo'.str_repeat(' ', 29).'1,00',
            'Fim'.str_repeat(' ', 37),
        ], explode("\n", $receipt->text()));

        $out = app(PdfRenderer::class)->render($composed, $type);

        RenderedPdf::open($out['pdf'], function (RenderedPdf $pdf): void {
            $words = [];

            foreach ($pdf->wordsOn(1) as $word) {
                $words[$word['text']] ??= $word;
            }

            // The PDF breaks after "com", as the receipt does, and the value is whole.
            $this->assertEqualsWithDelta($words['Chapa']['top'], $words['com']['top'], 0.5);
            $this->assertEqualsWithDelta($words['Chapa']['top'], $words['1.229.999,98']['top'], 0.5);
            $this->assertGreaterThan($words['com']['top'] + 1.0, $words['duas']['top']);

            // The row grew: the wrapped line sits above the next row instead of being clipped.
            $this->assertGreaterThan($words['duas']['top'] + 1.0, $words['Segundo']['top']);

            // The table is in flow (no absolute .block): below what precedes it, above what follows.
            $this->assertGreaterThan($words['Antes']['top'] + 1.0, $words['Artigo']['top']);
            $this->assertGreaterThan($words['Segundo']['top'] + 1.0, $words['Fim']['top']);
        });
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

    /**
     * Each case puts its hyphen at the 40-column edge (ReceiptBuilderTest pins the same
     * lines without the engine): where Chromium breaks after a hyphen, and where it does not.
     */
    private const HYPHEN_CASES = [
        'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx 4200-072 q',
        'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx ABC-12345 q',
        'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx 123-abcde q',
        'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx ab-12,00 q',
        'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx ab-(123 q',
        'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx )-abcde q',
        'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx -abcdefg q',
        'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx ã-12345 q',
        'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx )-12345 q',
        'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx .-12345 q',
        'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx ab-.1234 q',
        'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx -12,00 q',
    ];

    private function engineUp(): void
    {
        Http::allowStrayRequests();

        try {
            Http::timeout(3)->get(rtrim((string) config('templates.pdf.gotenberg.url'), '/').'/health');
        } catch (\Throwable) {
            $this->markTestSkipped('The PDF engine is not reachable.');
        }
    }

    /**
     * The PDF's lines on page 1, top to bottom: the words of one line joined by a space.
     *
     * @return list<string>
     */
    private function pdfLines(RenderedPdf $pdf): array
    {
        $lines = [];

        foreach ($pdf->wordsOn(1) as $word) {
            $lines[(string) round($word['top'], 1)][] = $word['text'];
        }

        ksort($lines, SORT_NUMERIC);

        return array_values(array_map(fn (array $words): string => implode(' ', $words), $lines));
    }

    /** @return list<string> the receipt's text lines, trailing padding removed */
    private function receiptLines(Receipt $receipt): array
    {
        return array_values(array_filter(
            array_map('rtrim', explode("\n", $receipt->text())),
            fn (string $line): bool => $line !== '',
        ));
    }

    /** The PDF breaks after a hyphen exactly where the receipt does (CGE-97). */
    #[Group('render')]
    public function test_roll_pdf_breaks_at_hyphens_where_escpos_does(): void
    {
        $this->engineUp();

        $html = '<body><div class="zone zone--body">';

        foreach (self::HYPHEN_CASES as $index => $text) {
            $html .= '<div class="flow" id="tpl-case-'.$index.'">'.$text.'</div>';
        }

        $html .= '</div></body>';

        $type     = $this->carrier(self::ROLL_80);
        $composed = app(DocumentComposer::class)->compose($html, '', $type, []);
        $receipt  = (new ReceiptBuilder)->build($composed, [], PageGeometry::fromType(self::ROLL_80));
        $expected = $this->receiptLines($receipt);

        $this->assertCount(24, $expected);

        $out = app(PdfRenderer::class)->render($composed, $type);

        RenderedPdf::open($out['pdf'], fn (RenderedPdf $pdf) => $this->assertSame($expected, $this->pdfLines($pdf)));
    }

    /**
     * The issuer address breaks at its postal code's hyphen in the PDF («4200-» / «072
     * Porto»): R-02 still finds it whole, and the receipt breaks it on the same lines.
     */
    #[Group('render')]
    public function test_a_legal_address_broken_at_its_postal_code_is_found(): void
    {
        $this->engineUp();

        $invoice = (require __DIR__.'/../Fixtures/types/invoice.php')['invoice'];
        $fields  = array_values(array_filter($invoice['bindings'], fn (array $b): bool => $b['key'] === 'emitente.morada'));
        $data    = ['emitente.morada' => $invoice['fixture']['emitente.morada']];
        $layout  = [
            'project_data' => [],
            'html'         => '<div class="page"><div class="zone zone--body" data-tpl-zone="body">'
                .'<div class="flow" data-tpl-type="data-field" data-tpl-binding="emitente.morada" data-tpl-format="" data-tpl-legal="1" id="tpl-emitente-morada">{{ emitente.morada }}</div>'
                .'</div></div>',
            'css' => '',
        ];
        $lines = [
            'Rua Doutor António Bernardino de',
            'Almeida, n.º 1234, 5.º Esquerdo, 4200-',
            '072 Porto',
        ];

        $documents = app(Documents::class);
        $pdf       = $documents->render($layout, $data, self::ROLL_80, '', $fields);

        $this->assertSame([], $documents->validateRender($pdf, $fields, $data, self::ROLL_80, 1.0, [], $layout));
        RenderedPdf::open($pdf, fn (RenderedPdf $rendered) => $this->assertSame($lines, $this->pdfLines($rendered)));

        $escPos = $documents->escPos($layout, $data, self::ROLL_80, '', $fields);
        $this->assertSame([], $escPos['problems']);
        $this->assertMatchesRegularExpression('#Esquerdo, 4200- *</pre><pre[^>]*>072 Porto#u', $escPos['preview']);
    }

    /**
     * A roll has no bands (CGE-97): its zones are in flow with no reserved height, so the
     * receipt starts at the top margin, as the printer starts it. A sheet keeps its bands.
     */
    public function test_roll_zones_are_in_flow(): void
    {
        $html     = '<body><div class="zone zone--body"><div class="flow">Olá</div></div></body>';
        $composer = app(DocumentComposer::class);

        $this->assertSame('.page .zone{position:static;height:auto}', PageStructure::ROLL_ZONES);
        $this->assertStringContainsString(PageStructure::ROLL_ZONES, $composer->compose($html, '', $this->carrier(self::ROLL_80), []));
        $this->assertStringNotContainsString(PageStructure::ROLL_ZONES, $composer->compose($html, '', $this->carrier(['paper_format' => 'A4']), []));
    }

    #[Group('render')]
    public function test_a_roll_pdf_starts_at_its_top_margin(): void
    {
        $this->engineUp();

        $type = $this->carrier(self::ROLL_80);
        $html = '<body><div class="page"><div class="zone zone--header"><div class="flow">Cabeçalho</div></div>'
            .'<div class="zone zone--body"><div class="flow">Corpo</div></div>'
            .'<div class="zone zone--footer"><div class="flow">Rodapé</div></div></div></body>';

        $out = app(PdfRenderer::class)->render(app(DocumentComposer::class)->compose($html, '', $type, []), $type);

        RenderedPdf::open($out['pdf'], function (RenderedPdf $pdf): void {
            $words = [];

            foreach ($pdf->wordsOn(1) as $word) {
                $words[$word['text']] = $word;
            }

            // The first line sits at the 3 mm top margin, not below a 35 mm header band.
            $this->assertLessThan(10.0, $words['Cabeçalho']['top']);

            // The zones follow each other line by line: header, body, footer.
            $this->assertEqualsWithDelta($words['Cabeçalho']['bottom'], $words['Corpo']['top'], 1.5);
            $this->assertEqualsWithDelta($words['Corpo']['bottom'], $words['Rodapé']['top'], 1.5);
        });
    }
}
