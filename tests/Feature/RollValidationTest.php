<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Tests\Feature\Concerns\BuildsPdfLayouts;
use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Toolkit\Documents;
use Controlink\Templates\Types\TemplateType;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\ExecutableFinder;

/** Roll-aware layer 2 validation (R-04 without a bottom, R-08 page size). */
class RollValidationTest extends TestCase
{
    use BuildsPdfLayouts;

    protected function setUp(): void
    {
        parent::setUp();

        $finder = new ExecutableFinder;

        foreach (['pdfinfo', 'pdftotext', 'pdftoppm'] as $tool) {
            if (! $finder->find($tool)) {
                $this->markTestSkipped('poppler-utils not installed');
            }
        }

        Http::allowStrayRequests();

        try {
            Http::timeout(3)->get(rtrim((string) config('templates.pdf.gotenberg.url'), '/').'/health');
        } catch (\Throwable) {
            $this->markTestSkipped('The PDF engine is not reachable.');
        }
    }

    private function fields(): array
    {
        return [
            ['key' => 'documento.atcud', 'label' => 'ATCUD', 'type' => 'text', 'mandatory' => true, 'legal' => true, 'scope' => 'every_page'],
        ];
    }

    private function roll(): array
    {
        return [
            'medium' => 'roll', 'width_mm' => 80, 'fit' => 'measure', 'max_height_mm' => 4000,
            'margins' => ['top' => 3, 'right' => 3, 'bottom' => 3, 'left' => 3],
        ];
    }

    private function rollLayout(): array
    {
        return ['html' => '<body><div class="zone zone--body"><p style="font-size:10pt">JFT7-0000000001</p><div style="height:30mm;background:#000"></div></div></body>', 'css' => ''];
    }

    private function a4(): array
    {
        return [
            'paper_format' => 'A4',
            'margins'      => ['top' => 15, 'right' => 15, 'bottom' => 15, 'left' => 15],
            'zones'        => ['header_height' => 40, 'footer_height' => 18],
        ];
    }

    public function test_a_well_placed_roll_render_passes_validation(): void
    {
        $documents = app(Documents::class);
        $data      = ['documento.atcud' => 'JFT7-0000000001'];
        $type      = TemplateType::fromConfig('x', ['bindings' => $this->fields()]);

        $pdf = $documents->render($this->rollLayout(), $data, $this->roll(), '', $this->fields());

        $this->assertSame([], $documents->validateRender($pdf, $this->fields(), $data, $this->roll(), 1.0));
    }

    public function test_r08_fires_when_the_pdf_size_differs_from_the_geometry(): void
    {
        $documents = app(Documents::class);
        $data      = ['documento.atcud' => 'JFT7-0000000001'];
        $type      = TemplateType::fromConfig('x', ['bindings' => $this->fields()]);

        $a4Pdf = $documents->render($this->validPdfLayout($type), $data, $this->a4(), '', $this->fields());

        $problems = $documents->validateRender($a4Pdf, $this->fields(), $data, $this->roll(), 1.0);

        $this->assertContains('R-08', array_column($problems, 'code'));
        $this->assertSame([], array_filter($documents->validateRender($a4Pdf, $this->fields(), $data, $this->a4(), 1.0), fn ($p) => $p['code'] === 'R-08'));
    }

    public function test_a_tall_roll_has_no_bottom_margin_to_fall_off(): void
    {
        $documents = app(Documents::class);
        $data      = ['documento.atcud' => 'JFT7-0000000001'];
        $layout    = ['html' => '<body><div class="zone zone--body"><div style="height:310mm;background:#000"></div><p style="font-size:10pt">JFT7-0000000001</p></div></body>', 'css' => ''];

        $pdf = $documents->render($layout, $data, $this->roll(), '', $this->fields());

        $this->assertSame([], $documents->validateRender($pdf, $this->fields(), $data, $this->roll(), 1.0));
    }
}