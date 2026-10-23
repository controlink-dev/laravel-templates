<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Tests\Feature\Concerns\BuildsPdfLayouts;
use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Toolkit\Documents;
use Controlink\Templates\Types\TemplateType;
use Controlink\Templates\Versions\CanonicalHash;

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
        $this->skipWithoutPdfEngine();

        $documents = app(Documents::class);
        $data      = ['documento.atcud' => 'JFT7-0000000001'];

        $pdf = $documents->render($this->validPdfLayout($this->type()), $data, $this->geometry(), '', $this->fields());

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame([], $documents->validateRender($pdf, $this->fields(), $data, $this->geometry(), 1.0));

        $problems = $documents->validateRender($pdf, $this->fields(), $data, $this->geometry(), 99.0);

        $this->assertSame(['R-07'], array_column($problems, 'code'));
    }

    /**
     * A seeded version has no project data, only HTML: R-02 must still look for a date in
     * the pattern its field was placed with, read from the HTML's `data-tpl-format*`.
     */
    public function test_render_validation_reads_placed_formats_from_html_without_project_data(): void
    {
        $this->skipWithoutPdfEngine();

        $fields = [['key' => 'documento.data', 'label' => 'Data', 'type' => 'date', 'mandatory' => true, 'legal' => false, 'default_format' => 'date']];
        $data   = ['documento.data' => '2026-12-31'];
        $html   = '<div class="page"><div class="zone zone--header" data-tpl-zone="header">'
            .'<div class="block block--field" data-tpl-type="data-field" data-tpl-binding="documento.data" data-tpl-format="date" data-tpl-format-pattern="yyyy/MM/dd" id="tpl-date" style="top:0;left:0">'
            ."{{ documento.data|date('yyyy/MM/dd') }}</div></div>"
            .'<div class="zone zone--body" data-tpl-zone="body"></div><div class="zone zone--footer" data-tpl-zone="footer"></div></div>';
        $layout = ['html' => $html, 'css' => '', 'project_data' => []];

        $documents = app(Documents::class);
        $pdf       = $documents->render($layout, $data, $this->geometry(), '', $fields);

        $this->assertSame([], $documents->validateRender($pdf, $fields, $data, $this->geometry(), 1.0, [], $layout));

        // Without the layout, the date is looked for in the default format, and is not there.
        $this->assertContains('R-02', array_column($documents->validateRender($pdf, $fields, $data, $this->geometry(), 1.0), 'code'));
    }

    public function test_render_for_activation_returns_pdf_hash_and_seconds(): void
    {
        $this->skipWithoutPdfEngine();

        $result = app(Documents::class)->renderForActivation(
            $this->validPdfLayout($this->type()),
            ['documento.atcud' => 'JFT7-0000000001'],
            $this->geometry(),
            '',
            $this->fields(),
        );

        $this->assertStringStartsWith('%PDF', $result['pdf']);
        $this->assertSame(\Controlink\Templates\Output\Pdf\PdfHash::of($result['pdf']), $result['hash']);
        $this->assertNotSame('', $result['hash']);
        $this->assertIsFloat($result['seconds']);
        $this->assertGreaterThan(0.0, $result['seconds']);
    }

    public function test_code_slot_boxes_are_produced_from_the_facade(): void
    {
        $fields = [['key' => 'documento.qr', 'label' => 'QR', 'type' => 'qr', 'mandatory' => true]];
        $type   = TemplateType::fromConfig('x', ['bindings' => $fields]);

        $boxes = app(Documents::class)->codeSlotBoxes($this->validPdfLayout($type), $fields, $this->geometry());

        $this->assertArrayHasKey('documento.qr', $boxes);
        $this->assertGreaterThan(0, $boxes['documento.qr']['width']);
    }

    /* -----------------------------------------------------------------------
     * previewFormulas (CGE-37): the editor's live preview
     * -------------------------------------------------------------------- */

    private function formulaType(): TemplateType
    {
        return TemplateType::fromConfig('x', ['bindings' => [
            ['key' => 'totals.total', 'label' => 'Total', 'type' => 'money'],
            ['key' => 'customer.name', 'label' => 'Name', 'type' => 'text'],
            ['key' => 'lines', 'label' => 'Lines', 'type' => 'collection', 'columns' => [
                ['key' => 'quantity', 'label' => 'Qty', 'type' => 'number'],
                ['key' => 'price', 'label' => 'Price', 'type' => 'money'],
            ]],
        ]]);
    }

    private function formulaDataset(): array
    {
        return [
            'totals.total'  => '100',
            'customer.name' => 'Ana',
            'lines'         => [
                ['quantity' => 2, 'price' => '1.5'],
                ['quantity' => 3, 'price' => '4'],
            ],
        ];
    }

    public function test_preview_formulas_evaluates_each_item_against_the_dataset(): void
    {
        $results = app(Documents::class)->previewFormulas([
            ['source' => 'totals.total * 2'],
            ['source' => 'UPPER(customer.name) & "!"'],
            ['source' => 'SUM(lines.quantity)'],
        ], $this->formulaDataset(), $this->formulaType());

        $this->assertSame([
            ['value' => 200, 'error' => null],
            ['value' => 'ANA!', 'error' => null],
            ['value' => 5, 'error' => null],
        ], $results);
    }

    public function test_preview_formulas_evaluates_a_column_on_the_first_row_of_its_collection(): void
    {
        $results = app(Documents::class)->previewFormulas([
            ['source' => 'lines.quantity * lines.price', 'collection' => 'lines'],
            ['source' => 'lines.quantity', 'collection' => 'nothing'],
        ], $this->formulaDataset(), $this->formulaType());

        $this->assertSame(['value' => 3.0, 'error' => null], $results[0]);
        $this->assertSame('', $results[1]['value']);
        $this->assertNotNull($results[1]['error']);
    }

    public function test_preview_formulas_gives_an_empty_value_and_the_v19_message_for_an_invalid_formula(): void
    {
        $results = app(Documents::class)->previewFormulas([
            ['source' => '1 +'],
            ['source' => 'TODAY()'],
            ['source' => 'nothing.here'],
            ['source' => 'lines.quantity * 2'],
            ['source' => ''],
        ], $this->formulaDataset(), $this->formulaType());

        $this->assertSame(
            [
                __('templates::editor.validation.formula_syntax', ['formula' => __('templates::editor.formula.label')]),
                __('templates::editor.validation.formula_unknown_function', ['formula' => __('templates::editor.formula.label')]),
                __('templates::editor.validation.formula_unknown_reference', ['formula' => __('templates::editor.formula.label')]),
                __('templates::editor.validation.formula_scope', ['formula' => __('templates::editor.formula.label')]),
                __('templates::editor.validation.formula_without_source', ['formula' => __('templates::editor.formula.label')]),
            ],
            array_column($results, 'error'),
        );
        $this->assertSame(['', '', '', '', ''], array_column($results, 'value'));
    }

    public function test_preview_formulas_with_no_rows_in_the_collection_prints_empty(): void
    {
        $results = app(Documents::class)->previewFormulas(
            [['source' => 'lines.quantity', 'collection' => 'lines']],
            ['lines' => []],
            $this->formulaType(),
        );

        $this->assertSame([['value' => '', 'error' => null]], $results);
    }
}
