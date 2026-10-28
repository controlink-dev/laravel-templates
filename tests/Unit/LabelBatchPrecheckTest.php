<?php

namespace Controlink\Templates\Tests\Unit;

use Controlink\Templates\Codes\Code128Fit;
use Controlink\Templates\Output\Labels\LabelBatchFailed;
use Controlink\Templates\Output\Pdf\DocumentComposer;
use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Toolkit\Documents;
use Controlink\Templates\Types\TemplateType;
use InvalidArgumentException;

/**
 * The batch label pre-check (CGE-98): every dataset's barcode is checked before anything
 * renders, so a failing batch names every label that broke it and prints none.
 */
class LabelBatchPrecheckTest extends TestCase
{
    private const LABEL = [
        'medium'    => 'sheet',
        'width_mm'  => 100,
        'height_mm' => 50,
        'margins'   => ['top' => 3.0, 'right' => 3.0, 'bottom' => 3.0, 'left' => 3.0],
        'zones'     => ['header_height' => 0.0, 'footer_height' => 0.0],
    ];

    private function fields(): array
    {
        return [
            ['key' => 'article.name', 'label' => 'Name', 'type' => 'text'],
            ['key' => 'article.barcode', 'label' => 'Barcode', 'type' => 'barcode'],
            ['key' => 'article.qr', 'label' => 'QR', 'type' => 'qr'],
        ];
    }

    /** A label whose barcode slot is `$style` inline, with `$css` as the version CSS. */
    private function layout(string $style = 'top:20mm;left:0;width:40mm;height:12mm', string $css = ''): array
    {
        return [
            'html' => '<div class="page"><div class="zone zone--body" data-tpl-zone="body">'
                .'<div data-tpl-type="data-field" data-tpl-binding="article.name" id="tpl-name" class="block block--field">{{ article.name }}</div>'
                .'<img data-tpl-type="code-slot" data-tpl-binding="article.barcode" id="tpl-bc" class="block" style="'.$style.'">'
                .'<img data-tpl-type="code-slot" data-tpl-binding="article.qr" id="tpl-qr" class="block" style="top:0;left:60mm;width:20mm;height:20mm">'
                .'</div></div>',
            'css' => $css,
        ];
    }

    private function datasets(): array
    {
        return [
            ['article.name' => 'One', 'article.barcode' => 'ART-0000000003', 'article.qr' => 'x'],
            ['article.name' => 'Two', 'article.barcode' => 'A1', 'article.qr' => 'x'],
            ['article.name' => 'Three', 'article.barcode' => 'AÇO', 'article.qr' => 'x'],
        ];
    }

    private const EXPECTED = [
        ['index' => 0, 'content' => 'ART-0000000003', 'reason' => 'does_not_fit'],
        ['index' => 2, 'content' => 'AÇO', 'reason' => 'unencodable'],
    ];

    public function test_precheck_lists_every_failing_dataset(): void
    {
        $problems = app(Documents::class)->labelProblems($this->layout(), $this->datasets(), $this->fields(), self::LABEL);

        $this->assertSame(self::EXPECTED, $problems);
    }

    public function test_precheck_reads_the_width_from_the_version_css(): void
    {
        // The editor writes sizes into `#id{…}` rules (avoidInlineStyle), not inline.
        $layout = $this->layout('', '#tpl-bc{position:absolute;top:20mm;max-width:90mm;width:40mm;height:12mm}');

        $this->assertSame(self::EXPECTED, app(Documents::class)->labelProblems($layout, $this->datasets(), $this->fields(), self::LABEL));

        // Wide enough for every code: only the unencodable one is left.
        $wide = $this->layout('', '#tpl-bc{width:90mm;height:12mm}');

        $this->assertSame(
            [['index' => 2, 'content' => 'AÇO', 'reason' => 'unencodable']],
            app(Documents::class)->labelProblems($wide, $this->datasets(), $this->fields(), self::LABEL),
        );
    }

    public function test_precheck_passes_a_batch_that_fits(): void
    {
        $this->assertSame([], app(Documents::class)->labelProblems(
            $this->layout(),
            [['article.barcode' => 'A1'], ['article.barcode' => 'B2']],
            $this->fields(),
            self::LABEL,
        ));
    }

    public function test_labels_throws_with_every_failure_before_rendering(): void
    {
        try {
            app(Documents::class)->labels($this->layout(), $this->datasets(), self::LABEL, '', $this->fields());
            $this->fail('A batch with a failing barcode was rendered.');
        } catch (LabelBatchFailed $failed) {
            $this->assertSame(self::EXPECTED, $failed->failures);
        }
    }

    public function test_an_empty_or_missing_barcode_fails_the_batch(): void
    {
        try {
            app(Documents::class)->labels($this->layout(), [
                ['article.barcode' => 'A1'],
                ['article.barcode' => ''],
                ['article.name' => 'No barcode at all'],
            ], self::LABEL, '', $this->fields());
            $this->fail('A batch with an empty barcode was rendered.');
        } catch (LabelBatchFailed $failed) {
            $this->assertSame([
                ['index' => 1, 'content' => '', 'reason' => 'unencodable'],
                ['index' => 2, 'content' => '', 'reason' => 'unencodable'],
            ], $failed->failures);
        }
    }

    /**
     * A slot sized only by the version CSS: the pre-check and the composer read the same
     * box, so what passes the one composes in the other, at that box's whole-dot size.
     */
    public function test_precheck_and_compose_agree_on_a_slot_sized_by_the_version_css(): void
    {
        $layout = $this->layout('', '#tpl-bc{position:absolute;top:20mm;left:0;width:90mm;height:15mm}');

        // Fits 90 mm (4 dots per module) but not 30 mm: it must pass and compose.
        $fits = [['article.name' => 'Fits', 'article.barcode' => 'ART-00003', 'article.qr' => 'x']];

        $this->assertSame([], app(Documents::class)->labelProblems($layout, $fits, $this->fields(), self::LABEL));

        $html    = app(DocumentComposer::class)->composeMany($layout['html'], $layout['css'], $this->type(), $fits);
        $widthMm = Code128Fit::modules('ART-00003') * Code128Fit::moduleDots('ART-00003', 90) / 8;

        $this->assertStringContainsString('style="width:'.$widthMm.'mm;height:15mm"', $html);

        // Fits neither: the batch fails as a whole, before any BarcodeFailure can escape.
        $long = str_repeat('X', 40);

        try {
            app(Documents::class)->labels($layout, [...$fits, ['article.name' => 'Long', 'article.barcode' => $long, 'article.qr' => 'x']], self::LABEL, '', $this->fields());
            $this->fail('A barcode wider than its slot was rendered.');
        } catch (LabelBatchFailed $failed) {
            $this->assertSame([['index' => 1, 'content' => $long, 'reason' => 'does_not_fit']], $failed->failures);
        }
    }

    public function test_labels_refuses_a_label_that_spans_more_than_one_sheet(): void
    {
        // A line table too long for one 50 mm label would paginate: a label is one sheet,
        // and an extra page would misalign every label after it on the roll.
        $layout = ['html' => '<div class="page"><div class="zone zone--body" data-tpl-zone="body">'
            .'<div class="block block--lines" data-tpl-type="line-table" data-tpl-collection="lines" id="tpl-lines" style="top:2mm">'
            .'<table class="lines"><thead><tr><th>C</th></tr></thead><tbody>'
            .'{% for linha in lines %}<tr><td>{{ linha.c }}</td></tr>{% endfor %}'
            .'</tbody></table></div></div></div>', 'css' => ''];

        $fields = [['key' => 'lines', 'label' => 'Lines', 'type' => 'collection', 'columns' => [['key' => 'c', 'label' => 'C', 'type' => 'number']]]];
        $rows   = array_map(fn (int $i): array => ['c' => $i], range(1, 60));

        $this->expectException(InvalidArgumentException::class);

        app(Documents::class)->labels($layout, [['lines' => [['c' => 1]]], ['lines' => $rows]], self::LABEL, '', $fields);
    }

    private function type(): TemplateType
    {
        return TemplateType::fromConfig('x', [
            'output'         => 'pdf',
            'bindings'       => $this->fields(),
            'pdf'            => array_diff_key(self::LABEL, ['zones' => true]),
            'initial_layout' => ['zones' => self::LABEL['zones']],
        ]);
    }

    public function test_labels_refuses_a_non_label_geometry(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(Documents::class)->labels($this->layout(), [['article.barcode' => 'A1']], [
            'paper_format' => 'A4',
            'margins'      => ['top' => 15, 'right' => 15, 'bottom' => 15, 'left' => 15],
        ], '', $this->fields());
    }

    public function test_labels_refuses_a_roll(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(Documents::class)->labels($this->layout(), [['article.barcode' => 'A1']], [
            'medium' => 'roll', 'width_mm' => 80, 'fit' => 'measure',
        ], '', $this->fields());
    }

    public function test_labels_refuses_an_empty_list(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(Documents::class)->labels($this->layout(), [], self::LABEL, '', $this->fields());
    }
}
