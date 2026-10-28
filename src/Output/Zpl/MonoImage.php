<?php

namespace Controlink\Templates\Output\Zpl;

use GdImage;
use InvalidArgumentException;

/**
 * A label screenshot, one pixel per printer dot, read as the 1-bit image a ZPL `^GF`
 * takes (CGE-98).
 *
 * The colour is kept until the bits are asked for, because the code slots reach here as
 * boxes of a pure marker colour: they are found by that exact colour and painted over,
 * and only then is the picture reduced to black and white. Reduced first, a marker would
 * be just another black box.
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

        return self::fromGd($image, $threshold);
    }

    /**
     * Take over a GD image (it is drawn on, not copied).
     *
     * @param  int  $threshold  a pixel whose luminance (0-255, over white) is below it prints black
     */
    public static function fromGd(GdImage $image, int $threshold = 128): self
    {
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
     * Every marker on the image, in one pass: the pixels whose red and green are exactly
     * the given ones, grouped by their blue (the marker's number), each group as its
     * bounding box in dots and how many pixels it has.
     *
     * Exact: the screenshot draws an image at whole device pixels with no blending
     * (measured), so a marker comes back in its own colour, and a neighbouring marker
     * (one blue step away) is never taken for it. The count is what tells a solid box
     * from one with something drawn over it, or from content of the same colour.
     *
     * @return array<int, array{x: int, y: int, w: int, h: int, pixels: int}>  keyed by marker number
     */
    public function markers(int $red, int $green): array
    {
        $family = ($red << 16) | ($green << 8);
        $found  = [];

        for ($y = 0; $y < $this->height; $y++) {
            for ($x = 0; $x < $this->width; $x++) {
                $pixel = imagecolorat($this->image, $x, $y);

                if (($pixel & 0x7FFFFF00) !== $family) {
                    continue;
                }

                $marker = $pixel & 0xFF;

                if (! isset($found[$marker])) {
                    $found[$marker] = ['left' => $x, 'top' => $y, 'right' => $x, 'bottom' => $y, 'pixels' => 1];

                    continue;
                }

                $box = $found[$marker];

                $found[$marker] = [
                    'left'   => min($box['left'], $x),
                    'top'    => $box['top'],
                    'right'  => max($box['right'], $x),
                    'bottom' => $y,
                    'pixels' => $box['pixels'] + 1,
                ];
            }
        }

        return array_map(fn (array $box): array => [
            'x'      => $box['left'],
            'y'      => $box['top'],
            'w'      => $box['right'] - $box['left'] + 1,
            'h'      => $box['bottom'] - $box['top'] + 1,
            'pixels' => $box['pixels'],
        ], $found);
    }

    /**
     * Paint the box white, clipped to the image.
     *
     * Whatever overlaps it goes too: a code's box is reserved for the code, so anything
     * the layout draws over a barcode or a QR (a caption, a frame) is not printed there
     * in the ZPL, although the PDF shows it. A documented limitation.
     *
     * @param  array{x: int, y: int, w: int, h: int}  $box
     */
    public function paintWhite(array $box): void
    {
        $this->fill($box, imagecolorallocate($this->image, 255, 255, 255));
    }

    /** @param  array{x: int, y: int, w: int, h: int}  $box */
    public function paintBlack(array $box): void
    {
        $this->fill($box, imagecolorallocate($this->image, 0, 0, 0));
    }

    /** @param  array{x: int, y: int, w: int, h: int}  $box */
    private function fill(array $box, int $colour): void
    {
        imagefilledrectangle(
            $this->image,
            max(0, $box['x']),
            max(0, $box['y']),
            min($this->width - 1, $box['x'] + $box['w'] - 1),
            min($this->height - 1, $box['y'] + $box['h'] - 1),
            $colour,
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
