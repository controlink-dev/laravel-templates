<?php

namespace Controlink\Templates\Codes;

use Controlink\Templates\Output\Zpl\Dots;

/**
 * How a Code 128 barcode fits a slot, in whole printer dots.
 *
 * A 203 dpi head prints 8 dots per millimetre and cannot print a fraction of a dot, so a
 * bar that is 2.4 dots wide is printed as 2 or 3 depending on where it lands, and the
 * barcode stops scanning. The only safe module is a whole number of dots (at least 2),
 * and the barcode is as wide as that makes it. This is the one place that arithmetic
 * lives: the PDF image, the ZPL command and the pre-print check all ask here, so they
 * cannot disagree about whether a value fits.
 *
 * The symbol is subset B, forced (see `CodeImageGenerator`), so its length depends only
 * on the character count: start + data + check are 11 modules each, the stop is 13, plus
 * a 10-module quiet zone on each side.
 */
final class Code128Fit
{
    /** Printer resolution this arithmetic is for: 203 dpi, 8 dots per millimetre (`Dots`). */
    public const DOTS_PER_MM = Dots::PER_MM;

    /** Narrowest module a thermal head prints reliably. */
    public const MIN_MODULE_DOTS = 2;

    /** Quiet zone on each side, in modules. */
    public const QUIET_ZONE = 10;

    /** Modules of the whole symbol including both quiet zones. */
    public static function modules(string $content): int
    {
        return 11 * (strlen($content) + 2) + 13 + 2 * self::QUIET_ZONE;
    }

    /**
     * The largest whole number of dots per module with which the symbol still fits.
     *
     * @throws BarcodeFailure when even 2 dots per module is too wide
     */
    public static function moduleDots(string $content, float $widthMm): int
    {
        $dots = (int) floor($widthMm * self::DOTS_PER_MM / self::modules($content));

        if ($dots < self::MIN_MODULE_DOTS) {
            throw BarcodeFailure::doesNotFit($content, $widthMm);
        }

        return $dots;
    }

    /** The longest value that fits `$widthMm` at the minimum module width. */
    public static function maxChars(float $widthMm): int
    {
        $modules = $widthMm * self::DOTS_PER_MM / self::MIN_MODULE_DOTS;

        // Fixed part: start + check + stop and both quiet zones, i.e. everything that is
        // not a data character.
        $fixed = 11 * 2 + 13 + 2 * self::QUIET_ZONE;

        return max(0, (int) floor(($modules - $fixed) / 11));
    }

    /**
     * @throws BarcodeFailure unless the value is non-empty printable ASCII (32-126)
     */
    public static function assertEncodable(string $content): void
    {
        if ($content === '' || preg_match('/^[\x20-\x7E]+$/D', $content) !== 1) {
            throw BarcodeFailure::unencodable($content);
        }
    }
}
