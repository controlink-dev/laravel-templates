<?php

namespace Controlink\Templates\Formulas;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Walks the AST produced by {@see Parser} and computes a value.
 *
 * Why a tree walk and not generated code: nothing the user typed is ever executed
 * as PHP or Twig, so the language is exactly what is implemented here. Evaluation
 * is lazy (IF/AND/OR only evaluate the branches they need) so `IF(q = 0, 0, t / q)`
 * is a safe way to avoid a division by zero.
 *
 * Every failure is a {@see FormulaException}; {@see Formula::evaluate()} turns it
 * into an empty string so a bad formula can never break a render.
 *
 * Values are null, bool, int, float or string. Coercion follows the spreadsheet
 * habit: empty means 0 in arithmetic, numeric text is a number, other text in
 * arithmetic is an error.
 */
final class Evaluator
{
    /** Formats TEXT() may use; a closed list so the formatter is never asked for anything else. */
    public const TEXT_FORMATS = ['date', 'money', 'number'];

    /** @var callable(mixed, string, array): string */
    private $format;

    /**
     * @param  array<string, mixed>  $dataset  flat map: 'customer.name' => ..., 'lines' => [[...], ...]
     * @param  array<string, mixed>|null  $row  current row when evaluating a per-row column
     * @param  callable(mixed, string, array): string|null  $format  how TEXT() formats; absent => TEXT is an error
     */
    public function __construct(
        private readonly array $dataset,
        private readonly ?array $row = null,
        private readonly ?string $rowCollection = null,
        ?callable $format = null,
    ) {
        $this->format = $format;
    }

    /**
     * @param  array<string, mixed>  $node
     *
     * @throws FormulaException
     */
    public function evaluate(array $node): mixed
    {
        switch ($node['type']) {
            case 'num':
            case 'str':
            case 'bool':
                return $node['value'];
            case 'ref':
                return $this->reference($node['key']);
            case 'neg':
                return -$this->number($this->evaluate($node['expr']));
            case 'bin':
                return $this->binary($node);
            case 'call':
                return $this->call($node['name'], $node['args']);
        }

        throw new FormulaException('syntax', 'Unknown node.');
    }

    private function reference(string $key): mixed
    {
        if ($this->row !== null && $this->rowCollection !== null && str_starts_with($key, $this->rowCollection.'.')) {
            $value = $this->row[substr($key, strlen($this->rowCollection) + 1)] ?? null;
        } else {
            $value = $this->dataset[$key] ?? null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        // A collection or object is not a scalar value: using it in a formula is a type error.
        if ($value !== null && ! is_scalar($value)) {
            throw new FormulaException('type', 'Not a scalar value.');
        }

        return $value;
    }

    private function binary(array $node): mixed
    {
        $op = $node['op'];
        $left = $this->evaluate($node['left']);
        $right = $this->evaluate($node['right']);

        switch ($op) {
            case '+':
                return $this->number($left) + $this->number($right);
            case '-':
                return $this->number($left) - $this->number($right);
            case '*':
                return $this->number($left) * $this->number($right);
            case '/':
                $divisor = $this->number($right);
                if ($divisor == 0) {
                    throw new FormulaException('division_by_zero');
                }

                // Always float so `4 / 2` and `5 / 2` behave alike downstream.
                return $this->number($left) / (float) $divisor;
            case '&':
                return $this->text($left).$this->text($right);
        }

        return $this->compare($op, $left, $right);
    }

    private function compare(string $op, mixed $left, mixed $right): string|bool
    {
        $leftEmpty = $left === null || $left === '';
        $rightEmpty = $right === null || $right === '';

        if ($this->isNumeric($left) && $this->isNumeric($right)) {
            $order = $this->numericOrder($this->number($left), $this->number($right));
        } elseif (($leftEmpty && $this->isNumeric($right)) || ($rightEmpty && $this->isNumeric($left))) {
            // A blank cell is 0 next to a number, like in a spreadsheet.
            $order = $this->numericOrder($this->number($left), $this->number($right));
        } else {
            $order = strcmp($this->text($left), $this->text($right)) <=> 0;
        }

        return match ($op) {
            '=' => $order === 0,
            '<>' => $order !== 0,
            '<' => $order < 0,
            '<=' => $order <= 0,
            '>' => $order > 0,
            '>=' => $order >= 0,
        };
    }

    /**
     * Two numbers within float noise of each other are equal: `0.1 + 0.2 = 0.3`
     * has to be TRUE on a printed document, as it is in a spreadsheet. The
     * tolerance is relative so it holds for large amounts as well as small ones,
     * and it lives in the ordering so `<=`/`>=` agree with `=`/`<>`. 1e-12, not
     * 1e-9: at 10,000,000 a looser bound would already swallow a cent.
     */
    private function numericOrder(int|float $left, int|float $right): int
    {
        if (abs($left - $right) <= 1e-12 * max(1, abs($left), abs($right))) {
            return 0;
        }

        return $left <=> $right;
    }

    /**
     * @param  list<array<string, mixed>>  $args
     */
    private function call(string $name, array $args): mixed
    {
        switch ($name) {
            case 'IF':
                return $this->evaluate($this->truthy($this->evaluate($args[0])) ? $args[1] : $args[2]);
            case 'AND':
                foreach ($args as $arg) {
                    if (! $this->truthy($this->evaluate($arg))) {
                        return false;
                    }
                }

                return true;
            case 'OR':
                foreach ($args as $arg) {
                    if ($this->truthy($this->evaluate($arg))) {
                        return true;
                    }
                }

                return false;
            case 'NOT':
                return ! $this->truthy($this->evaluate($args[0]));
            case 'SUM':
            case 'COUNT':
                return $this->aggregate($name, $args[0]);
            case 'TEXT':
                return $this->formatted($args);
        }

        $values = array_map(fn (array $arg): mixed => $this->evaluate($arg), $args);

        switch ($name) {
            case 'ROUND':
                // PHP rounds half away from zero, which is what spreadsheets do.
                return round($this->number($values[0]), (int) $this->number($values[1] ?? 0));
            case 'ABS':
                return abs($this->number($values[0]));
            case 'MIN':
                return min(array_map(fn ($v) => $this->number($v), $values));
            case 'MAX':
                return max(array_map(fn ($v) => $this->number($v), $values));
            case 'UPPER':
                return mb_strtoupper($this->text($values[0]));
            case 'LOWER':
                return mb_strtolower($this->text($values[0]));
            case 'CONCAT':
                return implode('', array_map(fn ($v) => $this->text($v), $values));
            case 'LEFT':
                return mb_substr($this->text($values[0]), 0, $this->count($values[1] ?? 1));
            case 'RIGHT':
                $count = $this->count($values[1] ?? 1);

                return $count === 0 ? '' : mb_substr($this->text($values[0]), -$count);
            case 'LEN':
                return mb_strlen($this->text($values[0]));
            case 'TRIM':
                // \s with /u also removes non-breaking spaces that pasted data often carries.
                return (string) preg_replace('/^\s+|\s+$/u', '', $this->text($values[0]));
            case 'YEAR':
                return $this->date($values[0])->year;
            case 'MONTH':
                return $this->date($values[0])->month;
            case 'DAY':
                return $this->date($values[0])->day;
            case 'DAYS':
                return $this->days($this->date($values[0]), $this->date($values[1]));
        }

        throw new FormulaException('unknown_function', "Unknown function {$name}.");
    }

    /**
     * SUM and COUNT always range over the whole collection of the dataset, also in a
     * per-row column: a line total that summed "its own" rows would be meaningless.
     *
     * @param  array<string, mixed>  $arg
     */
    private function aggregate(string $name, array $arg): int|float
    {
        if ($arg['type'] !== 'ref') {
            throw new FormulaException('scope', "{$name} needs a collection column.");
        }

        // Column keys may contain dots (`lines.entity.name`), so split on the first one only.
        [$collection, $column] = explode('.', $arg['key'], 2) + [1 => ''];
        $rows = $this->dataset[$collection] ?? null;
        if (! is_array($rows)) {
            throw new FormulaException('unknown_reference', "No collection {$collection}.");
        }

        if ($name === 'COUNT') {
            return count($rows);
        }

        $sum = 0;
        foreach ($rows as $row) {
            $cell = is_array($row) ? ($row[$column] ?? null) : null;
            $sum += $this->number($cell);
        }

        return $sum;
    }

    /**
     * @param  list<array<string, mixed>>  $args
     */
    private function formatted(array $args): string
    {
        // The format must be a literal so the closed list is enforced by looking at the source.
        $formatNode = $args[1];
        if ($formatNode['type'] !== 'str' || ! in_array($formatNode['value'], self::TEXT_FORMATS, true)) {
            throw new FormulaException('syntax', 'TEXT format must be "date", "money" or "number".');
        }
        if ($this->format === null) {
            throw new FormulaException('type', 'No formatter available.');
        }

        $value = $this->evaluate($args[0]);
        $extra = isset($args[2]) ? [$this->evaluate($args[2])] : [];

        return (string) ($this->format)($value, $formatNode['value'], $extra);
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === null || $value === '') {
            return false;
        }
        if ($this->isNumeric($value)) {
            return $this->number($value) != 0;
        }

        $upper = strtoupper((string) $value);
        if ($upper === 'TRUE' || $upper === 'FALSE') {
            return $upper === 'TRUE';
        }

        throw new FormulaException('type', 'Not a condition.');
    }

    private function isNumeric(mixed $value): bool
    {
        return is_int($value) || is_float($value) || is_bool($value)
            || (is_string($value) && is_numeric(trim($value)));
    }

    private function number(mixed $value): int|float
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return trim($value) + 0;
        }

        throw new FormulaException('type', 'Not a number.');
    }

    /** A non-negative whole count (LEFT/RIGHT length). */
    private function count(mixed $value): int
    {
        $n = $this->number($value);
        if ($n < 0) {
            throw new FormulaException('type', 'Negative length.');
        }

        return (int) $n;
    }

    private function text(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }
        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new FormulaException('type', 'Not a finite number.');
            }

            // 3.0 prints as "3": a trailing ".0" is noise in running text.
            return floor($value) === $value && abs($value) < 1e15 ? sprintf('%.0f', $value) : (string) $value;
        }

        return (string) $value;
    }

    /**
     * Only real calendar dates are accepted. Carbon would also parse "tomorrow" or
     * "now", which would make a reprint differ from the original print.
     */
    private function date(mixed $value): CarbonImmutable
    {
        $text = trim($this->text($value));
        // The whole value, not a prefix: "2026-10-01 tomorrow" is not a date.
        $pattern = '/^(\d{4})-(\d{2})-(\d{2})(?:[ T]\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)?$/';
        if (preg_match($pattern, $text, $m) !== 1) {
            throw new FormulaException('type', 'Not a date.');
        }
        // Carbon rolls an impossible day over ("2026-02-30" becomes March 2nd).
        if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new FormulaException('type', 'Not a date.');
        }

        try {
            return CarbonImmutable::parse($text);
        } catch (\Throwable) {
            throw new FormulaException('type', 'Not a date.');
        }
    }

    /** Whole calendar days end - start, independent of time of day and DST. */
    private function days(CarbonImmutable $end, CarbonImmutable $start): int
    {
        $utc = fn (CarbonImmutable $d): int => gmmktime(0, 0, 0, $d->month, $d->day, $d->year);

        return intdiv($utc($end) - $utc($start), 86400);
    }
}
