<?php

namespace Controlink\Templates\Output\Pdf;

/**
 * A line table whose items span several rows (CGE-97).
 *
 * A column flagged `break` starts a new row of the item: the visible columns split
 * before each one into segments, one `<tr>` per segment. The longest segment is the
 * grid every row is laid on (the `<colgroup>`); a shorter one stretches its last cell
 * over the grid columns it leaves free.
 *
 * Mirrored by `resources/js/editor/line-rows.js`, which writes the markup: the two must
 * agree on the segments, the grid and the spans, and on the `lines__text` class it puts
 * on the text cells of a multi-row table (the cells `PageStructure::css` lets wrap).
 */
class LineRows
{
    /** The class on a multi-row table's text cells. */
    public const TEXT_CLASS = 'lines__text';

    /**
     * The visible columns, split before each one with `break === true`.
     *
     * A hidden column's break moves to the next visible column; a break on the first
     * visible column opens no empty row. Without a break: `[$visible]`.
     *
     * @param  list<array<string, mixed>>  $columns
     * @return list<list<array<string, mixed>>>
     */
    public static function segments(array $columns): array
    {
        $segments = [[]];
        $pending  = false;

        foreach ($columns as $column) {
            if (! is_array($column)) {
                continue;
            }

            $pending = $pending || ($column['break'] ?? false) === true;

            if (! self::isVisible($column)) {
                continue;
            }

            if ($pending && $segments[count($segments) - 1] !== []) {
                $segments[] = [];
            }

            $pending = false;

            $segments[count($segments) - 1][] = $column;
        }

        return $segments;
    }

    /** @param  list<array<string, mixed>>  $columns */
    public static function isMultiRow(array $columns): bool
    {
        return count(self::segments($columns)) > 1;
    }

    /**
     * The columns of the longest segment (the first, on a tie): the table's grid.
     *
     * @param  list<array<string, mixed>>  $columns
     * @return list<array<string, mixed>>
     */
    public static function grid(array $columns): array
    {
        $grid = [];

        foreach (self::segments($columns) as $segment) {
            if (count($segment) > count($grid)) {
                $grid = $segment;
            }
        }

        return $grid;
    }

    /**
     * The colspan of each cell, per segment: 1, but for the last cell of a segment
     * shorter than the grid, which takes the grid columns left over.
     *
     * @param  list<array<string, mixed>>  $columns
     * @return list<list<int>>
     */
    public static function spans(array $columns): array
    {
        $width = count(self::grid($columns));
        $spans = [];

        foreach (self::segments($columns) as $segment) {
            $row = array_fill(0, count($segment), 1);

            if ($row !== []) {
                $row[count($row) - 1] = $width - count($segment) + 1;
            }

            $spans[] = $row;
        }

        return $spans;
    }

    /**
     * The columns that print, in order.
     *
     * @param  list<array<string, mixed>>  $columns
     * @return list<array<string, mixed>>
     */
    public static function visible(array $columns): array
    {
        return array_values(array_filter($columns, fn (mixed $column): bool => is_array($column) && self::isVisible($column)));
    }

    /** @param  array<string, mixed>  $column */
    private static function isVisible(array $column): bool
    {
        return empty($column['hidden']);
    }
}
