<?php

namespace Controlink\Templates\Contracts;

interface MoneyFormatter
{
    public function format(float|string|null $value): string;
}
