<?php

namespace Controlink\Templates\Support;

use Controlink\Templates\Contracts\MoneyFormatter;
use NumberFormatter;

/**
 * ICU currency formatting with the app locale and `templates.money.currency`.
 *
 * The two optional arguments are the `money(decimals, symbol)` filter's (CGE-49). Without
 * them the output is the plain ICU currency string, as it always was.
 */
final class IntlMoneyFormatter implements MoneyFormatter
{
    public function format(float|string|null $value, ?int $decimals = null, ?string $symbol = null): string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return '';
        }

        $code     = (string) config('templates.money.currency', 'EUR');
        $currency = new NumberFormatter(app()->getLocale().'@currency='.$code, NumberFormatter::CURRENCY);

        if ($decimals !== null) {
            $currency->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
            $currency->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
            $currency->setAttribute(NumberFormatter::ROUNDING_MODE, NumberFormatter::ROUND_HALFUP);
        }

        if ($symbol === null) {
            return (string) $currency->formatCurrency((float) $value, $code);
        }

        $number = new NumberFormatter(app()->getLocale(), NumberFormatter::DECIMAL);
        $digits = $decimals ?? (int) $currency->getAttribute(NumberFormatter::FRACTION_DIGITS);
        $number->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $digits);
        $number->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $digits);
        $number->setAttribute(NumberFormatter::ROUNDING_MODE, NumberFormatter::ROUND_HALFUP);

        $amount = (string) $number->format((float) $value);
        $sign   = (string) $currency->getSymbol(NumberFormatter::CURRENCY_SYMBOL);

        return match ($symbol) {
            'before' => $sign."\u{A0}".$amount,
            'after'  => $amount."\u{A0}".$sign,
            'code'   => $amount."\u{A0}".$code,
            default  => $amount,
        };
    }
}
