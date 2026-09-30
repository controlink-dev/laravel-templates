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

    /**
     * The document, as one page template per sheet plus the dataset each needs.
     *
     * @param  array<string, mixed>  $dataset
     * @return array{pages: array<int, array{template: string, dataset: array<string, mixed>}>, css: string}
     */
    public function build(string $editorHtml, TemplateType $type, array $dataset): array
    {
        $zones = $this->zonesOf($editorHtml);
        $table = $this->tableIn($zones['body']);

        // A roll is one continuous page: every row, no repeated header or footer.
        if (PageGeometry::fromType($type->pdf)->medium === 'roll') {
            $rows = $table !== null && $table['collection'] !== ''
                ? ($dataset[$table['collection']] ?? [])
                : [];

            return [
                'pages' => [[
                    'template' => $this->pageTemplate($zones, $table, withTrailingBlocks: true),
                    'dataset'  => $this->datasetFor($dataset, $table, is_array($rows) ? $rows : [], 1, 1),
                ]],
                'css' => $this->css($type),
            ];
        }

        $chunks = $this->chunkRows($table, $type, $dataset);
        $total  = count($chunks);

        $pages = [];

        foreach ($chunks as $index => $chunk) {
            $isLast = $index === $total - 1;

            $pages[] = [
                'template' => $this->pageTemplate($zones, $table, withTrailingBlocks: $isLast),
                'dataset'  => $this->datasetFor($dataset, $table, $chunk, $index + 1, $total),
            ];
        }

        return ['pages' => $pages, 'css' => $this->css($type)];
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
        $margins = $type->pdf['margins'] ?? [];
        $zones   = $type->initialLayout['zones'] ?? [];

        $sheetHeight = PageGeometry::fromType($type->pdf)->sheetSizeMm()['height'];

        // A roll has no page to fill: rows never paginate.
        if (is_infinite($sheetHeight)) {
            return PHP_INT_MAX;
        }

        $bodyHeight = $sheetHeight
            - (float) ($margins['top'] ?? 15)
            - (float) ($margins['bottom'] ?? 15)
            - (float) ($zones['header_height'] ?? 35)
            - (float) ($zones['footer_height'] ?? 20);

        $rowHeight = max(1.0, (float) ($type->pdf['line_row_height_mm'] ?? 4.5));
        $available = $bodyHeight
            - $tableTopMm
            - (float) ($type->pdf['body_reserve_mm'] ?? 0)
            - $rowHeight;

        return max(1, (int) floor($available / $rowHeight));
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
     * @param  array{html: string, collection: string, top: float}|null  $table
     * @param  array<int, array<string, mixed>>  $chunk
     * @return array<string, mixed>
     */
    private function datasetFor(array $dataset, ?array $table, array $chunk, int $number, int $total): array
    {
        if ($table !== null && $table['collection'] !== '') {
            $dataset[$table['collection']] = $chunk;
        }

        $dataset['pagina.numero'] = $number;
        $dataset['pagina.total']  = $total;

        return $dataset;
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

        return '<div class="page">'
            .'<div class="zone zone--header">'.$zones['header'].'</div>'
            .'<div class="zone zone--body">'.$body.'</div>'
            .'<div class="zone zone--footer">'.$zones['footer'].'</div>'
            .'</div>';
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
    public function tableIn(string $bodyHtml): ?array
    {
        if (! str_contains($bodyHtml, 'data-tpl-type="line-table"')) {
            return null;
        }

        $document = $this->parse($bodyHtml);
        $xpath    = new DOMXPath($document);
        $found    = $xpath->query('//*[@data-tpl-type="line-table"]');
        $node     = $found ? $found->item(0) : null;

        if (! $node instanceof DOMElement) {
            return null;
        }

        return [
            'html'       => $document->saveHTML($node),
            'collection' => $node->getAttribute('data-tpl-collection'),
            'top'        => $this->topMm($node),
        ];
    }

    /**
     * How far down its zone a block sits, in millimetres, from its inline style.
     *
     * Only the inline style is read: the id rules live in the stylesheet the browser
     * resolves, and the only thing this number decides is how many rows fit, where
     * guessing low costs a page and guessing high clips a row.
     */
    private function topMm(DOMElement $node): float
    {
        if (preg_match('/top\s*:\s*([\d.]+)mm/i', $node->getAttribute('style'), $matches) === 1) {
            return (float) $matches[1];
        }

        return 0.0;
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
