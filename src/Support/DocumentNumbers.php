<?php

namespace Controlink\Templates\Support;

use NumberFormatter;

/**
 * The number formatters a document prints with (CGE-75).
 *
 * A document has one set of separators for its numbers and its money: the ones
 * `templates.numbers` names (`locale`, `thousands`, `decimal`), not the language of the
 * user who happens to open the editor or print. The currency only brings its symbol and
 * its decimals. Left unset, each one falls back to ICU for the app locale, which is what
 * every version rendered before this existed.
 */
final class DocumentNumbers
{
    /** A plain number; `$decimals` fixes the fraction digits, rounding half up. */
    public static function decimal(?int $decimals = null): NumberFormatter
    {
        $formatter = new NumberFormatter(self::locale(), NumberFormatter::DECIMAL);
        self::separators($formatter);
        self::fraction($formatter, $decimals);

        return $formatter;
    }

    /** An amount in `$code`, laid out the way the document's locale places the symbol. */
    public static function currency(string $code, ?int $decimals = null): NumberFormatter
    {
        $formatter = new NumberFormatter(self::locale().'@currency='.$code, NumberFormatter::CURRENCY);
        self::separators($formatter);
        self::fraction($formatter, $decimals);

        return $formatter;
    }

    public static function locale(): string
    {
        $locale = config('templates.numbers.locale');

        return is_string($locale) && $locale !== '' ? $locale : app()->getLocale();
    }

    private static function separators(NumberFormatter $formatter): void
    {
        $thousands = config('templates.numbers.thousands');
        $decimal   = config('templates.numbers.decimal');

        // A currency pattern reads the monetary symbols, a plain one the others: set both.
        if (is_string($thousands)) {
            $formatter->setSymbol(NumberFormatter::GROUPING_SEPARATOR_SYMBOL, $thousands);
            $formatter->setSymbol(NumberFormatter::MONETARY_GROUPING_SEPARATOR_SYMBOL, $thousands);
        }

        if (is_string($decimal) && $decimal !== '') {
            $formatter->setSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL, $decimal);
            $formatter->setSymbol(NumberFormatter::MONETARY_SEPARATOR_SYMBOL, $decimal);
        }
    }

    private static function fraction(NumberFormatter $formatter, ?int $decimals): void
    {
        if ($decimals === null) {
            return;
        }

        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
        $formatter->setAttribute(NumberFormatter::ROUNDING_MODE, NumberFormatter::ROUND_HALFUP);
    }
}
