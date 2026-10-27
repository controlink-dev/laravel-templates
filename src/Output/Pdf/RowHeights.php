<?php

namespace Controlink\Templates\Output\Pdf;

use Controlink\Templates\Support\FontMetrics;
use Controlink\Templates\Support\PageGeometry;
use Controlink\Templates\Support\TextLines;
use Controlink\Templates\Types\TemplateType;

/**
 * How tall each row of a line table prints, when its text columns wrap (CGE-89).
 *
 * A text column (visible, no format, no formula) breaks its value into as many lines as
 * the column needs; the row takes the tallest cell. The count is made against the
 * embedded font and a column a little narrower than the real one, so it errs towards one
 * line too many: that leaves white space, never rows printed over the totals.
 *
 * `null` when no row needs a second line — the signal that keeps the fixed-height
 * pagination, byte for byte. A table whose items span several rows (CGE-97) always has
 * heights: each item sums its rows, every row measured on the grid columns its cells span.
 */
class RowHeights
{
    /** The share of a column the text is measured against: kerning and the engine's own line breaking are not modelled. */
    public const SAFETY = 0.97;

    /** The cell's horizontal padding, both sides (`padding: 0 1mm` in `PageStructure::css`). */
    private const PADDING_MM = 2.0;

    /**
     * The padding to take off a column: none on a roll (`$chMm` given), whose cells
     * `PageStructure::ROLL_CELLS` sets to `padding:0` (CGE-97).
     */
    private static function padding(?float $chMm): float
    {
        return $chMm === null ? self::PADDING_MM : 0.0;
    }

    /** The line height the wrapping cells are given (`line-height: 1.2`). */
    public const LINE_HEIGHT = 1.2;

    /** The browser's default font size, for a table that states none. */
    public const DEFAULT_FONT_PT = 12.0;

    /**
     * @param  array{collection: string, id?: string, columns?: list<array<string, mixed>>, fontPt?: float|null, fontWeight?: int, widthMm?: float|null}  $table
     * @param  array<string, mixed>  $dataset
     * @return list<float>|null one height per row, in the dataset's order
     */
    public static function of(array $table, TemplateType $type, array $dataset): ?array
    {
        $columns = $table['columns'] ?? [];
        $rows    = $dataset[$table['collection']] ?? [];

        if (($table['id'] ?? '') === '' || $columns === [] || ! is_array($rows) || $rows === []) {
            return null;
        }

        $tableWidth = $table['widthMm'] ?? self::printableWidth($type);

        // An item spanning several rows (CGE-97) is as tall as its rows, each measured
        // like a row of its own. Its height is never the fixed one, so it is always given.
        $multiRow = LineRows::isMultiRow($columns);
        // A width in characters (`12ch`, CGE-97) is the roll's grid; on a sheet it is auto, as ever.
        $geometry = PageGeometry::fromType($type->pdf);
        $chMm     = $geometry->columns !== null && $geometry->charWidthMm() > 0 ? $geometry->charWidthMm() : null;
        $segments = $multiRow ? self::segmentWidths($columns, $tableWidth, $chMm) : [self::textWidths($columns, $tableWidth, $chMm)];

        if (! $multiRow && $segments[0] === []) {
            return null;
        }

        $fontPt    = (float) (($table['fontPt'] ?? 0.0) ?: self::DEFAULT_FONT_PT);
        $font      = FontMetrics::fromConfig((int) ($table['fontWeight'] ?? 400));
        $rowHeight = max(1.0, (float) ($type->pdf['line_row_height_mm'] ?? 4.5));
        $lineMm    = $fontPt * self::LINE_HEIGHT * 25.4 / 72;

        $heights = [];
        $wraps   = false;

        foreach (array_values($rows) as $row) {
            $height = 0.0;

            foreach ($segments as $widths) {
                $lines = 1;

                foreach ($widths as $key => $width) {
                    $value = data_get($row, $key);
                    $text  = is_scalar($value) ? (string) $value : '';

                    $lines = max($lines, TextLines::count($font, $text, $fontPt, $width));
                }

                $wraps  = $wraps || $lines > 1;
                $height += $rowHeight + ($lines - 1) * $lineMm;
            }

            $heights[] = $height;
        }

        return $wraps || $multiRow ? $heights : null;
    }

    /**
     * The 1-based position, among the visible columns, of each text column — the cells
     * `PageStructure::css` lets wrap.
     *
     * @param  list<array<string, mixed>>  $columns
     * @return list<int>
     */
    public static function textColumnIndexes(array $columns): array
    {
        $indexes = [];

        foreach (array_values(self::visible($columns)) as $index => $column) {
            if (self::isText($column)) {
                $indexes[] = $index + 1;
            }
        }

        return $indexes;
    }

    /**
     * Text room of each text column, keyed by the column's key, laid out like
     * `table-layout: fixed`: stated widths first, the rest shared by the auto ones.
     *
     * @param  list<array<string, mixed>>  $columns
     * @return array<string, float>
     */
    private static function textWidths(array $columns, float $tableWidth, ?float $chMm = null): array
    {
        $visible = self::visible($columns);
        $layout  = self::columnWidths($visible, $tableWidth, $chMm);

        $widths = [];

        foreach ($visible as $index => $column) {
            if (! self::isText($column) || ($column['key'] ?? '') === '') {
                continue;
            }

            $widths[(string) $column['key']] = max(0.1, ($layout[$index] - self::padding($chMm)) * self::SAFETY);
        }

        return $widths;
    }

    /**
     * Text room of each text cell of a multi-row table (CGE-97), per segment, keyed by the
     * column's key: the grid columns the cell spans, laid out like `textWidths`.
     *
     * @param  list<array<string, mixed>>  $columns
     * @return list<array<string, float>>
     */
    private static function segmentWidths(array $columns, float $tableWidth, ?float $chMm = null): array
    {
        $grid  = self::columnWidths(LineRows::grid($columns), $tableWidth, $chMm);
        $spans = LineRows::spans($columns);

        $segments = [];

        foreach (LineRows::segments($columns) as $s => $segment) {
            $widths = [];
            $at     = 0;

            foreach ($segment as $i => $column) {
                $span = $spans[$s][$i];
                $room = array_sum(array_slice($grid, $at, $span));
                $at  += $span;

                if (self::isText($column) && ($column['key'] ?? '') !== '') {
                    $widths[(string) $column['key']] = max(0.1, ($room - self::padding($chMm)) * self::SAFETY);
                }
            }

            $segments[] = $widths;
        }

        return $segments;
    }

    /**
     * Each column's width, laid out like `table-layout: fixed`: stated widths first, the
     * rest shared by the auto ones.
     *
     * @param  list<array<string, mixed>>  $visible
     * @return list<float>
     */
    private static function columnWidths(array $visible, float $tableWidth, ?float $chMm = null): array
    {
        $stated = array_map(fn (array $column): ?float => self::statedWidth($column['width'] ?? '', $chMm), $visible);
        $autos  = count(array_filter($stated, fn (?float $width): bool => $width === null));
        $share  = $autos > 0 ? max(0.0, $tableWidth - array_sum(array_filter($stated))) / $autos : 0.0;

        return array_map(fn (?float $width): float => $width ?? $share, $stated);
    }

    /**
     * A bare number is millimetres (the editor's convention); `NNmm` too; `NNch` is NN
     * characters of a roll's grid (CGE-97) when `$chMm` is given; anything else is auto.
     */
    private static function statedWidth(mixed $width, ?float $chMm = null): ?float
    {
        $width = strtolower(trim((string) $width));

        if ($chMm !== null && preg_match('/^(\d+(?:\.\d+)?)ch$/', $width, $m) === 1 && (float) $m[1] > 0) {
            return (float) $m[1] * $chMm;
        }

        if (preg_match('/^(\d+(?:\.\d+)?)(mm)?$/', $width, $m) !== 1 || (float) $m[1] <= 0) {
            return null;
        }

        return (float) $m[1];
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @return list<array<string, mixed>>
     */
    private static function visible(array $columns): array
    {
        return LineRows::visible($columns);
    }

    /**
     * Printed as typed: no format, no formula — including a calculated column saved
     * before `formulaKey` existed, which only its `formula_` key gives away. The editor's
     * `isTextColumn`.
     *
     * @param  array<string, mixed>  $column
     */
    private static function isText(array $column): bool
    {
        $printed = (string) ($column['formulaKey'] ?? $column['key'] ?? '');

        return ($column['format'] ?? '') === ''
            && empty($column['formula'])
            && ! str_starts_with($printed, 'formula_');
    }

    /** The sheet's width between its side margins: what a table with no stated width spans. */
    private static function printableWidth(TemplateType $type): float
    {
        $margins = $type->pdf['margins'] ?? [];

        return PageGeometry::fromType($type->pdf)->sheetSizeMm()['width']
            - (float) ($margins['left'] ?? 15)
            - (float) ($margins['right'] ?? 15);
    }
}
