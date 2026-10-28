<?php

namespace Controlink\Templates\Tests\Unit;

use Controlink\Templates\Output\Zpl\MonoImage;
use Controlink\Templates\Tests\TestCase;

/**
 * A label screenshot as the 1-bit image a ZPL `^GF` takes, and the marker boxes the
 * barcodes are found by (CGE-98).
 */
class MonoImageTest extends TestCase
{
    /** @param  callable(\GdImage): void  $draw */
    private function png(int $width, int $height, callable $draw): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        $draw($image);

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public function test_rows_are_packed_bits_with_black_as_one(): void
    {
        $png = $this->png(16, 2, function (\GdImage $image): void {
            imagefilledrectangle($image, 0, 0, 7, 0, imagecolorallocate($image, 0, 0, 0));
        });

        $mono = MonoImage::fromPng($png);

        $this->assertSame(16, $mono->width);
        $this->assertSame(2, $mono->height);
        $this->assertSame(["\xFF\x00", "\x00\x00"], $mono->rows());
    }

    public function test_a_width_not_multiple_of_eight_pads_the_row_with_white(): void
    {
        $png = $this->png(10, 1, function (\GdImage $image): void {
            imagefilledrectangle($image, 0, 0, 9, 0, imagecolorallocate($image, 0, 0, 0));
        });

        $this->assertSame(["\xFF\xC0"], MonoImage::fromPng($png)->rows());
    }

    public function test_the_threshold_splits_grey(): void
    {
        $png = $this->png(8, 1, function (\GdImage $image): void {
            imagesetpixel($image, 0, 0, imagecolorallocate($image, 100, 100, 100));
            imagesetpixel($image, 1, 0, imagecolorallocate($image, 200, 200, 200));
        });

        $this->assertSame(["\x80"], MonoImage::fromPng($png)->rows());
        $this->assertSame(["\x00"], MonoImage::fromPng($png, 90)->rows());
    }

    public function test_a_marker_is_found_by_its_exact_colour_and_painted_white(): void
    {
        $png = $this->png(16, 4, function (\GdImage $image): void {
            imagefilledrectangle($image, 5, 1, 7, 2, imagecolorallocate($image, 255, 0, 0));
            // Another slot's marker, one blue step away, is not this one.
            imagesetpixel($image, 12, 3, imagecolorallocate($image, 255, 0, 1));
        });

        $mono = MonoImage::fromPng($png);
        $box  = $mono->findMarker([255, 0, 0]);

        $this->assertEquals(['x' => 5, 'y' => 1, 'w' => 3, 'h' => 2], $box);
        // A marker thresholds dark, so before it is painted it would print.
        $this->assertNotSame("\x00\x00", $mono->rows()[1]);

        $mono->paintWhite($box);

        $this->assertNull($mono->findMarker([255, 0, 0]));
        $this->assertSame("\x00\x00", $mono->rows()[1]);
        $this->assertEquals(['x' => 12, 'y' => 3, 'w' => 1, 'h' => 1], $mono->findMarker([255, 0, 1]));
    }

    public function test_no_marker_is_null(): void
    {
        $this->assertNull(MonoImage::fromPng($this->png(4, 4, fn () => null))->findMarker([255, 0, 0]));
    }
}
