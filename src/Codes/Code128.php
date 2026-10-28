<?php

namespace Controlink\Templates\Codes;

/**
 * Code 128 subset B, encoded here rather than through a library (CGE-98).
 *
 * Subset B only, always: every printable ASCII character (32-126) is one symbol character,
 * so the symbol's length depends on the character count alone — the arithmetic
 * `Code128Fit` and the ZPL path share. The symbol is Start B, one character per byte, the
 * mod-103 check character and the stop pattern.
 *
 * Each pattern is the widths, in modules, of its alternating bars and spaces, bar first:
 * six elements (11 modules) for a symbol character, seven (13 modules) for the stop.
 */
final class Code128
{
    /** Start B. */
    public const START_B = 104;

    /** The stop pattern, value 106. */
    public const STOP = 106;

    /**
     * Patterns by value: 0-102 data, 103-105 Start A/B/C, 106 stop (ISO/IEC 15417).
     */
    public const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213', // 0-9
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132', // 10-19
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211', // 20-29
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313', // 30-39
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331', // 40-49
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111', // 50-59
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214', // 60-69
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111', // 70-79
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141', // 80-89
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141', // 90-99
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',                              // 100-106
    ];

    /**
     * The symbol's values: Start B, one per character, the check character, the stop.
     *
     * @return list<int>
     *
     * @throws BarcodeFailure unless the value is non-empty printable ASCII
     */
    public static function values(string $content): array
    {
        Code128Fit::assertEncodable($content);

        $values = [self::START_B];
        $sum    = self::START_B;

        foreach (str_split($content) as $position => $char) {
            $value    = ord($char) - 32;
            $values[] = $value;
            $sum     += ($position + 1) * $value;
        }

        $values[] = $sum % 103;
        $values[] = self::STOP;

        return $values;
    }

    /**
     * The widths of the symbol's alternating bars and spaces, in modules, bar first,
     * without the quiet zones.
     *
     * @return list<int>
     *
     * @throws BarcodeFailure unless the value is non-empty printable ASCII
     */
    public static function widths(string $content): array
    {
        $widths = [];

        foreach (self::values($content) as $value) {
            foreach (str_split(self::PATTERNS[$value]) as $width) {
                $widths[] = (int) $width;
            }
        }

        return $widths;
    }
}
