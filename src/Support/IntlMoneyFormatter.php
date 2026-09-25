<?php

namespace Controlink\Templates\Support;

use Controlink\Templates\Contracts\MoneyFormatter;
use NumberFormatter;

/** ICU currency formatting with the app locale and `templates.money.currency`. */
final class IntlMoneyFormatter implements MoneyFormatter
{
    public function format(float|string|null $value): string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return '';
        }

        $formatter = new NumberFormatter(app()->getLocale(), NumberFormatter::CURRENCY);

        return (string) $formatter->formatCurrency((float) $value, (string) config('templates.money.currency', 'EUR'));
    }
}
