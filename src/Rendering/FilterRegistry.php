<?php

namespace Controlink\Templates\Rendering;

use Carbon\CarbonImmutable;
use Controlink\Templates\Contracts\MoneyFormatter;
use NumberFormatter;
use Throwable;

/**
 * The formats a data field may use. The names come from `templates.formats`; the
 * callables come from here (the four defaults) or from `register()` (the host).
 */
class FilterRegistry
{
    /** @var array<string, callable> */
    private array $custom = [];

    public function __construct(private readonly MoneyFormatter $money) {}

    public function register(string $name, callable $filter): void
    {
        $this->custom[$name] = $filter;
    }

    /** @return array<string, callable> only the names `templates.formats` allows */
    public function filters(): array
    {
        $all = $this->custom + [
            // The arguments (CGE-49) come from FormatSpec's closed lists and are checked
            // again here: the exported template is what gets rendered, so a hand-edited
            // argument has to fail rather than reach Carbon or ICU. With no arguments
            // each filter is exactly what it was, which keeps old versions reproducible.
            'date' => function (mixed $value, ?string $pattern = null): string {
                FormatSpec::assertPattern($pattern);

                if ($value === null || $value === '') {
                    return '';
                }

                try {
                    $date = CarbonImmutable::parse((string) $value)->locale(app()->getLocale());
                } catch (Throwable) {
                    return (string) $value;
                }

                return $date->isoFormat(FormatSpec::isoPattern($pattern) ?? 'L');
            },
            'number' => function (mixed $value, mixed $decimals = null): string {
                $decimals = FormatSpec::assertDecimals($decimals);

                if (! is_numeric($value)) {
                    return (string) $value;
                }

                $formatter = new NumberFormatter(app()->getLocale(), NumberFormatter::DECIMAL);

                if ($decimals !== null) {
                    $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
                    $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
                    $formatter->setAttribute(NumberFormatter::ROUNDING_MODE, NumberFormatter::ROUND_HALFUP);
                }

                return (string) $formatter->format((float) $value);
            },
            'money' => function (mixed $value, mixed $decimals = null, ?string $symbol = null): string {
                $decimals = FormatSpec::assertDecimals($decimals);
                FormatSpec::assertSymbol($symbol);

                $amount = is_numeric($value) ? (float) $value : null;

                // A host formatter written before CGE-49 declares only `$value`; PHP
                // ignores the extra arguments, so it keeps printing what it printed.
                return $decimals === null && $symbol === null
                    ? $this->money->format($amount)
                    : $this->money->format($amount, $decimals, $symbol);
            },
            'upper' => fn (mixed $value): string => mb_strtoupper((string) $value),
        ];

        $allowed = (array) config('templates.formats');

        return array_intersect_key($all, array_flip($allowed));
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->filters());
    }
}
