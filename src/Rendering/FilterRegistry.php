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
            'date' => function (mixed $value): string {
                if ($value === null || $value === '') {
                    return '';
                }

                try {
                    return CarbonImmutable::parse((string) $value)->locale(app()->getLocale())->isoFormat('L');
                } catch (Throwable) {
                    return (string) $value;
                }
            },
            'number' => function (mixed $value): string {
                if (! is_numeric($value)) {
                    return (string) $value;
                }

                return (string) (new NumberFormatter(app()->getLocale(), NumberFormatter::DECIMAL))->format((float) $value);
            },
            'money' => fn (mixed $value): string => $this->money->format(is_numeric($value) ? (float) $value : null),
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
