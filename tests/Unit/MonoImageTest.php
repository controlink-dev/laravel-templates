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

    public function test_every_marker_is_found_in_one_pass_by_its_exact_colour(): void
    {
        $png = $this->png(16, 4, function (\GdImage $image): void {
            imagefilledrectangle($image, 5, 1, 7, 2, imagecolorallocate($image, 1, 254, 0));
            // Another slot's marker, one blue step away, is not this one.
            imagesetpixel($image, 12, 3, imagecolorallocate($image, 1, 254, 1));
            // Not of the family: a near miss in green.
            imagesetpixel($image, 0, 0, imagecolorallocate($image, 1, 253, 0));
        });

        $markers = MonoImage::fromPng($png)->markers(1, 254);

        $this->assertEquals([
            0 => ['x' => 5, 'y' => 1, 'w' => 3, 'h' => 2, 'pixels' => 6],
            1 => ['x' => 12, 'y' => 3, 'w' => 1, 'h' => 1, 'pixels' => 1],
        ], $markers);
    }

    public function test_a_box_with_something_over_it_counts_fewer_pixels_than_its_area(): void
    {
        $png = $this->png(8, 8, function (\GdImage $image): void {
            imagefilledrectangle($image, 1, 1, 6, 6, imagecolorallocate($image, 1, 254, 0));
            imagefilledrectangle($image, 3, 3, 4, 4, imagecolorallocate($image, 0, 0, 0));
        });

        $this->assertSame(32, MonoImage::fromPng($png)->markers(1, 254)[0]['pixels']);
    }

    public function test_painting_white_and_black_clipped_to_the_image(): void
    {
        $png = $this->png(16, 2, function (\GdImage $image): void {
            imagefilledrectangle($image, 0, 0, 15, 1, imagecolorallocate($image, 1, 254, 0));
        });

        $mono = MonoImage::fromPng($png);

        // This marker family thresholds light (luminance ~149): a dot of it left over at
        // an edge does not print.
        $this->assertSame(["\x00\x00", "\x00\x00"], $mono->rows());

        $mono->paintWhite(['x' => -1, 'y' => -1, 'w' => 18, 'h' => 4]);
        $mono->paintBlack(['x' => 8, 'y' => 1, 'w' => 20, 'h' => 5]);

        $this->assertSame([], $mono->markers(1, 254));
        $this->assertSame(["\x00\x00", "\x00\xFF"], $mono->rows());
    }

    public function test_no_marker_is_empty(): void
    {
        $this->assertSame([], MonoImage::fromPng($this->png(4, 4, fn () => null))->markers(1, 254));
    }

    public function test_from_gd_takes_the_image_without_encoding(): void
    {
        $image = imagecreatetruecolor(8, 1);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagesetpixel($image, 7, 0, imagecolorallocate($image, 0, 0, 0));

        $this->assertSame(["\x01"], MonoImage::fromGd($image)->rows());
    }
}
