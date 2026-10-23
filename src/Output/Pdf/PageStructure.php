<?php

namespace Controlink\Templates\Output\Pdf;

use Controlink\Templates\Support\PageGeometry;
use Controlink\Templates\Types\TemplateType;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Lays the document out into pages, on the server (spec 7.1, step 3).
 *
 * **The engine does not paginate this module.** It was tried and measured, and it fails
 * in two ways that a certified document cannot tolerate: when the last page carries only
 * a few lines Chromium stops emitting the repeated header group, so a three-line invoice
 * prints its last page with no ATCUD and no document number; and the obvious mitigation,
 * padding the end of the body, pushes out a blank page whenever the last page is nearly
 * full. Neither is visible in the extracted text, so a render-only validation would have
 * passed both. The numbers are in docs/NOTAS-FIDELIDADE.md.
 *
 * So the compositor decides the sheets. One `.page` per sheet, each carrying its own copy
 * of the header and the footer — which means the printed structure is exactly what the
 * canvas shows, repeated, and the zone rules in `print.css` apply unchanged on both
 * sides. That is the strongest fidelity position the module has had.
 *
 * The cost, accepted deliberately: knowing how many lines fit on a page requires a fixed
 * row height, so a long description is clipped at the column width rather than wrapping.
 * A line description is not a legal field, and reserving space for wrapped text would
 * either waste half of every page or put text measurement back in the compositor.
 */
class PageStructure
{
    private const ZONES = ['header', 'body', 'footer'];

    /** `data-tpl-anchor` of a line table drawn at its own place on the last page (CGE-67). */
    public const ANCHOR_LAST_PAGE = 'last-page';

    /**
     * The document, as one page template per sheet plus the dataset each needs.
     *
     * `$css` is the version stylesheet the editor exported. It is optional so every
     * existing caller and test keeps working; when present it carries the `top` of
     * each editor-placed line table, which lives in a `#id{…}` rule rather than inline
     * (the canvas runs with `avoidInlineStyle: true`). See `topMm`.
     *
     * @param  array<string, mixed>  $dataset
     * @return array{pages: array<int, array{template: string, dataset: array<string, mixed>}>, css: string}
     */
    public function build(string $editorHtml, TemplateType $type, array $dataset, string $css = ''): array
    {
        $zones  = $this->zonesOf($editorHtml);
        $tables = $this->tablesIn($zones['body'], $css);

        // A roll is one continuous page: every row, no repeated header or footer. Every
        // table renders all of its rows (spec 3.1); no chunking, so the body is unchanged.
        if (PageGeometry::fromType($type->pdf)->medium === 'roll') {
            return [
                'pages' => [[
                    'template' => $this->wrapPage($zones, $zones['body']),
                    'dataset'  => $this->datasetFor($dataset, $this->allRowsByCollection($tables, $dataset), 1, 1),
                ]],
                'css' => $this->css($type),
            ];
        }

        // A table fixed on the last page (CGE-67) that fits its slot leaves the flow: it is
        // drawn where the canvas put it, with all its rows, like the totals. One that does
        // not fit flows as any other table, so it is never clipped nor drawn over a block.
        [$flowing, $fixed] = $this->splitFixed($tables, $type, $dataset);

        // One table (or none) paginates exactly as it always has: a single DOM round-trip
        // is never taken, so the emitted strings are byte-for-byte what they were.
        if (count($flowing) <= 1) {
            return $this->buildSingle($zones, $flowing[0] ?? null, $type, $dataset);
        }

        return $this->buildStacked($zones, $flowing, $type, $dataset, $fixed);
    }

    /**
     * The original single-table pagination, preserved intact.
     *
     * @param  array{header: string, body: string, footer: string}  $zones
     * @param  array{html: string, collection: string, top: float}|null  $table
     * @param  array<string, mixed>  $dataset
     * @return array{pages: array<int, array{template: string, dataset: array<string, mixed>}>, css: string}
     */
    private function buildSingle(array $zones, ?array $table, TemplateType $type, array $dataset): array
    {
        $chunks = $this->chunkRows($table, $type, $dataset);
        $total  = count($chunks);

        $pages = [];

        foreach ($chunks as $index => $chunk) {
            $isLast = $index === $total - 1;

            $pages[] = [
                'template' => $this->pageTemplate($zones, $table, withTrailingBlocks: $isLast),
                'dataset'  => $this->datasetFor($dataset, $this->chunkMap($table, $chunk), $index + 1, $total),
            ];
        }

        return ['pages' => $pages, 'css' => $this->css($type)];
    }

    /**
     * N tables as one stacked flow across shared pages (spec 3.1).
     *
     * The body is a column that flows: the current table's header plus as many rows as
     * fit below the cursor; when rows remain the page closes (the bands repeat) and the
     * table continues at the top of a fresh body; when a table ends the cursor advances
     * and the next table starts on the same page if its header and one row still fit,
     * otherwise on a new page. On every page the flow starts at the first table's
     * designed `top` — never reset to 0 — which is what makes a lone table reduce exactly
     * to today's `array_chunk(rows, rowsPerPage($type, top))`. The trailing blocks (the
     * totals) ride the last page, after the last table.
     *
     * @param  array{header: string, body: string, footer: string}  $zones
     * @param  list<array{html: string, collection: string, top: float}>  $tables
     * @param  array<string, mixed>  $dataset
     * @return array{pages: array<int, array{template: string, dataset: array<string, mixed>}>, css: string}
     */
    private function buildStacked(array $zones, array $tables, TemplateType $type, array $dataset, array $fixed = []): array
    {
        $placements = $this->flow($tables, $type, $dataset);
        $total      = count($placements);

        // Every bound collection the body carries: a page that shows none of a table's
        // rows still declares it as [] (exhausted, or not yet started).
        $bound = array_values(array_filter(
            array_map(fn (array $t): string => $t['collection'], $tables),
            fn (string $collection): bool => $collection !== '',
        ));

        $pages = [];

        foreach ($placements as $index => $page) {
            $isLast = $index === $total - 1;

            // The collections placed on this page, each with the chunk it carries here.
            $placed = [];
            foreach ($page as $placement) {
                $placed[$placement['collection']] = $placement['chunk'];
            }

            $chunkMap = [];
            foreach ($bound as $collection) {
                $chunkMap[$collection] = $placed[$collection] ?? [];
            }

            $pages[] = [
                'template' => $this->wrapPage($zones, $this->stackedBody($zones['body'], $page, $isLast, $fixed)),
                'dataset'  => $this->datasetFor($dataset, $chunkMap, $index + 1, $total),
            ];
        }

        return ['pages' => $pages, 'css' => $this->css($type)];
    }

    /**
     * Walks the bound tables in `top` order, filling pages with a vertical cursor.
     *
     * Returns one entry per page, each a list of placements in flow order:
     * `{collection, chunk, top}` — `top` being where that chunk renders on that page,
     * which is the cursor, not necessarily the table's designed position.
     *
     * @param  list<array{html: string, collection: string, top: float}>  $tables
     * @param  array<string, mixed>  $dataset
     * @return list<list<array{collection: string, chunk: array<int, array<string, mixed>>, top: float}>>
     */
    private function flow(array $tables, TemplateType $type, array $dataset): array
    {
        // The flow is over bound tables only; an unbound one carries no collection to
        // paginate (spec 3.1, ruling T2-F) and stays in the DOM untouched.
        $bound = array_values(array_filter($tables, fn (array $t): bool => $t['collection'] !== ''));

        if ($bound === []) {
            return [[]];
        }

        $flowTop   = $bound[0]['top'];
        $rowHeight = max(1.0, (float) ($type->pdf['line_row_height_mm'] ?? 4.5));

        $pages   = [];
        $current = [];
        $cursor  = $flowTop;

        $close = function () use (&$pages, &$current, &$cursor, $flowTop): void {
            $pages[]  = $current;
            $current  = [];
            $cursor   = $flowTop;
        };

        foreach ($bound as $table) {
            $rows = $dataset[$table['collection']] ?? [];
            $rows = is_array($rows) ? array_values($rows) : [];
            $n    = count($rows);

            // A bound collection with no rows renders header-only, once, where the flow
            // reaches it (ruling T2-C). It takes the header row's worth of cursor.
            if ($n === 0) {
                if ($current !== [] && $this->rowsThatFit($type, $cursor) < 0) {
                    $close();
                }

                $current[] = ['collection' => $table['collection'], 'chunk' => [], 'top' => $cursor];
                $cursor += $rowHeight;

                continue;
            }

            $offset = 0;

            while ($offset < $n) {
                $atPageStart = $current === [];

                // The first table on a page uses the clamped value, so a lone table is
                // exactly today's chunking; a later table uses the raw fit, which may be
                // zero or negative — then the page closes and the table continues fresh.
                $capacity = $atPageStart
                    ? $this->rowsPerPage($type, $cursor)
                    : $this->rowsThatFit($type, $cursor);

                if ($capacity < 1) {
                    $close();

                    continue;
                }

                $take  = min($capacity, $n - $offset);
                $chunk = array_slice($rows, $offset, $take);

                $current[] = ['collection' => $table['collection'], 'chunk' => $chunk, 'top' => $cursor];

                $offset += $take;
                $cursor += (1 + $take) * $rowHeight;

                // Rows still left means they belong on the next page.
                if ($offset < $n) {
                    $close();
                }
            }
        }

        if ($current !== [] || $pages === []) {
            $pages[] = $current;
        }

        return $pages;
    }

    /**
     * How many rows fit on one page.
     *
     * Body height minus wherever the user put the table, minus the band the totals sit
     * in at the bottom, minus one row for the table's own header, divided by the row
     * height. Deterministic by construction, which is the whole reason the row height
     * is fixed.
     */
    public function rowsPerPage(TemplateType $type, float $tableTopMm = 0.0): int
    {
        $sheetHeight = PageGeometry::fromType($type->pdf)->sheetSizeMm()['height'];

        // A roll has no page to fill: rows never paginate.
        if (is_infinite($sheetHeight)) {
            return PHP_INT_MAX;
        }

        // At least one row always fits, so a page can never carry zero rows and loop
        // forever. The raw fit below is what a continuation uses to decide the next table.
        return max(1, $this->rowsThatFit($type, $tableTopMm));
    }

    /**
     * How many rows fit below a cursor, unclamped — the same arithmetic as
     * `rowsPerPage`, but it may return zero or a negative number when there is no room
     * left on the page. The stacked flow needs that to know when to close a page.
     *
     * Not called for a roll: there is no page to fill, and the height is infinite.
     */
    private function rowsThatFit(TemplateType $type, float $cursorMm): int
    {
        $rowHeight = max(1.0, (float) ($type->pdf['line_row_height_mm'] ?? 4.5));
        $available = $this->bodyHeight($type)
            - $cursorMm
            - (float) ($type->pdf['body_reserve_mm'] ?? 0)
            - $rowHeight;

        return (int) floor($available / $rowHeight);
    }

    /** The body band's height on a sheet: the page minus its margins and both bands. */
    private function bodyHeight(TemplateType $type): float
    {
        $margins = $type->pdf['margins'] ?? [];
        $zones   = $type->initialLayout['zones'] ?? [];

        $sheetHeight = PageGeometry::fromType($type->pdf)->sheetSizeMm()['height'];

        return $sheetHeight
            - (float) ($margins['top'] ?? 15)
            - (float) ($margins['bottom'] ?? 15)
            - (float) ($zones['header_height'] ?? 35)
            - (float) ($zones['footer_height'] ?? 20);
    }

    /**
     * Splits the body's tables into the ones that flow and the collections of the ones
     * fixed on the last page (CGE-67).
     *
     * A fixed table stays where the canvas drew it, with every row, on the last page only
     * — the way the totals do. That is safe only inside the band the flow never fills (the
     * `body_reserve_mm` at the bottom of every page) and only when the header and all its
     * rows fit above the footer. A fixed table that misses either condition — placed higher
     * than the reserve, or a document with more rows than its slot holds — falls back to
     * the flow, where it can never be clipped nor drawn over the lines: a mandatory table
     * (the tax summary) printed whole somewhere else is better than one printed cut.
     *
     * @param  list<array{html: string, collection: string, top: float, anchored?: bool}>  $tables
     * @param  array<string, mixed>  $dataset
     * @return array{0: list<array{html: string, collection: string, top: float, anchored?: bool}>, 1: list<string>}
     */
    private function splitFixed(array $tables, TemplateType $type, array $dataset): array
    {
        $flowing = [];
        $fixed   = [];

        $bodyHeight = $this->bodyHeight($type);
        $reserveTop = $bodyHeight - (float) ($type->pdf['body_reserve_mm'] ?? 0);
        $rowHeight  = max(1.0, (float) ($type->pdf['line_row_height_mm'] ?? 4.5));
        $tolerance  = 0.01;

        foreach ($tables as $table) {
            $rows = $dataset[$table['collection']] ?? [];
            $rows = is_array($rows) ? count($rows) : 0;

            $fits = ($table['anchored'] ?? false)
                && $table['collection'] !== ''
                && $table['top'] >= $reserveTop - $tolerance
                && $table['top'] + (1 + $rows) * $rowHeight <= $bodyHeight + $tolerance;

            if ($fits) {
                $fixed[] = $table['collection'];
            } else {
                $flowing[] = $table;
            }
        }

        return [$flowing, $fixed];
    }

    /**
     * @param  array<string, mixed>  $dataset
     * @param  array{html: string, collection: string, top: float}|null  $table
     * @return array<int, array<int, array<string, mixed>>> one chunk of rows per page
     */
    private function chunkRows(?array $table, TemplateType $type, array $dataset): array
    {
        // A form with no line table is a single sheet: nothing in it can grow.
        if ($table === null || $table['collection'] === '') {
            return [[]];
        }

        $rows = $dataset[$table['collection']] ?? [];

        if (! is_array($rows) || $rows === []) {
            return [[]];
        }

        return array_chunk($rows, $this->rowsPerPage($type, $table['top']));
    }

    /**
     * The dataset one page renders with: the whole document's values, its own slice of
     * the collection, and its page numbers.
     *
     * The page numbers arrive as an ordinary binding rather than as anything special in
     * the compositor. That is what lets the page number be a block the user positions
     * like any other field — and the reason it can exist at all, because the CSS page
     * counter does not work in this engine (measured; it prints zero).
     *
     * @param  array<string, mixed>  $dataset
     * @param  array<string, array<int, array<string, mixed>>>  $chunksByCollection  each bound table's slice for this page
     * @return array<string, mixed>
     */
    private function datasetFor(array $dataset, array $chunksByCollection, int $number, int $total): array
    {
        foreach ($chunksByCollection as $collection => $chunk) {
            if ($collection !== '') {
                $dataset[$collection] = $chunk;
            }
        }

        $dataset['pagina.numero'] = $number;
        $dataset['pagina.total']  = $total;

        return $dataset;
    }

    /**
     * The single-table chunk map: the one collection to its slice, or nothing when the
     * table is absent or unbound — so a lone table's dataset keys stay exactly as before.
     *
     * @param  array{html: string, collection: string, top: float}|null  $table
     * @param  array<int, array<string, mixed>>  $chunk
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function chunkMap(?array $table, array $chunk): array
    {
        if ($table === null || $table['collection'] === '') {
            return [];
        }

        return [$table['collection'] => $chunk];
    }

    /**
     * Every bound table's whole collection, for a roll: no chunking, each table renders
     * all of its rows (spec 3.1).
     *
     * @param  list<array{html: string, collection: string, top: float}>  $tables
     * @param  array<string, mixed>  $dataset
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function allRowsByCollection(array $tables, array $dataset): array
    {
        $map = [];

        foreach ($tables as $table) {
            if ($table['collection'] === '') {
                continue;
            }

            $rows = $dataset[$table['collection']] ?? [];
            $map[$table['collection']] = is_array($rows) ? $rows : [];
        }

        return $map;
    }

    /**
     * One sheet: the three zones, exactly as the canvas draws them.
     *
     * `$withTrailingBlocks` decides whether the body's non-table blocks are included.
     * They are not repeated on every page — a totals block belongs on the last sheet,
     * not on all of them — and the choice is made here, in PHP, because the sandbox
     * allows no `if` in a template (spec 7.2) and this is exactly the conditional logic
     * section 2 excludes from templates.
     *
     * @param  array{header: string, body: string, footer: string}  $zones
     * @param  array{html: string, collection: string, top: float}|null  $table
     */
    private function pageTemplate(array $zones, ?array $table, bool $withTrailingBlocks): string
    {
        $body = $zones['body'];

        if ($table !== null && ! $withTrailingBlocks) {
            // Only the table survives on a continuation page.
            $body = $table['html'];
        }

        return $this->wrapPage($zones, $body);
    }

    /**
     * One sheet around a body: the three zones, header and footer exactly as the canvas
     * draws them. The body is whatever the caller composed for that sheet.
     *
     * @param  array{header: string, body: string, footer: string}  $zones
     */
    private function wrapPage(array $zones, string $body): string
    {
        return '<div class="page">'
            .'<div class="zone zone--header">'.$zones['header'].'</div>'
            .'<div class="zone zone--body">'.$body.'</div>'
            .'<div class="zone zone--footer">'.$zones['footer'].'</div>'
            .'</div>';
    }

    /**
     * The body of one page in the stacked flow.
     *
     * A continuation page carries only the tables placed on it, in flow order, each
     * repositioned to where its chunk renders — nothing else survives, exactly as the
     * single-table continuation never carried the trailing blocks. The last page carries
     * the full body (totals and all), with the tables that have no rows on it removed
     * from the DOM so an exhausted header never prints over the next block (ruling T2-C),
     * and the surviving tables repositioned to their cursor.
     *
     * @param  list<array{collection: string, chunk: array<int, array<string, mixed>>, top: float}>  $page
     */
    private function stackedBody(string $bodyHtml, array $page, bool $isLast, array $fixed = []): string
    {
        // Where each placed collection renders on this page.
        $topByCollection = [];
        foreach ($page as $placement) {
            $topByCollection[$placement['collection']] = $placement['top'];
        }

        if (! $isLast) {
            // Only the placed tables, in flow order, each at its cursor position.
            $body = '';

            foreach ($page as $placement) {
                $table = $this->tableHtmlFor($bodyHtml, $placement['collection']);

                if ($table !== null) {
                    $body .= $this->repositionTop($table, $placement['top']);
                }
            }

            return $body;
        }

        // The last page: the whole body, minus the bound tables with no rows here, with
        // the survivors moved to their cursor. An unbound table and the trailing blocks
        // are left where the canvas drew them.
        $document = $this->parse($bodyHtml);
        $xpath    = new DOMXPath($document);
        $found    = $xpath->query('//*[@data-tpl-type="line-table"]');

        foreach ($found ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $collection = $node->getAttribute('data-tpl-collection');

            if ($collection === '' || in_array($collection, $fixed, true)) {
                continue; // unbound, or fixed on the last page: not part of the flow, left as drawn.
            }

            if (! array_key_exists($collection, $topByCollection)) {
                // A bound table with nothing on this page: remove it entirely.
                $node->parentNode?->removeChild($node);

                continue;
            }

            $this->setTopMm($node, $topByCollection[$collection]);
        }

        $root = $xpath->query('//*[@id="tpl-root"]')->item(0);

        return $root instanceof DOMElement ? $this->innerHtml($root) : $bodyHtml;
    }

    /**
     * The markup of the body's line table bound to `$collection`, as it was exported.
     *
     * Read back from the body rather than the `tablesIn` record so a continuation page
     * carries the original bytes, not a re-serialisation of the whole body.
     */
    private function tableHtmlFor(string $bodyHtml, string $collection): ?string
    {
        foreach ($this->tablesIn($bodyHtml) as $table) {
            if ($table['collection'] === $collection) {
                return $table['html'];
            }
        }

        return null;
    }

    /**
     * Rewrite a table's inline `top` to `$topMm`, on the root element only.
     *
     * The inline style wins over the `#id` rule in the stylesheet (ruling T2-D), so a
     * continuing table has to carry its cursor position inline. Only the opening tag is
     * touched, and only a `top` that is the element's own — never `margin-top` inside a
     * cell style further in the markup.
     */
    private function repositionTop(string $html, float $topMm): string
    {
        return (string) preg_replace_callback(
            '/^\s*<[a-z][^>]*>/i',
            fn (array $m): string => $this->withTopMm($m[0], $topMm),
            $html,
            1,
        );
    }

    /** Apply the `top` to an opening tag string, adding a `style` or a `top` as needed. */
    private function withTopMm(string $openingTag, float $topMm): string
    {
        $top = 'top:'.$this->mm($topMm);

        if (preg_match('/\sstyle\s*=\s*"[^"]*"/i', $openingTag) !== 1) {
            // No style attribute: add one before the closing bracket.
            return (string) preg_replace('/>$/', ' style="'.$top.'">', $openingTag, 1);
        }

        return (string) preg_replace_callback(
            '/(\sstyle\s*=\s*")([^"]*)(")/i',
            function (array $m) use ($top): string {
                $value = $m[2];

                $value = preg_match('/(?<![-\w])top\s*:\s*[^;"]*/i', $value) === 1
                    ? (string) preg_replace('/(?<![-\w])top\s*:\s*[^;"]*/i', $top, $value, 1)
                    : $top.';'.$value;

                return $m[1].$value.$m[3];
            },
            $openingTag,
            1,
        );
    }

    /** Set a DOM node's inline `top`, preserving the rest of its style. */
    private function setTopMm(DOMElement $node, float $topMm): void
    {
        $top   = 'top:'.$this->mm($topMm);
        $style = $node->getAttribute('style');

        if ($style === '') {
            $node->setAttribute('style', $top);

            return;
        }

        $style = preg_match('/(?<![-\w])top\s*:\s*[^;]*/i', $style) === 1
            ? (string) preg_replace('/(?<![-\w])top\s*:\s*[^;]*/i', $top, $style, 1)
            : $top.';'.$style;

        $node->setAttribute('style', $style);
    }

    /**
     * The innerHTML of each zone, read with a DOM parser rather than a regular
     * expression: the zones contain nested markup, and matching balanced tags by
     * pattern is how a compositor quietly loses half a footer.
     *
     * @return array{header: string, body: string, footer: string}
     */
    public function zonesOf(string $editorHtml): array
    {
        $document = $this->parse($editorHtml);
        $xpath    = new DOMXPath($document);
        $zones    = [];

        foreach (self::ZONES as $zone) {
            $found = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' zone--{$zone} ')]");
            $node  = $found ? $found->item(0) : null;

            $zones[$zone] = $node instanceof DOMElement ? $this->innerHtml($node) : '';
        }

        if ($zones['header'] === '' && $zones['body'] === '' && $zones['footer'] === '') {
            throw new RuntimeException('The layout has no zones; it cannot be composed into pages.');
        }

        return $zones;
    }

    /**
     * The line table in the body, if there is one: its markup, the collection it is
     * bound to, and how far down the body the user placed it.
     *
     * @return array{html: string, collection: string, top: float}|null
     */
    public function tableIn(string $bodyHtml, string $css = ''): ?array
    {
        return $this->tablesIn($bodyHtml, $css)[0] ?? null;
    }

    /**
     * Every line table in the body, ordered by how far down the body the user placed
     * it (ties keep document order).
     *
     * `$css` is the version stylesheet. Each table's `top` is resolved against it (a
     * `#id{top}` or `.class{top}` rule) with any inline `top` winning — because the
     * editor writes a dragged block's position into a CSS rule, not inline. See
     * `topMm`. It is optional so a caller with inline-top HTML can pass none.
     *
     * @return list<array{html: string, collection: string, top: float}>
     */
    public function tablesIn(string $bodyHtml, string $css = ''): array
    {
        if (! str_contains($bodyHtml, 'data-tpl-type="line-table"')) {
            return [];
        }

        [$rulesById, $rulesByClass] = $this->cssRules($css);

        $document = $this->parse($bodyHtml);
        $xpath    = new DOMXPath($document);
        $found    = $xpath->query('//*[@data-tpl-type="line-table"]');
        $tables   = [];

        foreach ($found ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $tables[] = [
                'html'       => $document->saveHTML($node),
                'collection' => $node->getAttribute('data-tpl-collection'),
                'top'        => $this->topMm($node, $rulesById, $rulesByClass),
                'anchored'   => $node->getAttribute('data-tpl-anchor') === self::ANCHOR_LAST_PAGE,
            ];
        }

        // usort is stable since PHP 8.0, so equal tops keep document order.
        usort($tables, fn (array $a, array $b): int => $a['top'] <=> $b['top']);

        return $tables;
    }

    /**
     * How far down its zone a block sits, in millimetres.
     *
     * The compositor sees HTML plus the version CSS — not the `project_data` JSON
     * `LayoutDocument` walks — so the `top` is resolved here from the raw stylesheet.
     * The editor canvas runs GrapesJS with `avoidInlineStyle: true` and positions a
     * dragged block via `component.addStyle({top})`, which lands in a `#id{top}` rule,
     * so reading the inline style alone would see 0 for every editor-placed table and
     * collapse the flow ordering and the page-capacity arithmetic (spec 3.1).
     *
     * Precedence mirrors `LayoutDocument::styleOf` (and the browser cascade): class
     * rules first, then the `#id` rule, then inline last — so an inline `top` wins over
     * a `#id{top}` wins over a `.class{top}`. The hand-authored fiscal line table keeps
     * its inline `top:0mm`, so inline still wins and its resolved top is unchanged.
     *
     * `LayoutDocument::millimetres` does the unit parsing; it returns null for `0`,
     * `auto` and `%`, and a block with no stated `top` is flush to the top of its zone,
     * so the `?? 0.0` default stands in for both.
     *
     * @param  array<string, array<string, string>>  $rulesById
     * @param  array<string, array<string, string>>  $rulesByClass
     */
    private function topMm(DOMElement $node, array $rulesById, array $rulesByClass): float
    {
        $declarations = [];

        foreach ($this->classesOf($node) as $class) {
            $declarations = array_merge($declarations, $rulesByClass[$class] ?? []);
        }

        $id = $node->getAttribute('id');

        if ($id !== '') {
            $declarations = array_merge($declarations, $rulesById[$id] ?? []);
        }

        $declarations = array_merge($declarations, $this->declarationsOf($node->getAttribute('style')));

        return LayoutDocument::millimetres($declarations['top'] ?? null) ?? 0.0;
    }

    /**
     * The version stylesheet indexed as `#id` → declarations and `.class` → declarations.
     *
     * A flat scan of `selector { declarations }` blocks is complete here: the editor
     * runs its one device with `widthMedia: ''` (editor.js:212) precisely so `getCss()`
     * emits no `@media` wrapper, so there are no nested rules to miss. Only a lone
     * `#id` or `.class` selector is indexed — a type, compound, descendant or pseudo
     * selector (`table.lines th`, `*`) never carries a block's `top` — and `@page`/
     * `@font-face` at-rules, having no such selector, are skipped by the same test.
     *
     * @return array{0: array<string, array<string, string>>, 1: array<string, array<string, string>>}
     */
    private function cssRules(string $css): array
    {
        $byId    = [];
        $byClass = [];

        if ($css === '') {
            return [$byId, $byClass];
        }

        // Drop comments so a commented-out rule is never read.
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        if (preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $blocks, PREG_SET_ORDER) === false) {
            return [$byId, $byClass];
        }

        foreach ($blocks as $block) {
            $declarations = $this->declarationsOf($block[2]);

            if ($declarations === []) {
                continue;
            }

            foreach (explode(',', $block[1]) as $selector) {
                $selector = trim($selector);

                if (preg_match('/^#([\w-]+)$/', $selector, $m) === 1) {
                    $byId[$m[1]] = array_merge($byId[$m[1]] ?? [], $declarations);
                } elseif (preg_match('/^\.([\w-]+)$/', $selector, $m) === 1) {
                    $byClass[$m[1]] = array_merge($byClass[$m[1]] ?? [], $declarations);
                }
            }
        }

        return [$byId, $byClass];
    }

    /**
     * A block of CSS declarations as a property → value map.
     *
     * Splitting on `;` then the first `:` matches each property name exactly, which is
     * what keeps `margin-top` from ever being read as `top` — the structural form of
     * the word-boundary guard `withTopMm`/`setTopMm` apply to their regex.
     *
     * @return array<string, string>
     */
    private function declarationsOf(string $css): array
    {
        $declarations = [];

        foreach (explode(';', $css) as $piece) {
            $colon = strpos($piece, ':');

            if ($colon === false) {
                continue;
            }

            $name = mb_strtolower(trim(substr($piece, 0, $colon)));

            if ($name !== '') {
                $declarations[$name] = trim(substr($piece, $colon + 1));
            }
        }

        return $declarations;
    }

    /**
     * A DOM element's class list.
     *
     * @return array<int, string>
     */
    private function classesOf(DOMElement $node): array
    {
        return preg_split('/\s+/', trim($node->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private function parse(string $html): DOMDocument
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        // The export is a fragment, and the parser would otherwise complain about the
        // missing document structure and about the `{{ }}` it does not understand.
        $document->loadHTML(
            '<?xml encoding="utf-8" ?><div id="tpl-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    private function innerHtml(DOMElement $element): string
    {
        $html = '';

        foreach ($element->childNodes as $child) {
            $html .= $element->ownerDocument->saveHTML($child);
        }

        return $html;
    }

    /**
     * What the paginated document needs beyond the shared print stylesheet.
     *
     * Short, and that is the point: because every sheet is the canvas's own structure,
     * the zone and block rules in `print.css` apply unchanged. All that is left is the
     * page break and the row height that made the pagination computable.
     */
    public function css(TemplateType $type): string
    {
        $rowHeight = (float) ($type->pdf['line_row_height_mm'] ?? 4.5);

        return implode('', [
            // One sheet per page, and no trailing blank page after the last.
            '.page{break-after:page}',
            '.page:last-of-type{break-after:auto}',

            // The fixed row height is what makes rows-per-page arithmetic rather than
            // measurement. A description longer than its column is clipped: it is not a
            // legal field, and wrapping would make the page count unknowable.
            'table.lines{width:100%;border-collapse:collapse;table-layout:fixed}',
            "table.lines th,table.lines td{height:{$this->mm($rowHeight)};max-height:{$this->mm($rowHeight)};"
                .'overflow:hidden;white-space:nowrap;text-overflow:ellipsis;'
                .'box-sizing:border-box;padding:0 1mm;vertical-align:middle}',
        ]);
    }

    private function mm(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.').'mm';
    }
}
