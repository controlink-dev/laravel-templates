<?php

namespace Controlink\Templates\Support;

use Controlink\Templates\Contracts\MoneyFormatter;
use NumberFormatter;

/**
 * ICU currency formatting with the document's separators (`DocumentNumbers`) and
 * `templates.money.currency`.
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
        $currency = DocumentNumbers::currency($code, $decimals);

        if ($symbol === null) {
            return (string) $currency->formatCurrency((float) $value, $code);
        }

        $digits = $decimals ?? (int) $currency->getAttribute(NumberFormatter::FRACTION_DIGITS);
        $amount = (string) DocumentNumbers::decimal($digits)->format((float) $value);
        $sign   = (string) $currency->getSymbol(NumberFormatter::CURRENCY_SYMBOL);

        return match ($symbol) {
            'before' => $sign."\u{A0}".$amount,
            'after'  => $amount."\u{A0}".$sign,
            'code'   => $amount."\u{A0}".$code,
            default  => $amount,
        };
    }
}
