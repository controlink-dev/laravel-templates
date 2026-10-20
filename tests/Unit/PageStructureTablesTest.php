<?php

namespace Controlink\Templates\Tests\Unit;

use Controlink\Templates\Output\Pdf\PageStructure;
use PHPUnit\Framework\TestCase;

class PageStructureTablesTest extends TestCase
{
    private function table(string $collection, ?int $top): string
    {
        $style = $top === null ? '' : ' style="top:'.$top.'mm"';

        return '<div data-tpl-type="line-table" data-tpl-collection="'.$collection.'"'.$style.'><table></table></div>';
    }

    public function test_tables_are_ordered_by_top_not_document_order(): void
    {
        $body   = $this->table('b', 120).$this->table('a', 40);
        $tables = (new PageStructure)->tablesIn($body);

        $this->assertCount(2, $tables);
        $this->assertSame('a', $tables[0]['collection']);
        $this->assertSame(40.0, $tables[0]['top']);
        $this->assertSame('b', $tables[1]['collection']);
        $this->assertSame(120.0, $tables[1]['top']);
        $this->assertStringContainsString('data-tpl-collection="a"', $tables[0]['html']);
    }

    public function test_no_line_table_gives_empty_list(): void
    {
        $this->assertSame([], (new PageStructure)->tablesIn('<div>nothing</div>'));
    }

    public function test_single_table_matches_table_in(): void
    {
        $body   = '<p>x</p>'.$this->table('lines', 55);
        $tables = (new PageStructure)->tablesIn($body);

        $this->assertCount(1, $tables);
        $this->assertSame($tables[0], (new PageStructure)->tableIn($body));
        $this->assertSame('lines', $tables[0]['collection']);
        $this->assertSame(55.0, $tables[0]['top']);
    }

    /* -----------------------------------------------------------------------
     * The real editor writes a dragged block's `top` into the version CSS
     * (`avoidInlineStyle: true`), not inline. `tablesIn` must read it from there.
     * -------------------------------------------------------------------- */

    private function tableWithId(string $collection, string $id): string
    {
        return '<div data-tpl-type="line-table" data-tpl-collection="'.$collection.'" id="'.$id.'"><table></table></div>';
    }

    public function test_top_comes_from_a_css_id_rule_when_there_is_no_inline_style(): void
    {
        $body = $this->tableWithId('lines', 'tpl-lines');
        $css  = '#tpl-lines{left:10mm;top:47mm}';

        $tables = (new PageStructure)->tablesIn($body, $css);

        $this->assertCount(1, $tables);
        $this->assertSame(47.0, $tables[0]['top']);

        // Without the CSS the top is unknown and resolves to 0 — the defect.
        $this->assertSame(0.0, (new PageStructure)->tablesIn($body)[0]['top']);
    }

    public function test_css_top_orders_tables_against_dom_order(): void
    {
        // B is first in the DOM but sits lower on the page; only the CSS top tells
        // them apart, and A (the higher one) must come first in the flow.
        $body = $this->tableWithId('b', 'tpl-b').$this->tableWithId('a', 'tpl-a');
        $css  = '#tpl-a{top:30mm}#tpl-b{top:110mm}';

        $tables = (new PageStructure)->tablesIn($body, $css);

        $this->assertSame('a', $tables[0]['collection']);
        $this->assertSame(30.0, $tables[0]['top']);
        $this->assertSame('b', $tables[1]['collection']);
        $this->assertSame(110.0, $tables[1]['top']);

        // Ignoring the CSS, both collapse to 0 and keep DOM order (B before A) — wrong.
        $ignored = (new PageStructure)->tablesIn($body);
        $this->assertSame('b', $ignored[0]['collection']);
        $this->assertSame(0.0, $ignored[0]['top']);
    }

    public function test_inline_top_wins_over_a_conflicting_css_rule(): void
    {
        $body = '<div data-tpl-type="line-table" data-tpl-collection="lines" id="tpl-lines" style="top:12mm"><table></table></div>';
        $css  = '#tpl-lines{top:80mm}';

        $tables = (new PageStructure)->tablesIn($body, $css);

        $this->assertSame(12.0, $tables[0]['top']);
    }

    public function test_margin_top_is_not_read_as_top(): void
    {
        // The inline `margin-top` must not shadow the real `top` in the #id rule.
        $body = '<div data-tpl-type="line-table" data-tpl-collection="lines" id="tpl-lines" style="margin-top:5mm"><table></table></div>';
        $css  = '#tpl-lines{top:30mm}';

        $tables = (new PageStructure)->tablesIn($body, $css);

        $this->assertSame(30.0, $tables[0]['top']);
    }

    public function test_top_can_come_from_a_class_rule(): void
    {
        $body = '<div data-tpl-type="line-table" data-tpl-collection="lines" class="block block--lines"><table></table></div>';
        $css  = '.block--lines{top:25mm}';

        $tables = (new PageStructure)->tablesIn($body, $css);

        $this->assertSame(25.0, $tables[0]['top']);
    }
}
