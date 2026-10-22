<?php

namespace Controlink\Templates\Formulas;

use Throwable;

/**
 * SUM and COUNT results shared by the formulas of one evaluation pass (CGE-37).
 *
 * An aggregate ranges over the whole collection, and a per-row column asks for it once
 * per row: without this a `linhas.valor / SUM(linhas.valor)` column is O(n²) in the
 * number of lines. A failure is remembered too (a non-numeric cell makes SUM fail on
 * every row alike), or the error path would stay quadratic.
 *
 * Only valid while every formula that shares it reads the SAME dataset — the compositor
 * evaluates them all over the dataset as it arrived. Never share one across datasets.
 */
final class AggregateCache
{
    /** @var array<string, int|float|Throwable> */
    private array $results = [];

    private int $computed = 0;

    /**
     * @param  callable(): (int|float)  $compute
     *
     * @throws Throwable the failure `$compute` raised, the first time and every time after
     */
    public function remember(string $key, callable $compute): int|float
    {
        if (! array_key_exists($key, $this->results)) {
            $this->computed++;

            try {
                $this->results[$key] = $compute();
            } catch (Throwable $e) {
                $this->results[$key] = $e;
            }
        }

        $result = $this->results[$key];

        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }

    /** How many aggregates were actually computed: one per distinct key. */
    public function computed(): int
    {
        return $this->computed;
    }
}
