<?php

namespace Controlink\Templates\Tests\Unit;

use Controlink\Templates\Output\Pdf\PageStructure;
use Controlink\Templates\Templates;
use Controlink\Templates\Tests\Concerns\BuildsFixtureDataset;
use Controlink\Templates\Tests\Feature\Concerns\BuildsPdfLayouts;
use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Types\TemplateType;

/**
 * The compositor paginates N line tables as one stacked flow across shared pages.
 *
 * Two guards live here. The first is a golden snapshot: the exact `build()` output for
 * the existing single-table fixtures — the fiscal invoice (multi-page), a roll, the
 * boundary row counts — captured before this feature was written and asserted
 * string-for-string so a change to the engine cannot silently drift the bytes a
 * certified document is made of. The second is the new behaviour: two tables flowing in
 * order, the second continuing where the first ends, each page carrying only the tables
 * that have rows on it, and a bound collection with no rows rendering header-only once.
 */
class MultiTablePaginationTest extends TestCase
{
    use BuildsFixtureDataset;
    use BuildsPdfLayouts;

    /* -----------------------------------------------------------------------
     * Retrocompat — a single table paginates exactly as it did before.
     * -------------------------------------------------------------------- */

    /**
     * The golden snapshot. Every single-table scenario `build()` produced before the
     * multi-table flow existed must produce byte-for-byte the same today.
     */
    public function test_single_table_scenarios_are_byte_identical_to_the_golden(): void
    {
        $path = $this->goldenPath();

        $this->assertFileExists($path, 'The golden snapshot is missing; it must be captured before the engine is edited.');

        /** @var array<string, mixed> $golden */
        $golden = unserialize((string) file_get_contents($path), ['allowed_classes' => false]);

        foreach ($this->retrocompatScenarios() as $name => $scenario) {
            $actual = (new PageStructure)->build(
                $scenario['editorHtml'],
                $scenario['type'],
                $scenario['dataset'],
            );

            $this->assertArrayHasKey($name, $golden, "the golden has no scenario '{$name}'");
            $this->assertSame($golden[$name], $actual, "scenario '{$name}' drifted from the golden");
        }
    }

    /* -----------------------------------------------------------------------
     * Two tables, stacked flow, shared pages.
     * -------------------------------------------------------------------- */

    public function test_two_tables_flow_in_order_sharing_pages(): void
    {
        $type    = Templates::type('invoice');
        $body    = $this->twoTableBody(aTop: 40, bTop: 90);
        $dataset = ['a' => $this->rows('a', 8), 'b' => $this->rows('b', 30), 'totais.total' => 123.0];

        $result = (new PageStructure)->build($body, $type, $dataset);
        $pages  = $result['pages'];

        // page 1 carries all of A (8) and as much of B as fits (13); page 2 the rest of B.
        $this->assertCount(2, $pages);

        $this->assertCount(8, $pages[0]['dataset']['a']);
        $this->assertCount(13, $pages[0]['dataset']['b']);

        // A is exhausted: its dataset is [] and its markup is gone from the later page.
        $this->assertSame([], $pages[1]['dataset']['a']);
        $this->assertCount(17, $pages[1]['dataset']['b']);

        // Every B row appears once across the pages, in order.
        $this->assertSame('b-01', $pages[0]['dataset']['b'][0]['c']);
        $this->assertSame('b-14', $pages[1]['dataset']['b'][0]['c']);
        $this->assertSame(30, count($pages[0]['dataset']['b']) + count($pages[1]['dataset']['b']));

        // Page numbers reflect the real total.
        $this->assertSame(1, $pages[0]['dataset']['pagina.numero']);
        $this->assertSame(2, $pages[1]['dataset']['pagina.numero']);
        $this->assertSame(2, $pages[0]['dataset']['pagina.total']);
        $this->assertSame(2, $pages[1]['dataset']['pagina.total']);

        // Each page's template carries only the tables with rows on it (ruling T2-C).
        $this->assertStringContainsString('data-tpl-collection="a"', $pages[0]['template']);
        $this->assertStringContainsString('data-tpl-collection="b"', $pages[0]['template']);
        $this->assertStringNotContainsString('data-tpl-collection="a"', $pages[1]['template']);
        $this->assertStringContainsString('data-tpl-collection="b"', $pages[1]['template']);

        // Trailing blocks (the totals) only on the last page.
        $this->assertStringNotContainsString('tpl-totals', $pages[0]['template']);
        $this->assertStringContainsString('tpl-totals', $pages[1]['template']);
    }

    /**
     * The second table begins on the same page when a header and at least one row still
     * fit below the first, and on a new page when they do not. The boundary is one row.
     */
    public function test_the_second_table_starts_a_new_page_only_when_it_does_not_fit(): void
    {
        $type = Templates::type('invoice');
        $body = $this->twoTableBody(aTop: 40, bTop: 90);

        // A ends leaving room for exactly one more row: B fits, one page.
        $fits = (new PageStructure)->build($body, $type, ['a' => $this->rows('a', 20), 'b' => $this->rows('b', 1)]);
        $this->assertCount(1, $fits['pages']);

        // One more row of A and nothing fits below it: B spills to a second page.
        $spills = (new PageStructure)->build($body, $type, ['a' => $this->rows('a', 21), 'b' => $this->rows('b', 1)]);
        $this->assertCount(2, $spills['pages']);
        $this->assertStringNotContainsString('data-tpl-collection="b"', $spills['pages'][0]['template']);
        $this->assertStringContainsString('data-tpl-collection="b"', $spills['pages'][1]['template']);
    }

    /**
     * The two tables, rendered the whole way through the sandbox: each row appears once,
     * nothing collides, and no template expression survives — the failure T2-E warns of
     * (two tables rendering the same rows) only shows up once the loops actually run.
     */
    public function test_two_tables_render_each_collection_once_through_the_sandbox(): void
    {
        $type    = Templates::type('invoice');
        $body    = $this->twoTableBody(aTop: 40, bTop: 90);
        $dataset = [
            'a' => $this->rows('a', 8),
            'b' => $this->rows('b', 30),
            'totais.total'              => 123.0,
            'documento.atcud'           => 'JFT7-1',
            'documento.hash_caracteres' => 'ABCD',
        ];

        $document = app(\Controlink\Templates\Output\Pdf\DocumentComposer::class)
            ->compose('<body>'.$body.'</body>', '', $type, $dataset);

        // Nothing left for the engine to interpret.
        $this->assertStringNotContainsString('{{', $document);
        $this->assertStringNotContainsString('{%', $document);

        // Every row of each collection reaches the page exactly once — no collection
        // rendered twice, none lost.
        for ($i = 1; $i <= 8; $i++) {
            $this->assertSame(1, substr_count($document, 'a-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)));
        }
        for ($i = 1; $i <= 30; $i++) {
            $this->assertSame(1, substr_count($document, 'b-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)));
        }

        // Page 2 carries the tail of B and none of A.
        $pages = explode('<div class="page">', $document);
        $this->assertCount(3, $pages); // [preamble, page 1, page 2]

        for ($i = 1; $i <= 8; $i++) {
            $this->assertStringNotContainsString('>a-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), $pages[2]);
        }
    }

    /* -----------------------------------------------------------------------
     * The `top` arrives in the version CSS, not inline (avoidInlineStyle: true).
     * -------------------------------------------------------------------- */

    /**
     * A single table positioned only by a `#id{top}` rule paginates by that top: the
     * capacity reflects the lower body start. Ignoring the CSS (top → 0) would fit it
     * on one page, so the two-page result is proof the CSS reaches the arithmetic.
     */
    public function test_build_paginates_a_css_positioned_table_by_its_css_top(): void
    {
        $type = Templates::type('invoice');
        $ps   = new PageStructure;

        // A lower start leaves room for fewer rows; both computed, never hardcoded.
        $capacityLow  = $ps->rowsPerPage($type, 120.0);
        $capacityFull = $ps->rowsPerPage($type, 0.0);
        $this->assertGreaterThan($capacityLow, $capacityFull);

        // A row count that fits from the top of the body but overflows from 120mm down.
        $n = $capacityLow + 1;
        $this->assertLessThanOrEqual($capacityFull, $n);

        $body    = $this->cssPositionedSingleTableBody();
        $css     = '#tpl-lines{top:120mm}';
        $dataset = ['lines' => $this->rows('lines', $n)];

        // With the CSS top: two pages.
        $this->assertCount(2, $ps->build($body, $type, $dataset, $css)['pages']);

        // Without it the top collapses to 0 and it fits on one — the defect.
        $this->assertCount(1, $ps->build($body, $type, $dataset)['pages']);
    }

    /**
     * The same, end to end through `DocumentComposer::compose`: this is the defect
     * site — compose has the version CSS but must forward it to the compositor. The
     * two sheets prove the `$css` argument reaches `build()`.
     */
    public function test_compose_forwards_the_version_css_so_a_css_top_paginates(): void
    {
        $type = Templates::type('invoice');
        $ps   = new PageStructure;

        $capacityLow  = $ps->rowsPerPage($type, 120.0);
        $capacityFull = $ps->rowsPerPage($type, 0.0);
        $this->assertGreaterThan($capacityLow, $capacityFull);

        $n = $capacityLow + 1;
        $this->assertLessThanOrEqual($capacityFull, $n);

        $body    = $this->cssPositionedSingleTableBody();
        $dataset = ['lines' => $this->rows('lines', $n)];

        $document = app(\Controlink\Templates\Output\Pdf\DocumentComposer::class)
            ->compose('<body>'.$body.'</body>', '#tpl-lines{top:120mm}', $type, $dataset);

        $this->assertSame(2, substr_count($document, '<div class="page">'));
    }

    /** A single line table placed only by a `#id{top}` CSS rule — no inline top. */
    private function cssPositionedSingleTableBody(): string
    {
        $table = '<div class="block block--lines" data-tpl-type="line-table" data-tpl-collection="lines" id="tpl-lines">'
            .'<table class="lines"><thead><tr><th>C</th></tr></thead><tbody>'
            .'{% for linha in lines %}<tr><td>{{ linha.c }}</td></tr>{% endfor %}'
            .'</tbody></table></div>';

        return '<div class="page">'
            .'<div class="zone zone--header" data-tpl-zone="header"></div>'
            .'<div class="zone zone--body" data-tpl-zone="body">'.$table.'</div>'
            .'<div class="zone zone--footer" data-tpl-zone="footer"></div>'
            .'</div>';
    }

    /* -----------------------------------------------------------------------
     * A bound collection with no rows.
     * -------------------------------------------------------------------- */

    public function test_a_bound_collection_with_no_rows_renders_header_only_once(): void
    {
        $type    = Templates::type('invoice');
        $body    = $this->twoTableBody(aTop: 40, bTop: 90);
        $dataset = ['a' => $this->rows('a', 40), 'b' => []];

        $result = (new PageStructure)->build($body, $type, $dataset);
        $pages  = $result['pages'];

        // A paginates normally (22 + 18 across two pages); nothing crashes on empty B.
        $this->assertCount(2, $pages);
        $this->assertSame(40, count($pages[0]['dataset']['a']) + count($pages[1]['dataset']['a']));

        // The empty table renders once, header-only, where the flow reaches it — after A,
        // on the last page — and is absent before that.
        $this->assertStringNotContainsString('data-tpl-collection="b"', $pages[0]['template']);
        $this->assertStringContainsString('data-tpl-collection="b"', $pages[1]['template']);
        $this->assertSame([], $pages[1]['dataset']['b']);
    }

    /**
     * The discriminating case: an empty table reached on a page that is *not* the last.
     * Its header renders there, once, and must be stripped from the last page's full body
     * so an exhausted header never prints over the block below it (ruling T2-C).
     */
    public function test_an_empty_table_reached_before_the_last_page_is_absent_from_it(): void
    {
        $type = Templates::type('invoice');
        $body = $this->tablesBody([
            ['collection' => 'a', 'top' => 40],
            ['collection' => 'b', 'top' => 90],
            ['collection' => 'c', 'top' => 120],
        ]);
        $dataset = ['a' => $this->rows('a', 8), 'b' => [], 'c' => $this->rows('c', 40)];

        $pages = (new PageStructure)->build($body, $type, $dataset)['pages'];

        // Flow: [A8, B(empty), C12], [C22], [C6].
        $this->assertCount(3, $pages);

        // B renders header-only on page 1 (where the flow reaches it) and nowhere else —
        // crucially, it is gone from the last page's full body.
        $this->assertStringContainsString('data-tpl-collection="b"', $pages[0]['template']);
        $this->assertStringNotContainsString('data-tpl-collection="b"', $pages[1]['template']);
        $this->assertStringNotContainsString('data-tpl-collection="b"', $pages[2]['template']);

        // Its collection is [] on every page.
        foreach ($pages as $page) {
            $this->assertSame([], $page['dataset']['b']);
        }

        // A appears once; C spans all three pages; counts add up.
        $this->assertStringContainsString('data-tpl-collection="a"', $pages[0]['template']);
        $this->assertStringNotContainsString('data-tpl-collection="a"', $pages[2]['template']);
        $this->assertSame(40, array_sum(array_map(fn (array $p): int => count($p['dataset']['c']), $pages)));
    }

    /* -----------------------------------------------------------------------
     * Scenario fixtures (shared with the golden capture).
     * -------------------------------------------------------------------- */

    /**
     * The single-table scenarios the golden pins. Each is the exact input `build()` is
     * given — the page-level editor HTML, the type and the dataset.
     *
     * @return array<string, array{editorHtml: string, type: TemplateType, dataset: array<string, mixed>}>
     */
    protected function retrocompatScenarios(): array
    {
        $invoice = Templates::type('invoice');
        $html    = $invoice->initialLayout['html'];
        $full    = $this->fixtureDataset($invoice);

        $sliced = function (int $count) use ($full): array {
            $d          = $full;
            $d['linhas'] = array_slice($full['linhas'], 0, $count);

            return $d;
        };

        return [
            // The fiscal invoice, paginating over its 180 fixture rows.
            'invoice_fiscal' => ['editorHtml' => $html, 'type' => $invoice, 'dataset' => $full],

            // The same, with the table pushed 20mm down — exercises rowsPerPage($type, top).
            'invoice_top20' => [
                'editorHtml' => str_replace('style="top:0mm"', 'style="top:20mm"', $html),
                'type'       => $invoice,
                'dataset'    => $full,
            ],

            // A row count that divides exactly: two full pages, no trailing blank.
            'invoice_exact62' => ['editorHtml' => $html, 'type' => $invoice, 'dataset' => $sliced(62)],

            // The degenerate counts.
            'invoice_one_row' => ['editorHtml' => $html, 'type' => $invoice, 'dataset' => $sliced(1)],
            'invoice_empty'   => ['editorHtml' => $html, 'type' => $invoice, 'dataset' => $this->withLinhas($full, [])],

            // No line table at all: a single sheet, full body.
            'invoice_no_table' => [
                'editorHtml' => $this->bodyOf($this->pdfLayoutWithout($invoice, 'linhas')['html']),
                'type'       => $invoice,
                'dataset'    => $full,
            ],

            // A line table bound to nothing: today a single sheet, full body, unchanged.
            'invoice_unbound_table' => [
                'editorHtml' => $this->unboundTableBody(),
                'type'       => $invoice,
                'dataset'    => $full,
            ],

            // A roll: one page, every row, no repeated bands.
            'roll' => ['editorHtml' => $html, 'type' => $this->rollCarrier(), 'dataset' => $full],
        ];
    }

    /** The invoice geometry on a measured roll, as RollRenderTest builds it. */
    private function rollCarrier(): TemplateType
    {
        $base = Templates::type('invoice');

        return new TemplateType(
            $base->code, $base->label, $base->output, $base->seeded, $base->layoutRevision,
            $base->bindings, $base->nodes, $base->fixture, $base->fixtureRepeats,
            $base->initialLayout,
            [
                'medium' => 'roll', 'width_mm' => 80, 'fit' => 'measure',
                'margins' => ['top' => 3, 'right' => 3, 'bottom' => 3, 'left' => 3],
                'max_height_mm' => 4000,
                'line_row_height_mm' => 4.5,
            ],
            [],
        );
    }

    /**
     * @param  array<string, mixed>  $dataset
     * @param  array<int, mixed>  $linhas
     * @return array<string, mixed>
     */
    private function withLinhas(array $dataset, array $linhas): array
    {
        $dataset['linhas'] = $linhas;

        return $dataset;
    }

    /** The editor's export wraps the document in `<body>`; the compositor unwraps it. */
    private function bodyOf(string $html): string
    {
        return (string) preg_replace('/^<body[^>]*>|<\/body>$/', '', trim($html));
    }

    /* -----------------------------------------------------------------------
     * Multi-table fixtures (mirror the real editor: per-collection loop, inline top).
     * -------------------------------------------------------------------- */

    /**
     * A body with two line tables and a trailing totals block, each table iterating its
     * own collection and carrying an inline `top` — the shape the real editor emits
     * (table.js:215), not the simplified single-collection helper.
     */
    private function twoTableBody(int $aTop, int $bTop): string
    {
        return $this->tablesBody([['collection' => 'a', 'top' => $aTop], ['collection' => 'b', 'top' => $bTop]]);
    }

    /**
     * A body of line tables (each with its own collection loop and inline top) plus a
     * trailing totals block, wrapped in the three zones the way the editor exports it.
     *
     * @param  list<array{collection: string, top: int}>  $tables
     */
    private function tablesBody(array $tables): string
    {
        $body = '';
        foreach ($tables as $table) {
            $body .= $this->lineTable($table['collection'], $table['top']);
        }
        $body .= '<div class="block block--field" data-tpl-type="data-field" data-tpl-binding="totais.total" id="tpl-totals">{{ totais.total }}</div>';

        return '<div class="page">'
            .'<div class="zone zone--header" data-tpl-zone="header"><div id="tpl-atcud" data-tpl-type="data-field" data-tpl-binding="documento.atcud">{{ documento.atcud }}</div></div>'
            .'<div class="zone zone--body" data-tpl-zone="body">'.$body.'</div>'
            .'<div class="zone zone--footer" data-tpl-zone="footer"><div id="tpl-hash" data-tpl-type="data-field" data-tpl-binding="documento.hash_caracteres">{{ documento.hash_caracteres }}</div></div>'
            .'</div>';
    }

    private function unboundTableBody(): string
    {
        return '<div class="page">'
            .'<div class="zone zone--header" data-tpl-zone="header"></div>'
            .'<div class="zone zone--body" data-tpl-zone="body">'.$this->lineTable('', 0).'</div>'
            .'<div class="zone zone--footer" data-tpl-zone="footer"></div>'
            .'</div>';
    }

    private function lineTable(string $collection, int $top): string
    {
        $loop = $collection === '' ? 'linhas' : $collection;

        return '<div class="block block--lines" data-tpl-type="line-table" data-tpl-collection="'.$collection.'" id="tpl-'.($collection === '' ? 'x' : $collection).'" style="top:'.$top.'mm">'
            .'<table class="lines"><thead><tr><th>C</th></tr></thead><tbody>'
            .'{% for linha in '.$loop.' %}<tr><td>{{ linha.c }}</td></tr>{% endfor %}'
            .'</tbody></table></div>';
    }

    /**
     * @return array<int, array{c: string}>
     */
    private function rows(string $prefix, int $count): array
    {
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ['c' => $prefix.'-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)];
        }

        return $rows;
    }

    protected function goldenPath(): string
    {
        return __DIR__.'/../Fixtures/golden/retrocompat-pagination.ser';
    }
}
