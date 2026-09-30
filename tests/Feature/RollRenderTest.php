<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Output\Pdf\DocumentComposer;
use Controlink\Templates\Output\Pdf\PdfRenderer;
use Controlink\Templates\Output\Pdf\RenderedPdf;
use Controlink\Templates\Templates;
use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Types\TemplateType;
use Illuminate\Support\Facades\Http;
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
