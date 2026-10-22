<?php

namespace Controlink\Templates\Tests\Unit;

use Controlink\Templates\Output\Pdf\DocumentComposer;
use Controlink\Templates\Support\PageGeometry;
use PHPUnit\Framework\TestCase;

/** The geometry CSS override emitted after print.css — named sheet, mm label, roll. */
class GeometryStylesTest extends TestCase
{
    public function test_named_sheet_restates_the_css_format(): void
    {
        $css = (new DocumentComposer)->geometryStyles(PageGeometry::fromType(['paper_format' => 'A4']));

        $this->assertStringContainsString('@page{size: A4;margin:0}', $css);
    }

    /**
     * CGE-56: the sheet box follows the format. print.css draws `.page` at A4, so an A5
     * paper carried an A4 box that spilled over several pages. `html .page` because the
     * saved layout CSS comes later and may carry a `.page{…}` rule of its own.
     */
    public function test_named_sheet_sizes_the_page_box_to_its_format(): void
    {
        foreach (['A4' => [210, 297], 'A5' => [148, 210], 'A3' => [297, 420]] as $format => [$width, $height]) {
            $css = (new DocumentComposer)->geometryStyles(PageGeometry::fromType(['paper_format' => $format]));

            $this->assertStringContainsString('@page{size: '.$format.';margin:0}', $css);
            $this->assertStringContainsString('html .page{width: '.$width.'mm;height: '.$height.'mm}', $css);
        }
    }

    public function test_label_sheet_in_mm_emits_a_fixed_box(): void
    {
        $css = (new DocumentComposer)->geometryStyles(PageGeometry::fromType([
            'medium' => 'sheet', 'width_mm' => 50, 'height_mm' => 30,
            'margins' => ['top' => 2, 'right' => 2, 'bottom' => 2, 'left' => 2],
        ]));

        $this->assertStringContainsString('@page{size: 50mm 30mm;margin:0}', $css);
        $this->assertStringContainsString('.page{width: 50mm;height: 30mm;', $css);
        // A fixed box clips, it does not grow or paginate; all four margins apply.
        $this->assertStringContainsString('overflow: hidden', $css);
        $this->assertStringContainsString('padding: 2mm 2mm 2mm 2mm', $css);
    }

    public function test_roll_sets_width_and_auto_height(): void
    {
        $css = (new DocumentComposer)->geometryStyles(PageGeometry::fromType([
            'medium' => 'roll', 'width_mm' => 80, 'fit' => 'measure',
            'margins' => ['top' => 3, 'right' => 3, 'bottom' => 3, 'left' => 3],
            'max_height_mm' => 4000,
        ]));

        $this->assertStringContainsString('@page{size: 80mm', $css);
        $this->assertStringContainsString('height: auto', $css);
    }
}
