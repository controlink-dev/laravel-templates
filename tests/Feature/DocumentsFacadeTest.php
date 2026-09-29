<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Tests\Feature\Concerns\BuildsPdfLayouts;
use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Toolkit\Documents;
use Controlink\Templates\Types\TemplateType;
use Controlink\Templates\Versions\CanonicalHash;
use Symfony\Component\Process\ExecutableFinder;

class DocumentsFacadeTest extends TestCase
{
    use BuildsPdfLayouts;

    private function fields(): array
    {
        return [
            ['key' => 'documento.atcud', 'label' => 'ATCUD', 'type' => 'text', 'mandatory' => true, 'legal' => true, 'scope' => 'every_page'],
        ];
    }

    private function geometry(): array
    {
        return [
            'paper_format' => 'A4',
            'margins'      => ['top' => 15, 'right' => 15, 'bottom' => 15, 'left' => 15],
            'zones'        => ['header_height' => 40, 'footer_height' => 18],
        ];
    }

    private function type(): TemplateType
    {
        return TemplateType::fromConfig('x', ['bindings' => $this->fields()]);
    }

    public function test_it_resolves_from_the_container_as_a_singleton(): void
    {
        $this->assertSame(app(Documents::class), app(Documents::class));
    }

    public function test_input_hash_equals_canonical_hash(): void
    {
        $pd  = ['b' => 1, 'a' => ['y' => 2, 'x' => 3]];
        $geo = ['paper_format' => 'A5'];

        $documents = app(Documents::class);

        $this->assertSame(CanonicalHash::of($pd, $geo), $documents->inputHash($pd, $geo));
        $this->assertSame(CanonicalHash::of($pd), $documents->inputHash($pd));
        $this->assertNotSame($documents->inputHash($pd), $documents->inputHash($pd, $geo));
    }

    public function test_input_hash_without_geometry_is_unchanged_from_the_legacy_hash(): void
    {
        $this->assertSame(hash('sha256', '{"a":1}'), app(Documents::class)->inputHash(['a' => 1]));
    }

    public function test_validate_layout_reports_a_missing_legal_field(): void
    {
        $documents = app(Documents::class);

        $problems = $documents->validateLayout(
            $this->pdfLayoutWithout($this->type(), 'documento.atcud'),
            $this->fields(),
            $this->geometry(),
        );

        $this->assertContains('V-10', array_column($problems, 'code'));

        $this->assertSame([], $documents->validateLayout(
            $this->validPdfLayout($this->type()),
            $this->fields(),
            $this->geometry(),
        ));
    }

    public function test_render_returns_pdf_bytes_and_render_validation_flags_a_slow_render(): void
    {
        $finder = new ExecutableFinder;

        foreach (['pdfinfo', 'pdftotext', 'pdftoppm'] as $tool) {
            if (! $finder->find($tool)) {
                $this->markTestSkipped('poppler-utils not installed');
            }
        }

        $documents = app(Documents::class);
        $data      = ['documento.atcud' => 'JFT7-0000000001'];

        $pdf = $documents->render($this->validPdfLayout($this->type()), $data, $this->geometry(), '', $this->fields());

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame([], $documents->validateRender($pdf, $this->fields(), $data, $this->geometry(), 1.0));

        $problems = $documents->validateRender($pdf, $this->fields(), $data, $this->geometry(), 99.0);

        $this->assertSame(['R-07'], array_column($problems, 'code'));
    }
}
