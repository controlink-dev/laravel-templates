<?php

namespace Controlink\Templates\Tests\Unit;

use Controlink\Templates\Output\Pdf\LineRows;
use Controlink\Templates\Tests\TestCase;

/**
 * A line table whose items span several rows (CGE-97): the columns split before each
 * `break`, into segments laid on one shared grid.
 */
class LineRowsTest extends TestCase
{
    public function test_columns_split_before_each_break(): void
    {
        $cols = [['key' => 'code'], ['key' => 'description'], ['key' => 'unit_price', 'break' => true], ['key' => 'quantity'], ['key' => 'vat_rate'], ['key' => 'amount']];

        $this->assertSame([['code', 'description'], ['unit_price', 'quantity', 'vat_rate', 'amount']], $this->keys(LineRows::segments($cols)));
        $this->assertTrue(LineRows::isMultiRow($cols));
        $this->assertSame([[1, 3], [1, 1, 1, 1]], LineRows::spans($cols));
        $this->assertSame(['unit_price', 'quantity', 'vat_rate', 'amount'], array_column(LineRows::grid($cols), 'key'));
    }

    public function test_hidden_break_moves_to_the_next_visible_column_and_a_leading_break_is_ignored(): void
    {
        $hidden = [['key' => 'a'], ['key' => 'b', 'break' => true, 'hidden' => true], ['key' => 'c'], ['key' => 'd']];
        $this->assertSame([['a'], ['c', 'd']], $this->keys(LineRows::segments($hidden)));

        $leading = [['key' => 'a', 'break' => true], ['key' => 'b']];
        $this->assertSame([['a', 'b']], $this->keys(LineRows::segments($leading)));
        $this->assertFalse(LineRows::isMultiRow($leading));
    }

    public function test_without_a_break_there_is_one_segment_of_the_visible_columns(): void
    {
        $cols = [['key' => 'a'], ['key' => 'b', 'hidden' => true], ['key' => 'c', 'break' => 'yes']];

        $this->assertSame([['a', 'c']], $this->keys(LineRows::segments($cols)));
        $this->assertFalse(LineRows::isMultiRow($cols));
        $this->assertSame([[1, 1]], LineRows::spans($cols));
        $this->assertSame([[]], LineRows::segments([]));
    }

    public function test_the_first_longest_segment_is_the_grid(): void
    {
        $cols = [['key' => 'a'], ['key' => 'b'], ['key' => 'c', 'break' => true], ['key' => 'd'], ['key' => 'e', 'break' => true]];

        $this->assertSame(['a', 'b'], array_column(LineRows::grid($cols), 'key'));
        $this->assertSame([[1, 1], [1, 1], [2]], LineRows::spans($cols));
    }

    /**
     * @param  list<list<array<string, mixed>>>  $segments
     * @return list<list<string>>
     */
    private function keys(array $segments): array
    {
        return array_map(fn (array $segment): array => array_column($segment, 'key'), $segments);
    }
}
