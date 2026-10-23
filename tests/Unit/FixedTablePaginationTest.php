<?php

namespace Controlink\Templates\Tests\Unit;

use Controlink\Templates\Output\Pdf\PageStructure;
use Controlink\Templates\Templates;
use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Types\TemplateType;

/**
 * A line table fixed on the last page (CGE-67): the tax summary beside the totals.
 *
 * It leaves the flow and is drawn where the canvas put it, with all its rows, on the last
 * page only — but only while it sits in the band the flow never fills and all its rows
 * fit above the footer. Otherwise it flows like any other table, so a mandatory table is
 * never clipped nor drawn over the lines.
 */
class FixedTablePaginationTest extends TestCase
{
    public function test_a_fixed_table_that_fits_is_drawn_on_the_last_page_only(): void
    {
        $type = Templates::type('invoice');
        $top  = $this->reserveTop($type);

        $body    = $this->body([['a', 0, false], ['b', (int) ceil($top), true]]);
        $dataset = ['a' => $this->rows('a', 60), 'b' => $this->rows('b', 3)];

        $pages = (new PageStructure)->build($body, $type, $dataset)['pages'];

        // The lines paginate exactly as if the fixed table were not there.
        $alone = (new PageStructure)->build($this->body([['a', 0, false]]), $type, ['a' => $this->rows('a', 60)])['pages'];
        $this->assertCount(count($alone), $pages);
        $this->assertGreaterThan(1, count($pages));

        foreach ($pages as $index => $page) {
            $isLast = $index === count($pages) - 1;

            $this->assertSame($isLast, str_contains($page['template'], 'data-tpl-collection="b"'), "page {$index}");
            // Its rows are never split: every page's dataset still holds all of them.
            $this->assertCount(3, $page['dataset']['b']);
        }

        // Drawn where the canvas put it, not moved to a cursor.
        $this->assertStringContainsString('top:'.(int) ceil($top).'mm', end($pages)['template']);
    }

    public function test_a_fixed_table_with_more_rows_than_its_slot_flows_instead(): void
    {
        $type = Templates::type('invoice');
        $top  = (int) ceil($this->reserveTop($type));

        // Far more rows than the reserve can hold: it must not be clipped.
        $body    = $this->body([['a', 0, false], ['b', $top, true]]);
        $dataset = ['a' => $this->rows('a', 5), 'b' => $this->rows('b', 40)];

        $pages = (new PageStructure)->build($body, $type, $dataset)['pages'];

        $seen = 0;
        foreach ($pages as $page) {
            if (str_contains($page['template'], 'data-tpl-collection="b"')) {
                $seen += count($page['dataset']['b']);
            }
        }

        $this->assertSame(40, $seen, 'every row of the table printed exactly once, across pages');
    }

    public function test_a_fixed_table_above_the_reserve_flows_instead(): void
    {
        $type = Templates::type('invoice');

        // Placed where the lines run: fixing it there would draw it over them.
        $body    = $this->body([['a', 0, false], ['b', 40, true]]);
        $dataset = ['a' => $this->rows('a', 60), 'b' => $this->rows('b', 3)];

        $fixed   = (new PageStructure)->build($body, $type, $dataset)['pages'];
        $flowing = (new PageStructure)->build($this->body([['a', 0, false], ['b', 40, false]]), $type, $dataset)['pages'];

        // The same pages as a plain flowing table; only the marker attribute differs.
        $strip = fn (array $pages): array => array_map(
            fn (array $page): array => ['template' => str_replace(' data-tpl-anchor="last-page"', '', $page['template'])] + $page,
            $pages,
        );
        $this->assertSame($flowing, $strip($fixed));
    }

    public function test_tables_in_reports_the_anchor(): void
    {
        $tables = (new PageStructure)->tablesIn($this->body([['a', 0, false], ['b', 120, true]]));

        $this->assertFalse($tables[0]['anchored']);
        $this->assertTrue($tables[1]['anchored']);
    }

    /** Where the band the flow never fills begins, measured from the top of the body. */
    private function reserveTop(TemplateType $type): float
    {
        $margins = $type->pdf['margins'] ?? [];
        $zones   = $type->initialLayout['zones'] ?? [];

        return 297.0
            - (float) ($margins['top'] ?? 15)
            - (float) ($margins['bottom'] ?? 15)
            - (float) ($zones['header_height'] ?? 35)
            - (float) ($zones['footer_height'] ?? 20)
            - (float) ($type->pdf['body_reserve_mm'] ?? 0);
    }

    /**
     * @param  list<array{0: string, 1: int, 2: bool}>  $tables  collection, top, fixed
     */
    private function body(array $tables): string
    {
        $body = '';

        foreach ($tables as [$collection, $top, $fixed]) {
            $body .= '<div class="block block--lines" data-tpl-type="line-table" data-tpl-collection="'.$collection.'"'
                .($fixed ? ' data-tpl-anchor="last-page"' : '')
                .' id="tpl-'.$collection.'" style="top:'.$top.'mm">'
                .'<table class="lines"><thead><tr><th>C</th></tr></thead><tbody>'
                .'{% for linha in '.$collection.' %}<tr><td>{{ linha.c }}</td></tr>{% endfor %}'
                .'</tbody></table></div>';
        }

        $body .= '<div class="block" data-tpl-type="data-field" data-tpl-binding="totais.total" id="tpl-totals">{{ totais.total }}</div>';

        return '<div class="page">'
            .'<div class="zone zone--header" data-tpl-zone="header"></div>'
            .'<div class="zone zone--body" data-tpl-zone="body">'.$body.'</div>'
            .'<div class="zone zone--footer" data-tpl-zone="footer"></div>'
            .'</div>';
    }

    /** @return array<int, array{c: string}> */
    private function rows(string $prefix, int $count): array
    {
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ['c' => $prefix.'-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)];
        }

        return $rows;
    }
}
