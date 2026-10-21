<?php

namespace Controlink\Templates\Contracts;

/**
 * A host may add two optional parameters, `?int $decimals = null` and
 * `?string $symbol = null` (`before|after|code|none`), to honour the `money(decimals,
 * symbol)` filter arguments (CGE-49). They are not on the interface so a formatter
 * written before them keeps compiling; the filter calls with one argument when the field
 * chose none.
 */
interface MoneyFormatter
{
    public function format(float|string|null $value): string;
}
