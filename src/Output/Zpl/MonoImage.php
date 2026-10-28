<?php

namespace Controlink\Templates\Output\Zpl;

use GdImage;
use InvalidArgumentException;

/**
 * A label screenshot, one pixel per printer dot, read as the 1-bit image a ZPL `^GF`
 * takes (CGE-98).
 *
 * The colour is kept until the bits are asked for, because the barcode slots reach here
 * as boxes of a pure marker colour (`rgb(255,0,i)`): they are found by that exact colour
 * and painted white, and only then is the picture reduced to black and white. Reduced
 * first, a marker would be just another black box.
 */
final class MonoImage
{
    public readonly int $width;

    public readonly int $height;

    private function __construct(private readonly GdImage $image, private readonly int $threshold)
    {
        $this->width  = imagesx($image);
        $this->height = imagesy($image);
    }

    /**
     * @param  int  $threshold  a pixel whose luminance (0-255, over white) is below it prints black
     *
     * @throws InvalidArgumentException when the bytes are not an image
     */
    public static function fromPng(string $png, int $threshold = 128): self
    {
        $image = @imagecreatefromstring($png);

        if ($image === false) {
            throw new InvalidArgumentException('The label screenshot is not an image.');
        }

        // A palette image answers imagecolorat() with an index, not a colour.
        imagepalettetotruecolor($image);

        return new self($image, $threshold);
    }

    /**
     * Each row as packed bits, most significant first, 1 = black: what `^GF` prints. A
     * row is padded with white to a whole byte.
     *
     * @return list<string>
     */
    public function rows(): array
    {
        $rows = [];

        // Each colour is judged once: a label has few (white, black and the anti-aliased
        // greys of its text), and a call per dot is most of the time a batch takes.
        $black = [];

        for ($y = 0; $y < $this->height; $y++) {
            $row  = '';
            $byte = 0;

            for ($x = 0; $x < $this->width; $x++) {
                $pixel = imagecolorat($this->image, $x, $y);

                if ($black[$pixel] ??= $this->isBlack($pixel)) {
                    $byte |= 0x80 >> ($x & 7);
                }

                if (($x & 7) === 7) {
                    $row .= chr($byte);
                    $byte = 0;
                }
            }

            if (($this->width & 7) !== 0) {
                $row .= chr($byte);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The bounding box of the pixels of exactly this colour, in dots, or null if none.
     *
     * Exact: the screenshot draws an image at whole device pixels with no blending
     * (measured), so a marker comes back in its own colour, and a neighbouring slot's
     * marker (one blue step away) is never taken for it.
     *
     * @param  array{int, int, int}  $rgb
     * @return array{x: int, y: int, w: int, h: int}|null
     */
    public function findMarker(array $rgb): ?array
    {
        $colour = ($rgb[0] << 16) | ($rgb[1] << 8) | $rgb[2];
        $left   = $top = PHP_INT_MAX;
        $right  = $bottom = -1;

        for ($y = 0; $y < $this->height; $y++) {
            for ($x = 0; $x < $this->width; $x++) {
                if (imagecolorat($this->image, $x, $y) === $colour) {
                    $left   = min($left, $x);
                    $right  = max($right, $x);
                    $top    = min($top, $y);
                    $bottom = $y;
                }
            }
        }

        return $right < 0 ? null : ['x' => $left, 'y' => $top, 'w' => $right - $left + 1, 'h' => $bottom - $top + 1];
    }

    /** @param  array{x: int, y: int, w: int, h: int}  $box */
    public function paintWhite(array $box): void
    {
        imagefilledrectangle(
            $this->image,
            $box['x'],
            $box['y'],
            $box['x'] + $box['w'] - 1,
            $box['y'] + $box['h'] - 1,
            imagecolorallocate($this->image, 255, 255, 255),
        );
    }

    /** Luminance over white (a transparent pixel is paper) against the threshold. */
    private function isBlack(int $pixel): bool
    {
        $alpha = (($pixel >> 24) & 0x7F) / 127;
        $r     = ($pixel >> 16) & 0xFF;
        $g     = ($pixel >> 8) & 0xFF;
        $b     = $pixel & 0xFF;

        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) * (1 - $alpha) + 255 * $alpha;

        return $luminance < $this->threshold;
    }
}
