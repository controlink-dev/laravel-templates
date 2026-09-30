<?php

namespace Controlink\Templates\Tests\Unit;

use Controlink\Templates\Support\PageGeometry;
use PHPUnit\Framework\TestCase;

class PageGeometryTest extends TestCase
{
    public function test_sheet_a4_from_array(): void
    {
        $g = PageGeometry::fromType(['paper_format' => 'A4', 'margins' => ['top' => 10, 'right' => 5, 'bottom' => 10, 'left' => 5]]);

        $this->assertSame('sheet', $g->medium);
        $this->assertSame('A4', $g->paperFormat);
        $this->assertSame(['top' => 10.0, 'right' => 5.0, 'bottom' => 10.0, 'left' => 5.0], $g->margins);
        $this->assertSame('fixed', $g->fit);
        $this->assertSame(['width' => 210.0, 'height' => 297.0], $g->sheetSizeMm());
    }

    public function test_empty_array_defaults_to_a4(): void
    {
        $g = PageGeometry::fromType([]);

        $this->assertSame('A4', $g->paperFormat);
        $this->assertSame(['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0], $g->margins);
        $this->assertSame(['width' => 210.0, 'height' => 297.0], $g->sheetSizeMm());
    }

    public function test_a5_and_unknown_format(): void
    {
        $this->assertSame(['width' => 148.0, 'height' => 210.0], PageGeometry::fromType(['paper_format' => 'A5'])->sheetSizeMm());
        $this->assertSame(['width' => 210.0, 'height' => 297.0], PageGeometry::fromType(['paper_format' => 'bogus;'])->sheetSizeMm());
    }

    public function test_roll_from_array(): void
    {
        $g = PageGeometry::fromType(['medium' => 'roll', 'width_mm' => 80, 'fit' => 'measure', 'max_height_mm' => 500]);

        $this->assertSame('roll', $g->medium);
        $this->assertNull($g->paperFormat);
        $this->assertSame(80.0, $g->widthMm);
        $this->assertSame('measure', $g->fit);
        $this->assertSame(500.0, $g->maxHeightMm);
        $this->assertSame(['width' => 80.0, 'height' => INF], $g->sheetSizeMm());
    }
}
