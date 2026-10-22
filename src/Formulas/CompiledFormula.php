<?php

namespace Controlink\Templates\Formulas;

use Controlink\Templates\Rendering\TemplateRenderer;
use Throwable;

/**
 * A formula parsed once and evaluated as often as needed (CGE-37): a per-row column is
 * evaluated on every line, and parsing its source on every line is wasted work.
 *
 * Built by {@see Formula::compile()}; a source that does not parse compiles too, and
 * every evaluation of it is the empty string — the same contract as {@see Formula::evaluate()}.
 */
final class CompiledFormula
{
    /**
     * @param  array<string, mixed>|null  $ast  null when the source does not parse
     */
    public function __construct(private readonly ?array $ast) {}

    /**
     * Never throws: any failure (syntax, missing value, wrong type, division by
     * zero, even a bug) prints as an empty string.
     *
     * @param  array<string, mixed>  $dataset
     * @param  array<string, mixed>|null  $row
     * @param  (callable(mixed, string, array): string)|null  $format  TEXT() formatter; defaults to the package's own filters
     * @param  AggregateCache|null  $aggregates  shared SUM/COUNT results; only across evaluations over this same dataset
     */
    public function evaluate(array $dataset, ?array $row = null, ?string $rowCollection = null, ?callable $format = null, ?AggregateCache $aggregates = null): string|int|float
    {
        if ($this->ast === null) {
            return '';
        }

        try {
            $format ??= static function (mixed $value, string $name, array $args): string {
                return app(TemplateRenderer::class)->formatValue($value, $name, $args);
            };
            $value = (new Evaluator($dataset, $row, $rowCollection, $format, $aggregates))->evaluate($this->ast);

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
}
