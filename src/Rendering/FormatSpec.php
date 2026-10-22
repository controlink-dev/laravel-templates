<?php

namespace Controlink\Templates\Rendering;

use InvalidArgumentException;

/**
 * A field's format with its arguments, and the one place its Twig expression is written.
 *
 * The editor stores the format as attributes (`data-tpl-format` and, for the formats
 * that take them, `-pattern`, `-decimals` and `-symbol`); a table column stores the same
 * as `format` + `formatArgs`. Every argument comes from a closed list, so an expression
 * built here can only ever call a filter with values the filter itself accepts. The JS
 * editor writes the same expression (`formats.js`); `tests/Fixtures/format-expressions.json`
 * pins both to one canonical form.
 *
 * With no arguments the expression is the bare `|name` every layout used before CGE-49,
 * which is what keeps already-activated versions printing byte for byte the same.
 */
final class FormatSpec
{
    public const DATE_PATTERNS = ['dd/MM/yyyy', 'yyyy-MM-dd', 'yyyy/MM/dd', 'dd-MM-yyyy', 'dd MMM yyyy'];

    public const SYMBOLS = ['before', 'after', 'code', 'none'];

    public const MAX_DECIMALS = 4;

    /** The Carbon `isoFormat` tokens each pattern stands for. */
    private const ISO = [
        'dd/MM/yyyy'  => 'DD/MM/YYYY',
        'yyyy-MM-dd'  => 'YYYY-MM-DD',
        'yyyy/MM/dd'  => 'YYYY/MM/DD',
        'dd-MM-yyyy'  => 'DD-MM-YYYY',
        'dd MMM yyyy' => 'DD MMM YYYY',
    ];

    private function __construct(
        private readonly string $name,
        private readonly ?string $pattern = null,
        private readonly ?int $decimals = null,
        private readonly ?string $symbol = null,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  a data field's attributes
     *
     * @throws InvalidArgumentException on an argument outside its list
     */
    public static function fromAttributes(array $attributes): ?self
    {
        return self::make(
            (string) ($attributes['data-tpl-format'] ?? ''),
            $attributes['data-tpl-format-pattern'] ?? null,
            $attributes['data-tpl-format-decimals'] ?? null,
            $attributes['data-tpl-format-symbol'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $column  a line-table column (`format`, `formatArgs`)
     *
     * @throws InvalidArgumentException on an argument outside its list
     */
    public static function fromColumn(array $column): ?self
    {
        $args = (array) ($column['formatArgs'] ?? []);

        return self::make(
            (string) ($column['format'] ?? ''),
            $args['pattern'] ?? null,
            $args['decimals'] ?? null,
            $args['symbol'] ?? null,
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * The filter's positional arguments, trailing absences dropped.
     *
     * @return list<string|int|null>
     */
    public function arguments(): array
    {
        $arguments = match ($this->name) {
            'date'   => [$this->pattern],
            'number' => [$this->decimals],
            'money'  => [$this->decimals, $this->symbol],
            default  => [],
        };

        while ($arguments !== [] && end($arguments) === null) {
            array_pop($arguments);
        }

        return $arguments;
    }

    /** `{{ subject|name(args) }}` in the canonical form the editor writes too. */
    public function expression(string $subject): string
    {
        $arguments = $this->arguments();

        if ($arguments === []) {
            return '{{ '.$subject.'|'.$this->name.' }}';
        }

        $literals = array_map(
            fn (string|int|null $argument): string => match (true) {
                $argument === null  => 'null',
                is_int($argument)   => (string) $argument,
                default             => "'".$argument."'",
            },
            $arguments,
        );

        return '{{ '.$subject.'|'.$this->name.'('.implode(', ', $literals).') }}';
    }

    /**
     * The spec a printed expression stands for, from `{{ subject|name(args) }}`; null for
     * a plain `{{ subject }}`.
     *
     * Strict: the expression must be exactly the canonical one this spec would write, so
     * a hand-built argument list (other literals, other spacing, extra arguments) fails.
     *
     * @throws InvalidArgumentException when it is not a canonical format expression
     */
    public static function fromExpression(string $expression): ?self
    {
        if (! preg_match('/^\{\{ ([A-Za-z0-9_.]+)(?:\|([a-z]+)(?:\((.*)\))?)? \}\}$/', $expression, $match)) {
            throw new InvalidArgumentException("Not a field expression [{$expression}].");
        }

        $subject = $match[1];
        $name    = $match[2] ?? '';

        if ($name === '') {
            return null;
        }

        $arguments = [];

        foreach (($match[3] ?? '') === '' ? [] : explode(', ', $match[3]) as $literal) {
            $arguments[] = match (true) {
                $literal === 'null'                          => null,
                (bool) preg_match('/^\d+$/', $literal)       => (int) $literal,
                (bool) preg_match("/^'([^']*)'$/", $literal) => substr($literal, 1, -1),
                default                                      => throw new InvalidArgumentException("Not a format argument [{$literal}]."),
            };
        }

        $positions = match ($name) {
            'date'   => ['pattern'],
            'number' => ['decimals'],
            'money'  => ['decimals', 'symbol'],
            default  => [],
        };

        if (count($arguments) > count($positions)) {
            throw new InvalidArgumentException("Too many arguments for [{$name}].");
        }

        $named = array_combine(array_slice($positions, 0, count($arguments)), $arguments);
        $spec  = self::make($name, $named['pattern'] ?? null, $named['decimals'] ?? null, $named['symbol'] ?? null);

        if ($spec?->expression($subject) !== $expression) {
            throw new InvalidArgumentException("Not a canonical format expression [{$expression}].");
        }

        return $spec;
    }

    /** The Carbon `isoFormat` string for a pattern from the list, or null. */
    public static function isoPattern(?string $pattern): ?string
    {
        return $pattern === null ? null : (self::ISO[$pattern] ?? null);
    }

    /** @throws InvalidArgumentException */
    public static function assertPattern(?string $pattern): void
    {
        if ($pattern !== null && ! in_array($pattern, self::DATE_PATTERNS, true)) {
            throw new InvalidArgumentException("Unknown date pattern [{$pattern}].");
        }
    }

    /** @throws InvalidArgumentException */
    public static function assertDecimals(mixed $decimals): ?int
    {
        if ($decimals === null || $decimals === '') {
            return null;
        }

        if (! is_numeric($decimals) || (string) (int) $decimals !== (string) $decimals
            || (int) $decimals < 0 || (int) $decimals > self::MAX_DECIMALS) {
            throw new InvalidArgumentException('Decimals must be an integer from 0 to '.self::MAX_DECIMALS.'.');
        }

        return (int) $decimals;
    }

    /** @throws InvalidArgumentException */
    public static function assertSymbol(?string $symbol): void
    {
        if ($symbol !== null && ! in_array($symbol, self::SYMBOLS, true)) {
            throw new InvalidArgumentException("Unknown currency symbol position [{$symbol}].");
        }
    }

    private static function make(string $name, mixed $pattern, mixed $decimals, mixed $symbol): ?self
    {
        if ($name === '') {
            return null;
        }

        $pattern  = self::blankToNull($pattern);
        $symbol   = self::blankToNull($symbol);
        $decimals = self::assertDecimals($decimals);

        self::assertPattern($pattern);
        self::assertSymbol($symbol);

        // An argument on a format that does not take it is not a choice the editor offers.
        $takes = match ($name) {
            'date'   => ['pattern'],
            'number' => ['decimals'],
            'money'  => ['decimals', 'symbol'],
            default  => [],
        };

        foreach (['pattern' => $pattern, 'decimals' => $decimals, 'symbol' => $symbol] as $argument => $value) {
            if ($value !== null && ! in_array($argument, $takes, true)) {
                throw new InvalidArgumentException("The [{$name}] format takes no {$argument}.");
            }
        }

        return new self($name, $pattern, $decimals, $symbol);
    }

    private static function blankToNull(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
