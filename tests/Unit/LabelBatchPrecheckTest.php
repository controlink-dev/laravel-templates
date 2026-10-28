<?php

namespace Controlink\Templates\Tests\Unit;

use Controlink\Templates\Output\Labels\LabelBatchFailed;
use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Toolkit\Documents;
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
