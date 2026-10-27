<?php

namespace Controlink\Templates\Tests\Unit;

use Controlink\Templates\Output\Pdf\PageStructure;
use Controlink\Templates\Templates;
use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Types\TemplateType;

/**
 * Totals carried across the pages of a document (CGE-79).
 *
 * A type that declares `pdf.carry` paginates with a carried-forward row at the top of
 * every page after the first and knows, per page, what it carries in and out. A type
 * without it paginates exactly as before.
 */
class CarriedTotalsPaginationTest extends TestCase
{
    public function test_without_carry_no_page_values_are_added(): void
    {
        $pages = (new PageStructure)->build($this->body([['a', 0, false]]), Templates::type('invoice'), ['a' => $this->rows('a', 120)])['pages'];

        foreach ($pages as $page) {
            $this->assertArrayNotHasKey(PageStructure::IS_LAST, $page['dataset']);
            $this->assertArrayNotHasKey(PageStructure::CARRIED_IN, $page['dataset']);
        }
    }

    public function test_carried_values_per_page(): void
    {
        $pages = (new PageStructure)->build($this->body([['a', 0, false]]), $this->carryType(), ['a' => $this->rows('a', 120)])['pages'];

        $this->assertGreaterThan(2, count($pages));

        $before = 0.0;

        foreach ($pages as $index => $page) {
            $here   = (float) array_sum(array_column($page['dataset']['a'], 'v'));
            $isLast = $index === count($pages) - 1;

            $this->assertSame($before, $page['dataset'][PageStructure::CARRIED_IN], "page {$index}");
            $this->assertSame($before + $here, $page['dataset'][PageStructure::CARRIED_OUT], "page {$index}");
            $this->assertSame($isLast, $page['dataset'][PageStructure::IS_LAST], "page {$index}");

            $before += $here;
        }

        $this->assertSame(0.0, $pages[0]['dataset'][PageStructure::CARRIED_IN]);
        $this->assertSame(1200.0, end($pages)['dataset'][PageStructure::CARRIED_OUT]);
    }

    public function test_continuation_pages_hold_one_row_less(): void
    {
        $type  = $this->carryType();
        $pages = (new PageStructure)->build($this->body([['a', 0, false]]), $type, ['a' => $this->rows('a', 200)])['pages'];

        $perPage = (new PageStructure)->rowsPerPage($type, 0.0);

        $this->assertGreaterThan(2, count($pages));
        $this->assertCount($perPage, $pages[0]['dataset']['a']);

        foreach (array_slice($pages, 1, -1) as $index => $page) {
            $this->assertCount($perPage - 1, $page['dataset']['a'], 'page '.($index + 1));
        }
    }

    public function test_a_single_page_document_is_its_own_last_page(): void
    {
        $pages = (new PageStructure)->build($this->body([['a', 0, false]]), $this->carryType(), ['a' => $this->rows('a', 3)])['pages'];

        $this->assertCount(1, $pages);
        $this->assertSame(0.0, $pages[0]['dataset'][PageStructure::CARRIED_IN]);
        $this->assertSame(30.0, $pages[0]['dataset'][PageStructure::CARRIED_OUT]);
        $this->assertTrue($pages[0]['dataset'][PageStructure::IS_LAST]);
    }

    public function test_non_numeric_amounts_count_as_zero(): void
    {
        $rows = [['c' => 'x', 'v' => null], ['c' => 'y', 'v' => ''], ['c' => 'z', 'v' => 'abc'], ['c' => 'w', 'v' => '12.5'], ['c' => 'k']];

        $pages = (new PageStructure)->build($this->body([['a', 0, false]]), $this->carryType(), ['a' => $rows])['pages'];

        $this->assertSame(12.5, $pages[0]['dataset'][PageStructure::CARRIED_OUT]);
    }

    public function test_wrapping_rows_with_carry_never_reach_the_reserve(): void
    {
        $type = $this->carryType();
        $rows = [];

        for ($i = 1; $i <= 80; $i++) {
            $rows[] = ['c' => $i % 3 === 0 ? implode("\n", array_fill(0, 3, 'linha')) : 'curta', 'v' => 1];
        }

        $structure = new PageStructure;
        $pages     = $structure->build($this->body([['a', 0, false]], wrapping: true), $type, ['a' => $rows])['pages'];

        // A row of 8 pt text at the default line height, the unit RowHeights measures with.
        $all     = array_merge(...array_map(fn (array $page): array => $page['dataset']['a'], $pages));
        $rowsFit = $structure->rowsPerPage($type, 0.0);
        $this->assertSame($rows, $all, 'every row printed once, in order');
        $this->assertGreaterThan(1, count($pages));

        // The carried row costs a row on every page after the first: the same rows with no
        // carry would fit at least as many on each continuation page.
        $plain = (new PageStructure)->build($this->body([['a', 0, false]], wrapping: true), Templates::type('invoice'), ['a' => $rows])['pages'];
        $this->assertGreaterThanOrEqual(count($plain), count($pages));
        $this->assertLessThanOrEqual($rowsFit, count($pages[0]['dataset']['a']));
        $this->assertSame(count($plain[0]['dataset']['a']), count($pages[0]['dataset']['a']), 'page 1 has no carried row');

        if (count($plain) > 1 && count($pages) > 2) {
            $this->assertLessThanOrEqual(count($plain[1]['dataset']['a']), count($pages[1]['dataset']['a']));
        }
    }

    /** The test invoice, carrying column `v` of collection `a`. */
    private function carryType(): TemplateType
    {
        $config = (require __DIR__.'/../Fixtures/types/invoice.php')['invoice'];

        $config['pdf']['carry'] = ['collection' => 'a', 'column' => 'v'];

        return TemplateType::fromConfig('invoice', $config);
    }

    /**
     * @param  list<array{0: string, 1: int, 2: bool}>  $tables  collection, top, fixed
     */
    private function body(array $tables, bool $wrapping = false): string
    {
        $columns = htmlspecialchars((string) json_encode([['key' => 'c', 'label' => 'C', 'width' => '40mm', 'align' => 'left', 'format' => '']]));
        $body    = '';

        foreach ($tables as [$collection, $top, $fixed]) {
            $body .= '<div class="block block--lines" data-tpl-type="line-table" data-tpl-collection="'.$collection.'"'
                .($fixed ? ' data-tpl-anchor="last-page"' : '')
                .($wrapping ? ' data-tpl-columns="'.$columns.'"' : '')
                .' id="tpl-'.$collection.'" style="top:'.$top.'mm'.($wrapping ? ';width:100mm;font-size:8pt' : '').'">'
                .'<table class="lines"><thead><tr><th>C</th><th>V</th></tr></thead><tbody>'
                .'{% for linha in '.$collection.' %}<tr><td>{{ linha.c }}</td><td>{{ linha.v|money(3, \'none\') }}</td></tr>{% endfor %}'
                .'</tbody></table></div>';
        }

        $body .= '<div class="block" data-tpl-type="static-text" id="tpl-label">TOTAL</div>'
            .'<div class="block" data-tpl-type="data-field" data-tpl-binding="totais.total" id="tpl-totals">{{ totais.total }}</div>';

        return '<div class="page">'
            .'<div class="zone zone--header" data-tpl-zone="header"></div>'
            .'<div class="zone zone--body" data-tpl-zone="body">'.$body.'</div>'
            .'<div class="zone zone--footer" data-tpl-zone="footer"></div>'
            .'</div>';
    }

    /** @return array<int, array{c: string, v: int}> */
    private function rows(string $prefix, int $count): array
    {
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ['c' => $prefix.'-'.$i, 'v' => 10];
        }

        return $rows;
    }
}
