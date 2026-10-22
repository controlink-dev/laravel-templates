<?php

namespace Controlink\Templates\Formulas;

use Controlink\Templates\Rendering\FormatSpec;
use InvalidArgumentException;
use Throwable;

/**
 * Entry point of the formula language (CGE-37): validate a formula when it is
 * saved, evaluate it when a document is printed.
 *
 * The two have different failure contracts on purpose. `check` reports why a
 * formula is wrong so the editor can tell the user; `evaluate` never throws,
 * because a bad formula must cost one empty field, not the whole document.
 */
final class Formula
{
    private const AGGREGATES = ['SUM', 'COUNT'];

    /**
     * @return list<string> function names, for the editor's autocomplete
     */
    public static function functions(): array
    {
        return array_keys(Parser::FUNCTIONS);
    }

    /**
     * A source as every reader takes it: the validator (V-19), the editor's live preview
     * and the compositor. One normalisation for all three, so a formula that saves and
     * previews also prints — trailing whitespace once printed empty while the other two
     * trimmed it away.
     */
    public static function normalize(string $source): string
    {
        return trim($source);
    }

    /**
     * @param  list<string>  $knownKeys  singular catalog keys
     * @param  array<string, list<string>>  $collections  collection key => column keys
     * @param  string|null  $rowCollection  set when the formula is a per-row column of that collection
     * @return FormulaException|null null when the formula is valid; reason: syntax | unknown_function |
     *                               arity | unknown_reference | scope
     */
    public static function check(string $source, array $knownKeys, array $collections, ?string $rowCollection = null): ?FormulaException
    {
        try {
            $ast = Parser::parse($source);
        } catch (FormulaException $e) {
            return $e;
        } catch (Throwable) {
            // Whatever goes wrong while parsing is a syntax problem for the caller.
            return new FormulaException('syntax');
        }

        try {
            self::walk($ast, $knownKeys, $collections, $rowCollection, false);
        } catch (FormulaException $e) {
            return $e;
        } catch (Throwable) {
            // Same contract as parsing: the editor gets a reason, never a crash.
            return new FormulaException('syntax');
        }

        return null;
    }

    /**
     * Every key the formula reads, in order of appearance and without repeats. A formula
     * that does not parse reads nothing; `check` is what reports why.
     *
     * Layer-1 validation needs these before it can call `check`: a referenced field the
     * type never declared has to be resolved by the host first, like a placed one.
     *
     * @return list<string>
     */
    public static function references(string $source): array
    {
        try {
            $keys = [];
            self::collectReferences(Parser::parse($source), $keys);

            return array_values(array_unique($keys));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Never throws: any failure (syntax, missing value, wrong type, division by
     * zero, even a bug) prints as an empty string.
     *
     * @param  array<string, mixed>  $dataset
     * @param  array<string, mixed>|null  $row
     * @param  (callable(mixed, string, array): string)|null  $format  TEXT() formatter; defaults to the package's own filters
     */
    public static function evaluate(string $source, array $dataset, ?array $row = null, ?string $rowCollection = null, ?callable $format = null): string|int|float
    {
        return self::compile($source)->evaluate($dataset, $row, $rowCollection, $format);
    }

    /**
     * The source parsed once, for evaluating many times (a per-row column, once per
     * line). Never throws: a source that does not parse compiles to one that prints ''.
     */
    public static function compile(string $source): CompiledFormula
    {
        try {
            return new CompiledFormula(Parser::parse($source));
        } catch (Throwable) {
            return new CompiledFormula(null);
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $knownKeys
     * @param  array<string, list<string>>  $collections
     *
     * @throws FormulaException
     */
    private static function walk(array $node, array $knownKeys, array $collections, ?string $rowCollection, bool $aggregateArg): void
    {
        switch ($node['type']) {
            case 'ref':
                self::checkReference($node['key'], $knownKeys, $collections, $rowCollection, $aggregateArg);

                return;
            case 'neg':
                self::walk($node['expr'], $knownKeys, $collections, $rowCollection, false);

                return;
            case 'bin':
                self::walk($node['left'], $knownKeys, $collections, $rowCollection, false);
                self::walk($node['right'], $knownKeys, $collections, $rowCollection, false);

                return;
            case 'call':
                $aggregate = in_array($node['name'], self::AGGREGATES, true);

                if ($aggregate && $node['args'][0]['type'] !== 'ref') {
                    throw new FormulaException('scope', "{$node['name']} needs a collection column.");
                }
                if ($node['name'] === 'TEXT'
                    && ($node['args'][1]['type'] !== 'str' || ! in_array($node['args'][1]['value'], Evaluator::TEXT_FORMATS, true))) {
                    throw new FormulaException('syntax', 'TEXT format must be "date", "money" or "number".');
                }
                if ($node['name'] === 'TEXT' && isset($node['args'][2])) {
                    self::checkTextArgument($node['args'][1]['value'], $node['args'][2]);
                }

                foreach ($node['args'] as $arg) {
                    self::walk($arg, $knownKeys, $collections, $rowCollection, $aggregate);
                }
        }
    }

    /**
     * TEXT's third argument, when it is written as a literal, is held to the same lists a
     * field's format is (`FormatSpec`): decimals for money and number, a pattern for date.
     * A computed one is left to the formatter at print time, where a bad value prints empty.
     *
     * @param  array<string, mixed>  $argument
     *
     * @throws FormulaException
     */
    private static function checkTextArgument(string $format, array $argument): void
    {
        $value = match (true) {
            in_array($argument['type'], ['num', 'str', 'bool'], true)         => $argument['value'],
            $argument['type'] === 'neg' && $argument['expr']['type'] === 'num' => -$argument['expr']['value'],
            default                                                            => null,
        };

        if ($value === null) {
            return;
        }

        try {
            if ($format === 'date') {
                if (! is_string($value)) {
                    throw new InvalidArgumentException('A date pattern is text.');
                }

                FormatSpec::assertPattern($value);
            } else {
                FormatSpec::assertDecimals($value);
            }
        } catch (InvalidArgumentException $e) {
            throw new FormulaException('syntax', $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $keys
     */
    private static function collectReferences(array $node, array &$keys): void
    {
        $children = match ($node['type']) {
            'neg'   => [$node['expr']],
            'bin'   => [$node['left'], $node['right']],
            'call'  => $node['args'],
            default => [],
        };

        if ($node['type'] === 'ref') {
            $keys[] = $node['key'];
        }

        // A plain loop: an arrow function would capture `$keys` by value and lose them.
        foreach ($children as $child) {
            self::collectReferences($child, $keys);
        }
    }

    /**
     * @param  list<string>  $knownKeys
     * @param  array<string, list<string>>  $collections
     *
     * @throws FormulaException
     */
    private static function checkReference(string $key, array $knownKeys, array $collections, ?string $rowCollection, bool $aggregateArg): void
    {
        // No chaining: a formula cannot read another formula's result.
        if (str_starts_with($key, 'formula.')) {
            throw new FormulaException('unknown_reference', "{$key} cannot be referenced.");
        }

        [$first, $column] = explode('.', $key, 2) + [1 => ''];

        if (isset($collections[$first])) {
            if (! in_array($column, $collections[$first], true)) {
                throw new FormulaException('unknown_reference', "{$key} is not a column of {$first}.");
            }
            if ($aggregateArg || $rowCollection === $first) {
                return;
            }

            throw new FormulaException('scope', "{$key} is only valid inside SUM or COUNT.");
        }

        if ($aggregateArg) {
            throw new FormulaException('scope', 'SUM and COUNT need a collection column.');
        }
        if (! in_array($key, $knownKeys, true)) {
            throw new FormulaException('unknown_reference', "{$key} is not a known field.");
        }
    }
}
