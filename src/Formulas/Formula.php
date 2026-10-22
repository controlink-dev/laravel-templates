<?php

namespace Controlink\Templates\Formulas;

use Controlink\Templates\Rendering\TemplateRenderer;
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
     * Never throws: any failure (syntax, missing value, wrong type, division by
     * zero, even a bug) prints as an empty string.
     *
     * @param  array<string, mixed>  $dataset
     * @param  array<string, mixed>|null  $row
     * @param  (callable(mixed, string, array): string)|null  $format  TEXT() formatter; defaults to the package's own filters
     */
    public static function evaluate(string $source, array $dataset, ?array $row = null, ?string $rowCollection = null, ?callable $format = null): string|int|float
    {
        try {
            $format ??= static function (mixed $value, string $name, array $args): string {
                return app(TemplateRenderer::class)->formatValue($value, $name, $args);
            };
            $value = (new Evaluator($dataset, $row, $rowCollection, $format))->evaluate(Parser::parse($source));

            if ($value === null) {
                return '';
            }
            if (is_bool($value)) {
                return $value ? 'TRUE' : 'FALSE';
            }
            if (is_float($value) && ! is_finite($value)) {
                return '';
            }

            return $value;
        } catch (Throwable) {
            return '';
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

                foreach ($node['args'] as $arg) {
                    self::walk($arg, $knownKeys, $collections, $rowCollection, $aggregate);
                }
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
