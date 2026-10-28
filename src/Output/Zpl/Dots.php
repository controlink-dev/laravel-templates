<?php

namespace Controlink\Templates\Output\Zpl;

/**
 * The label printer's resolution (CGE-98): 203 dpi, i.e. 8 dots per millimetre.
 *
 * The one place it is written. The Code 128 arithmetic, the screenshot scale and the ZPL
 * sizes all read it here, so a 300 dpi printer one day is a change of this constant (and
 * of who chooses it), not of the translator.
 */
final class Dots
{
    public const PER_MM = 8;

    /** Millimetres as whole dots. */
    public static function fromMm(float $mm): int
    {
        return (int) round($mm * self::PER_MM);
    }
}
