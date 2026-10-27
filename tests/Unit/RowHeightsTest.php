<?php

namespace Controlink\Templates\Tests\Unit;

use Controlink\Templates\Output\Pdf\RowHeights;
use Controlink\Templates\Support\FontMetrics;
use Controlink\Templates\Support\TextLines;
use Controlink\Templates\Templates;
use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Types\TemplateType;

/**
 * Each line-table row's height, from its text columns measured against their widths
 * (CGE-89). `null` means nothing wraps, which keeps the old fixed-height pagination.
 */
class RowHeightsTest extends TestCase
{
    private const LINE_MM = 8 * 1.2 * 25.4 / 72;

    private TemplateType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->type = Templates::type('invoice');
    }

    public function test_short_rows_return_null(): void
    {
        $rows = [['code' => 'A1', 'description' => 'Parafuso'], ['code' => 'A2', 'description' => 'Porca']];

        $this->assertNull(RowHeights::of($this->table(), $this->type, ['lines' => $rows]));
    }

    public function test_a_long_description_raises_only_its_row(): void
    {
        $long = str_repeat('Chapa de aço galvanizado ', 12);
        $rows = [['description' => 'Curta'], ['description' => $long], ['description' => 'Curta']];

        $lines = TextLines::count(FontMetrics::fromConfig(400), $long, 8, (80 - 2) * 0.97);

        $this->assertGreaterThan(1, $lines);
        $this->assertEqualsWithDelta(
            [4.5, 4.5 + ($lines - 1) * self::LINE_MM, 4.5],
            RowHeights::of($this->table(), $this->type, ['lines' => $rows]),
            0.0001,
        );
    }

    public function test_the_tallest_cell_decides_the_row(): void
    {
        $table = $this->table(columns: [
            ['key' => 'a', 'width' => '20mm', 'format' => ''],
            ['key' => 'b', 'width' => '20mm', 'format' => ''],
        ]);

        $two   = str_repeat('palavra ', 6);
        $three = str_repeat('palavra ', 12);
        $font  = FontMetrics::fromConfig(400);
        $max   = max(TextLines::count($font, $two, 8, 18 * 0.97), TextLines::count($font, $three, 8, 18 * 0.97));

        $heights = RowHeights::of($table, $this->type, ['lines' => [['a' => $two, 'b' => $three]]]);

        $this->assertEqualsWithDelta([4.5 + ($max - 1) * self::LINE_MM], $heights, 0.0001);
    }

    public function test_formatted_and_formula_columns_never_wrap(): void
    {
        $table = $this->table(columns: [
            ['key' => 'amount', 'width' => '10mm', 'format' => 'money'],
            ['key' => 'calc', 'width' => '10mm', 'format' => '', 'formula' => 'lines.a & lines.b'],
            ['key' => 'hidden', 'width' => '10mm', 'format' => '', 'hidden' => true],
            // A calculated column saved before `formulaKey` existed: its key alone says so.
            ['key' => 'formula_ab12', 'width' => '10mm', 'format' => ''],
        ]);

        $long = str_repeat('texto longo ', 20);

        $this->assertNull(RowHeights::of($table, $this->type, ['lines' => [['amount' => $long, 'calc' => $long, 'hidden' => $long, 'formula_ab12' => $long]]]));
    }

    public function test_auto_columns_share_the_remaining_width(): void
    {
        $table = $this->table(width: 100.0, columns: [
            ['key' => 'fixed', 'width' => '20', 'format' => ''],
            ['key' => 'a', 'width' => '', 'format' => ''],
            ['key' => 'b', 'width' => '', 'format' => ''],
        ]);

        // Each auto column is 40 mm, so 38 mm of text room before the safety margin.
        $font = FontMetrics::fromConfig(400);

        $this->assertNull(RowHeights::of($table, $this->type, ['lines' => [['a' => $this->textOfWidth($font, 34.0)]]]));
        $this->assertNotNull(RowHeights::of($table, $this->type, ['lines' => [['a' => $this->textOfWidth($font, 39.0)]]]));
    }

    public function test_a_table_without_a_width_takes_the_printable_width(): void
    {
        // A4 minus 15 + 15 mm margins = 180 mm, one auto column.
        $table = $this->table(width: null, columns: [['key' => 'a', 'width' => '', 'format' => '']]);
        $font  = FontMetrics::fromConfig(400);

        $this->assertNull(RowHeights::of($table, $this->type, ['lines' => [['a' => $this->textOfWidth($font, 160.0)]]]));
        $this->assertNotNull(RowHeights::of($table, $this->type, ['lines' => [['a' => $this->textOfWidth($font, 176.0)]]]));
    }

    public function test_null_and_numeric_values_measure_as_text(): void
    {
        $this->assertNull(RowHeights::of($this->table(), $this->type, ['lines' => [['description' => null], ['description' => 12345]]]));
    }

    public function test_linked_column_keys_read_nested_values(): void
    {
        $table = $this->table(columns: [['key' => 'family.name', 'width' => '20mm', 'format' => '']]);

        $this->assertNotNull(RowHeights::of($table, $this->type, ['lines' => [['family' => ['name' => str_repeat('nome ', 30)]]]]));
    }

    public function test_a_table_without_columns_or_id_never_wraps(): void
    {
        $long = [['description' => str_repeat('texto longo ', 40)]];

        $this->assertNull(RowHeights::of($this->table(columns: []), $this->type, ['lines' => $long]));
        $this->assertNull(RowHeights::of(['id' => ''] + $this->table(), $this->type, ['lines' => $long]));
    }

    public function test_bold_tables_measure_with_the_bold_face(): void
    {
        // Fits the description column in regular; bold is wider.
        $text = $this->textOfWidth(FontMetrics::fromConfig(400), 74.5);

        $this->assertNull(RowHeights::of($this->table(), $this->type, ['lines' => [['description' => $text]]]));
        $this->assertNotNull(RowHeights::of($this->table(weight: 700), $this->type, ['lines' => [['description' => $text]]]));
    }

    public function test_multi_row_item_height_sums_its_segments(): void
    {
        // Grid = the 2nd segment, four 20 mm columns; `description` spans grid columns 2-4.
        $table = $this->table(columns: [
            ['key' => 'code', 'width' => '20', 'format' => ''],
            ['key' => 'description', 'width' => '', 'format' => ''],
            ['key' => 'unit_price', 'width' => '20', 'format' => 'money', 'break' => true],
            ['key' => 'quantity', 'width' => '20', 'format' => 'number'],
            ['key' => 'vat_rate', 'width' => '20', 'format' => 'percent'],
            ['key' => 'amount', 'width' => '20', 'format' => 'money'],
        ]);

        // Two segments, nothing wraps: still a list, each item 2 rows tall.
        $short = [['code' => 'A1', 'description' => 'Parafuso'], ['code' => 'A2', 'description' => 'Porca']];
        $this->assertEqualsWithDelta([9.0, 9.0], RowHeights::of($table, $this->type, ['lines' => $short]), 0.0001);

        // A description that breaks into 3 lines across the 60 mm it spans.
        $font = FontMetrics::fromConfig(400);
        $long = 'Chapa';
        while (TextLines::count($font, $long, 8, (60 - 2) * 0.97) < 3) {
            $long .= ' galvanizada';
        }
        $this->assertSame(3, TextLines::count($font, $long, 8, (60 - 2) * 0.97));

        $this->assertEqualsWithDelta(
            [9.0, 9.0 + 2 * self::LINE_MM],
            RowHeights::of($table, $this->type, ['lines' => [['description' => 'Curta'], ['description' => $long]]]),
            0.0001,
        );
    }

    /**
     * The table record `PageStructure::tablesIn` produces.
     *
     * @param  list<array<string, mixed>>|null  $columns
     * @return array<string, mixed>
     */
    private function table(?float $width = 199.0, ?array $columns = null, int $weight = 400): array
    {
        return [
            'html'       => '',
            'collection' => 'lines',
            'top'        => 0.0,
            'id'         => 'tpl-lines',
            'columns'    => $columns ?? [
                ['key' => 'code', 'width' => '24.4', 'format' => ''],
                ['key' => 'description', 'width' => '80', 'format' => ''],
                ['key' => 'amount', 'width' => '', 'format' => 'money'],
            ],
            'fontPt'     => 8.0,
            'fontWeight' => $weight,
            'widthMm'    => $width,
        ];
    }

    /** A run of words just under `$mm` wide at 8 pt in `$font`. */
    private function textOfWidth(FontMetrics $font, float $mm): string
    {
        $text = 'a';

        while ($font->widthMm($text.' abc', 8) < $mm) {
            $text .= ' abc';
        }

        // Then letter by letter, so the run lands within a letter of `$mm`.
        while ($font->widthMm($text.'b', 8) < $mm) {
            $text .= 'b';
        }

        return $text;
    }
}
