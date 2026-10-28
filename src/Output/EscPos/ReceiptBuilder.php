<?php

namespace Controlink\Templates\Output\EscPos;

use Controlink\Templates\Codes\CodePayload;
use Controlink\Templates\Output\Pdf\LineRows;
use Controlink\Templates\Support\PageGeometry;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
use InvalidArgumentException;
use Throwable;

/**
 * A composed roll — the HTML the PDF engine gets — laid on the printer's character grid
 * (CGE-97). The result, a `Receipt`, is what the bytes and the preview are made from.
 *
 * It reads the receipt vocabulary (print.css, `RollVocabulary`) and breaks text the way
 * the browser breaks it in the PDF, so the receipt is the PDF line for line:
 *
 * - a block in flow is one or more lines; its text breaks on words (spaces, and after a
 *   hyphen where Chromium breaks: `4200-|072`, `Metalo-|Mecânicas`), and a word wider than the line is E-04 —
 *   the browser would let it overflow the paper;
 * - a value never breaks: an element with `esc-nowrap`, a formatted data field, a value
 *   cell of the line table (any column with a format or a formula, the
 *   `RowHeights::isText` rule), an absolutely positioned `.block` (print.css: nowrap). If
 *   it does not fit, it is E-04, never a cut;
 * - a legal text with no format (a name, an address) breaks on its spaces only, as the PDF
 *   wraps it (spaces and hyphens); a legal token (no space in it: a NIF, an ATCUD) never
 *   breaks, and wider than the line is E-04;
 * - every line carries the number of the text block (or row) it comes from, `group`, and
 *   how it joins the line before, `joint` (' ' after a space or a hard break, '' after a
 *   hyphen or a cut word), so E-01 reads a wrapped value back whole (`EscPosValidator`);
 * - a line table's text cells break on words and, failing that, anywhere (PageStructure
 *   `rollWrapCss`: pre-line + overflow-wrap:anywhere); its header cells are bold and are
 *   clipped at their width, as the browser clips them;
 * - `esc-row` cells take `data-tpl-cols` characters; the cells with 0 or none share the
 *   rest equally (floor, the remainder going to the last of them);
 * - a line table's grid columns take their `Nch`; the ones with no width share the rest
 *   the same way, and with none to share it, the stated widths grow in proportion (the
 *   browser's `table-layout: fixed` at 100%).
 *
 * Every text goes through `Cp858::normalize` before it is measured, so a transliterated
 * character keeps its place; one with no equivalent is E-03 naming the field (and a `?`
 * in the receipt, which must not be printed while it has problems).
 *
 * It expects V-21-clean input (`RollVocabulary` runs first) but never fails on other
 * input: what it cannot translate becomes an E-04.
 */
final class ReceiptBuilder
{
    /** Elements that start a block of their own (everything else is inline text). */
    private const BLOCK_TAGS = [
        'div', 'p', 'section', 'article', 'header', 'footer', 'main', 'aside', 'nav', 'table',
        'hr', 'img', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'figure', 'pre',
        'blockquote', 'address', 'form',
    ];

    private const BLOCK_CLASSES = ['flow', 'block', 'zone', 'esc-row', 'esc-cell'];

    private const BLOCK_TYPES = ['code-slot', 'image', 'rule', 'line-table', 'text-row'];

    /**
     * A `<br>` while the text is collected: a hard break in every white-space mode. U+2028
     * cannot come from the data as itself (it is not in CP858, so it is E-03 and `?`).
     */
    private const BR = "\u{2028}";

    private int $columns;

    /** @var array<string, mixed> */
    private array $dataset;

    /** @var array<string, true> the legal binding keys */
    private array $legal;

    /** @var list<array<string, mixed>> */
    private array $blocks;

    /** @var list<array{code: string, component_id: ?string, message: string, key: string, subject: string}> */
    private array $problems;

    /** @var array<string, true> problems already reported, by code, component and subject */
    private array $reported;

    /** @var array<int, array{owner: ?string, subject: string}> the unbreakable runs */
    private array $locks;

    /** The last text block's number: its lines carry it as `group` (see `Receipt`). */
    private int $group;

    /**
     * @param  array<string, mixed>  $dataset  the flat dataset the document was composed with
     * @param  list<array<string, mixed>>  $fields  the type's bindings (`key`, `legal`), the
     *                                               authoritative list of legal fields, which never
     *                                               break. Optional: the editor's `data-tpl-legal="1"`
     *                                               mark already says so on what it saved.
     */
    public function build(string $composedHtml, array $dataset, PageGeometry $geometry, array $fields = []): Receipt
    {
        if ($geometry->columns === null || $geometry->columns < 2) {
            throw new InvalidArgumentException('Only a roll has a character grid to lay a receipt on.');
        }

        $this->columns  = $geometry->columns;
        $this->dataset  = $dataset;
        $this->legal    = [];
        $this->blocks   = [];
        $this->problems = [];
        $this->reported = [];
        $this->locks    = [];
        $this->group    = 0;

        foreach ($fields as $field) {
            if (is_array($field) && ($field['legal'] ?? false) && isset($field['key'])) {
                $this->legal[(string) $field['key']] = true;
            }
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$composedHtml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        $pages = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " page ")]');
        $roots = [];

        foreach ($pages ?: [] as $page) {
            if ($page instanceof DOMElement) {
                $roots[] = $page;
            }
        }

        if ($roots === [] && ($body = $document->getElementsByTagName('body')->item(0)) instanceof DOMElement) {
            $roots[] = $body;
        }

        $context = ['align' => 'left', 'bold' => false, 'double' => false, 'nowrap' => false];

        foreach ($roots as $page) {
            $zones = [];

            foreach (['header', 'body', 'footer'] as $name) {
                $zone = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " zone--'.$name.' ")]', $page);

                if ($zone !== false && $zone->item(0) instanceof DOMElement) {
                    $zones[] = $zone->item(0);
                }
            }

            foreach ($zones === [] ? [$page] : $zones as $zone) {
                $this->container($zone, $context);
            }
        }

        return new Receipt($this->columns, $this->blocks, $this->problems);
    }

    /* ---------------------------------------------------------------------
     * Blocks
     * ------------------------------------------------------------------ */

    /** @param array{align: string, bold: bool, double: bool, nowrap: bool} $context */
    private function container(DOMElement $element, array $context): void
    {
        $inline = [];

        foreach (iterator_to_array($element->childNodes) as $child) {
            if ($this->isBlock($child)) {
                $this->anonymous($inline, $element, $context);
                $inline = [];
                $this->block($child, $context);

                continue;
            }

            if ($child instanceof DOMText || $child instanceof DOMElement) {
                $inline[] = $child;
            }
        }

        $this->anonymous($inline, $element, $context);
    }

    /**
     * @param  list<DOMNode>  $nodes  inline content between blocks
     * @param  array{align: string, bold: bool, double: bool, nowrap: bool}  $context
     */
    private function anonymous(array $nodes, DOMElement $parent, array $context): void
    {
        $glyphs = [];

        foreach ($nodes as $node) {
            array_push($glyphs, ...$this->collect($node, $context, $context['nowrap'] ? $this->lock($parent) : null, null));
        }

        $this->textLines($glyphs, $context, false, [$this->nearestId($parent)], $this->nearestId($parent));
    }

    /** @param array{align: string, bold: bool, double: bool, nowrap: bool} $context */
    private function block(DOMElement $element, array $context): void
    {
        $tag     = strtolower($element->tagName);
        $type    = $element->getAttribute('data-tpl-type');
        $context = $this->contextOf($element, $context);

        if (in_array($tag, ['script', 'style', 'template'], true)) {
            return;
        }

        // The gap's `em` is the element's own: on a double block, each blank line is double.
        if (preg_match('/(?:^|\s)esc-gap-([1-9])(?:\s|$)/', $element->getAttribute('class'), $gap)) {
            $width = $context['double'] ? intdiv($this->columns, 2) : $this->columns;

            for ($i = 0; $i < (int) $gap[1]; $i++) {
                $this->blocks[] = $this->lineBlock($this->pad([], $width, 'left'), $context['double'], [], ++$this->group);
            }
        }

        if ($type === 'code-slot') {
            $this->qr($element);

            return;
        }

        if ($tag === 'img' || $type === 'image') {
            $this->image($element);

            return;
        }

        if ($tag === 'hr' || $type === 'rule') {
            $this->blocks[] = $this->lineBlock($this->glyphs(str_repeat('-', $this->columns)), false, array_filter([$this->nearestId($element)]), ++$this->group);

            return;
        }

        if ($type === 'line-table' || $tag === 'table') {
            $this->table($element, $context);

            return;
        }

        if ($this->hasClass($element, 'esc-row')) {
            $this->row($element, $context);

            return;
        }

        foreach ($element->childNodes as $child) {
            if ($this->isBlock($child)) {
                $this->container($element, $context);

                return;
            }
        }

        // A text block: an absolutely positioned `.block` is nowrap in print.css, unless
        // it is set to wrap (pre-wrap, overflow-wrap:anywhere).
        $wraps  = $element->getAttribute('data-tpl-wrap') === '1';
        $nowrap = $context['nowrap'] || ($this->hasClass($element, 'block') && ! $wraps);
        $lock   = $this->lockFor($element, $nowrap);
        $glyphs = $this->collect($element, $context, $lock, $this->fieldOf($element));

        $this->textLines($glyphs, $context, $wraps, [$this->nearestId($element)], $this->nearestId($element));
    }

    /**
     * Lines of one text block, aligned on the grid.
     *
     * @param  list<array{c: string, b: bool, l: ?int}>  $glyphs
     * @param  array{align: string, bold: bool, double: bool, nowrap: bool}  $context
     * @param  list<?string>  $sources
     */
    private function textLines(array $glyphs, array $context, bool $preLine, array $sources, ?string $owner): void
    {
        $width = $context['double'] ? intdiv($this->columns, 2) : $this->columns;
        $group = ++$this->group;

        foreach ($this->wrap($this->collapse($glyphs, $preLine), $width, $preLine, $owner) as $line) {
            $this->blocks[] = $this->lineBlock($this->pad($line, $width, $context['align']), $context['double'], $sources, $group, $line[0]['j'] ?? ' ');
        }
    }

    /** One `esc-row`: its cells side by side, each on its own columns. */
    private function row(DOMElement $row, array $context): void
    {
        $cells = [];

        foreach ($row->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $cells[] = $child;
            }
        }

        $double = $context['double'];
        $width  = $double ? intdiv($this->columns, 2) : $this->columns;
        $stated = [];

        foreach ($cells as $index => $cell) {
            $cols           = $cell->getAttribute('data-tpl-cols');
            $stated[$index] = preg_match('/^\d+$/D', $cols) && (int) $cols > 0 ? (int) $cols : null;
        }

        $widths = $this->share($stated, $width, false);

        if (array_sum($widths) > $width) {
            $this->problem('E-04', 'row_too_wide', $this->nearestId($row), $this->nearestId($row) ?? 'esc-row', ['width' => array_sum($widths)]);
        }

        $columns = [];
        $sources = [];

        foreach ($cells as $index => $cell) {
            $cellContext = ['double' => $double] + $this->contextOf($cell, $context);
            $lock        = $this->lockFor($cell, $cellContext['nowrap']);
            $glyphs      = $this->collapse($this->collect($cell, $cellContext, $lock, $this->fieldOf($cell)), false);

            if ($glyphs !== []) {
                $sources[] = $this->nearestId($cell);
            }

            $columns[] = [
                'lines' => $this->cellLines($glyphs, $widths[$index], false, $this->nearestId($cell), $this->nearestId($cell) ?? 'esc-cell'),
                'width' => $widths[$index],
                'align' => $cellContext['align'],
            ];
        }

        $this->emitColumns($columns, $width, $double, $sources);
    }

    /** The line table: one line (or more, when a text cell wraps) per `<tr>`. */
    private function table(DOMElement $element, array $context): void
    {
        $holder  = $element->hasAttribute('data-tpl-columns') ? $element : $this->descendant($element, '*[@data-tpl-columns]');
        $table   = strtolower($element->tagName) === 'table' ? $element : $this->descendant($element, 'table');
        $columns = $holder === null ? null : json_decode($holder->getAttribute('data-tpl-columns'), true);
        $owner   = $this->nearestId($element);

        if (! is_array($columns) || $table === null) {
            $this->problem('E-04', 'untranslatable', $owner, $owner ?? 'table');

            return;
        }

        $segments = LineRows::segments($columns);
        $grid     = LineRows::grid($columns);
        $stated   = array_map(
            fn (array $column): ?int => preg_match('/^(\d+)ch$/D', strtolower(trim((string) ($column['width'] ?? ''))), $m) ? (int) $m[1] : null,
            $grid,
        );
        $widths = $this->share($stated, $this->columns, true);

        if (array_sum($widths) > $this->columns) {
            $this->problem('E-04', 'row_too_wide', $owner, $owner ?? 'table', ['width' => array_sum($widths)]);
        }

        $counters = [];

        foreach ((new DOMXPath($table->ownerDocument))->query('.//tr', $table) ?: [] as $tr) {
            if (! $tr instanceof DOMElement) {
                continue;
            }

            $section            = strtolower($tr->parentNode instanceof DOMElement ? $tr->parentNode->tagName : '');
            $counters[$section] = ($counters[$section] ?? -1) + 1;
            $segment            = $segments[$counters[$section] % max(1, count($segments))] ?? [];

            $this->tableRow($tr, $segment, $widths, $context, $owner);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $segment  the columns this row prints
     * @param  list<int>  $widths  the grid's widths
     */
    private function tableRow(DOMElement $tr, array $segment, array $widths, array $context, ?string $owner): void
    {
        $columns = [];
        $at      = 0;
        $index   = 0;

        foreach ($tr->childNodes as $cell) {
            if (! $cell instanceof DOMElement || ! in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                continue;
            }

            $span   = max(1, (int) $cell->getAttribute('colspan'));
            $width  = array_sum(array_slice($widths, $at, $span));
            $at    += $span;
            $column = $segment[$index++] ?? null;
            $header = strtolower($cell->tagName) === 'th';

            $cellContext          = $context;
            $cellContext['bold']  = $context['bold'] || $header;
            $cellContext['align'] = $this->inlineAlign($cell) ?? (is_array($column) ? $this->alignment((string) ($column['align'] ?? '')) : null) ?? 'left';

            $text  = $header || ! is_array($column) || self::isTextColumn($column);
            $lock  = $text ? null : $this->lock($cell, (string) ($column['label'] ?? $column['key'] ?? $owner ?? ''), $owner);
            $field = is_array($column) ? (string) ($column['key'] ?? '') : null;

            $glyphs = $this->collapse($this->collect($cell, $cellContext, $lock, $field ?: null), ! $header);

            // A header is nowrap and clipped at its width, as the browser draws it.
            $lines = $header
                ? ($glyphs === [] ? [] : [array_slice(array_map(fn (array $g): array => $g['c'] === "\n" ? ['c' => ' '] + $g : $g, $glyphs), 0, $width)])
                : $this->cellLines($glyphs, $width, true, $owner, (string) (is_array($column) ? ($column['label'] ?? $column['key'] ?? '') : '') ?: ($owner ?? 'td'));

            $columns[] = ['lines' => $lines, 'width' => $width, 'align' => $cellContext['align']];
        }

        // A table row has its height even when empty (PageStructure: a fixed row height).
        $this->emitColumns($columns, $this->columns, false, [$owner], 1);
    }

    /**
     * A cell's lines in its width. A cell with text and no columns left (the sized cells
     * took the whole line) is E-04: its text would vanish, never print.
     *
     * @param  list<array{c: string, b: bool, l: ?int}>  $glyphs  collapsed
     * @return list<list<array{c: string, b: bool, l: ?int}>>
     */
    private function cellLines(array $glyphs, int $width, bool $anywhere, ?string $owner, string $subject): array
    {
        if ($width <= 0) {
            if ($glyphs !== []) {
                $this->problem('E-04', 'no_room', $owner, $subject);
            }

            return [];
        }

        return $this->wrap($glyphs, $width, $anywhere, $owner);
    }

    /**
     * Side-by-side columns as lines: as many as the tallest, each column padded to its
     * width and the line to the grid.
     *
     * @param  list<array{lines: list<list<array{c: string, b: bool, l: ?int}>>, width: int, align: string}>  $columns
     * @param  list<?string>  $sources
     */
    private function emitColumns(array $columns, int $width, bool $double, array $sources, int $minimum = 0): void
    {
        $height = $columns === [] ? 0 : $minimum;
        $group  = ++$this->group;

        foreach ($columns as $column) {
            $height = max($height, count($column['lines']));
        }

        for ($i = 0; $i < $height; $i++) {
            $line   = [];
            $joints = [];

            foreach ($columns as $column) {
                array_push($line, ...$this->pad($column['lines'][$i] ?? [], $column['width'], $column['align']));

                if (($column['lines'][$i] ?? []) !== []) {
                    $joints[] = $column['lines'][$i][0]['j'] ?? ' ';
                }
            }

            // Nothing between the lines only when every column that goes on broke a word.
            $joint = $joints !== [] && array_unique($joints) === [''] ? '' : ' ';

            $this->blocks[] = $this->lineBlock($this->pad(array_slice($line, 0, $width), $width, 'left'), $double, $sources, $group, $joint);
        }
    }

    /**
     * Integer widths that fill `$total`: the stated ones as stated, the others sharing
     * what is left (floor, the remainder to the last of them). With none to share it,
     * `$grow` spreads the rest over the stated ones in proportion (table-layout: fixed).
     *
     * @param  array<int, ?int>  $stated
     * @return list<int>
     */
    private function share(array $stated, int $total, bool $grow): array
    {
        $stated = array_values($stated);
        $fixed  = array_sum(array_filter($stated, fn (?int $w): bool => $w !== null));
        $auto   = array_keys(array_filter($stated, fn (?int $w): bool => $w === null));
        $rest   = max(0, $total - $fixed);
        $widths = array_map(fn (?int $w): int => $w ?? 0, $stated);

        if ($auto !== []) {
            foreach ($auto as $index) {
                $widths[$index] = intdiv($rest, count($auto));
            }

            $widths[end($auto)] += $rest % count($auto);
        } elseif ($grow && $fixed > 0 && $rest > 0) {
            $given = 0;

            foreach ($widths as $index => $w) {
                $extra           = intdiv($rest * $w, $fixed);
                $widths[$index] += $extra;
                $given          += $extra;
            }

            $widths[count($widths) - 1] += $rest - $given;
        }

        return $widths;
    }

    private function qr(DOMElement $slot): void
    {
        $attributes = [];

        foreach ($slot->attributes ?? [] as $attribute) {
            $attributes[$attribute->nodeName] = (string) $attribute->nodeValue;
        }

        $id = $this->nearestId($slot);

        try {
            $fixed = CodePayload::fromAttributes($attributes);
        } catch (Throwable) {
            $this->problem('E-04', 'untranslatable', $id, $id ?? 'QR');

            return;
        }

        $binding = $fixed === null ? $slot->getAttribute('data-tpl-binding') : null;
        $payload = $fixed ?? (string) (is_scalar($this->dataset[$binding] ?? null) ? $this->dataset[$binding] : '');

        if ($payload === '') {
            $this->problem('E-04', 'qr_empty', $id, $binding ?: ($id ?? 'QR'));
        }

        $this->blocks[] = ['kind' => 'qr', 'payload' => $payload, 'binding' => $binding ?: null];
    }

    private function image(DOMElement $element): void
    {
        $img = strtolower($element->tagName) === 'img' ? $element : $this->descendant($element, 'img');
        $id  = $this->nearestId($element);
        $src = $img?->getAttribute('src') ?? '';
        $png = null;

        if (preg_match('#^data:image/(png|jpe?g);base64,(.+)$#is', trim($src), $match)) {
            $data  = base64_decode(preg_replace('/\s+/', '', $match[2]) ?? '', true);
            $image = $data === false ? false : @imagecreatefromstring($data);

            if ($image !== false) {
                if (strtolower($match[1]) === 'png') {
                    $png = $data;
                } else {
                    ob_start();
                    imagepng($image);
                    $png = (string) ob_get_clean();
                }
            }
        }

        if ($png === null || $png === false) {
            $this->problem('E-04', 'image_type', $id, $id ?? 'img');

            return;
        }

        $this->blocks[] = ['kind' => 'image', 'png' => $png];
    }

    /* ---------------------------------------------------------------------
     * Text
     * ------------------------------------------------------------------ */

    /**
     * The characters of a node, each with its bold and its lock (the unbreakable run it
     * belongs to), already in the printer's repertoire.
     *
     * @param  array{align: string, bold: bool, double: bool, nowrap: bool}  $context
     * @return list<array{c: string, b: bool, l: ?int}>
     */
    private function collect(DOMNode $node, array $context, ?int $lock, ?string $field): array
    {
        if ($node instanceof DOMText) {
            $glyphs = [];

            foreach (mb_str_split(Cp858::compose($node->nodeValue ?? ''), 1, 'UTF-8') as $char) {
                $glyphs[] = ['c' => $this->printable($char, $field, $node), 'b' => $context['bold'], 'l' => $lock];
            }

            return $glyphs;
        }

        if (! $node instanceof DOMElement) {
            return [];
        }

        $tag = strtolower($node->tagName);

        if (in_array($tag, ['script', 'style', 'template'], true)) {
            return [];
        }

        if ($tag === 'br') {
            return [['c' => self::BR, 'b' => false, 'l' => null]];
        }

        $inner = $this->contextOf($node, $context);

        if ($inner['double'] !== $context['double']) {
            $this->problem('E-04', 'mixed_size', $this->nearestId($node), $this->nearestId($node) ?? $tag);
        }

        // A value inside a legal text that wraps on words stays whole all the same.
        if ($lock === null || $this->locks[$lock]['words']) {
            $kind = $this->lockKind($node, $inner['nowrap']);

            if ($kind === 'whole' || ($kind === 'words' && $lock === null)) {
                $lock = $this->lock($node, words: $kind === 'words');
            }
        }

        $field  = $this->fieldOf($node) ?? $field;
        $glyphs = [];

        foreach ($node->childNodes as $child) {
            array_push($glyphs, ...$this->collect($child, $inner, $lock, $field));
        }

        return $glyphs;
    }

    /** One character as the printer has it; E-03 (and `?`) when it has nothing like it. */
    private function printable(string $char, ?string $field, DOMNode $node): string
    {
        try {
            return Cp858::normalize($char, $field);
        } catch (UnencodableText) {
            // Tabs and line breaks are white space, collapsed later: not text to print.
            if (in_array($char, ["\t", "\n", "\r", "\f"], true)) {
                return $char;
            }

            $id = $node->parentNode instanceof DOMElement ? $this->nearestId($node->parentNode) : null;
            $this->problem('E-03', $field === null ? 'unencodable' : 'unencodable_field', $id, $field ?? $id ?? '', ['char' => $char]);

            return '?';
        }
    }

    /**
     * White space as the browser collapses it: runs of spaces become one; line breaks
     * are spaces too, except under `pre-line` (and `<br>` always), where they stay and
     * the spaces around them go. Leading and trailing spaces go.
     *
     * @param  list<array{c: string, b: bool, l: ?int}>  $glyphs
     * @return list<array{c: string, b: bool, l: ?int}>
     */
    private function collapse(array $glyphs, bool $preLine): array
    {
        $out = [];

        foreach ($glyphs as $glyph) {
            $char = $glyph['c'];

            if ($char === self::BR) {
                $glyph['c'] = $char = "\n";
            } elseif (in_array($char, ["\t", "\r", "\f"], true) || ($char === "\n" && ! $preLine)) {
                $glyph['c'] = $char = ' ';
            }

            $last = $out === [] ? null : $out[count($out) - 1]['c'];

            if ($char === ' ' && ($last === null || $last === ' ' || $last === "\n")) {
                continue;
            }

            if ($char === "\n" && $last === ' ') {
                array_pop($out);
            }

            $out[] = $glyph;
        }

        while ($out !== [] && in_array($out[count($out) - 1]['c'], [' ', "\n"], true)) {
            array_pop($out);
        }

        return $out;
    }

    /**
     * Greedy line breaking on the break opportunities: a space outside a lock, and after
     * a hyphen where Chromium breaks (`breaksAfterHyphen`). A word wider than the line breaks
     * anywhere when `$anywhere` (and it holds no locked character); otherwise it is E-04
     * and is cut only so the receipt keeps its shape.
     *
     * @param  list<array{c: string, b: bool, l: ?int}>  $glyphs  collapsed
     * @return list<list<array{c: string, b: bool, l: ?int}>>
     */
    private function wrap(array $glyphs, int $width, bool $anywhere, ?string $owner): array
    {
        if ($glyphs === []) {
            return [];
        }

        $lines     = [];
        $paragraph = [];

        foreach ([...$glyphs, ['c' => "\n", 'b' => false, 'l' => null]] as $glyph) {
            if ($glyph['c'] !== "\n") {
                $paragraph[] = $glyph;

                continue;
            }

            array_push($lines, ...$this->wrapParagraph($paragraph, $width, $anywhere, $owner));
            $paragraph = [];
        }

        return $lines;
    }

    /**
     * @param  list<array{c: string, b: bool, l: ?int}>  $glyphs
     * @return list<list<array{c: string, b: bool, l: ?int}>>
     */
    private function wrapParagraph(array $glyphs, int $width, bool $anywhere, ?string $owner): array
    {
        // Words: [the separator glyph before it (a space) or null, the word's glyphs].
        $words   = [];
        $current = [];
        $before  = null;
        $count   = count($glyphs);

        for ($i = 0; $i < $count; $i++) {
            $glyph = $glyphs[$i];

            if ($glyph['c'] === ' ' && ($glyph['l'] === null || $this->locks[$glyph['l']]['words'])) {
                $words[] = [$before, $current];
                $current = [];
                $before  = $glyph;

                continue;
            }

            $current[] = $glyph;

            $previous = $glyphs[$i - 1]['c'] ?? '';
            $next     = $glyphs[$i + 1]['c'] ?? '';

            if ($glyph['c'] === '-' && ($glyph['l'] === null || $this->locks[$glyph['l']]['words'])
                && self::breaksAfterHyphen($previous, $next)) {
                $words[] = [$before, $current];
                $current = [];
                $before  = null;
            }
        }

        $words[] = [$before, $current];

        $lines = [];
        $line  = [];

        foreach ($words as [$separator, $word]) {
            $joined = $line === [] || $separator === null ? $word : [$separator, ...$word];

            if (count($line) + count($joined) <= $width) {
                array_push($line, ...($line === [] ? $word : $joined));

                continue;
            }

            // The line breaks here: the next one starts with how it joins this one, `j`
            // (a space after a space, nothing after a hyphen), for E-01 to read it back.
            if ($line !== []) {
                $lines[] = $line;
                $line    = [];

                if ($word !== []) {
                    $word[0]['j'] = $separator === null ? '' : ' ';
                }
            }

            if (count($word) > $width) {
                $this->tooWide($word, $width, $anywhere, $owner);

                foreach (array_chunk($word, $width) as $index => $piece) {
                    if ($index > 0) {
                        $piece[0]['j'] = '';
                    }

                    $lines[] = $piece;
                }

                $line = array_pop($lines);

                continue;
            }

            $line = $word;
        }

        $lines[] = $line;

        return $lines;
    }

    /**
     * Whether Chromium breaks a line after this hyphen, measured through Gotenberg with the
     * hyphen at the line's edge (RollRenderTest::HYPHEN_CASES):
     *
     * - before an ASCII digit, only after an ASCII letter or digit: `4200-|072`, `ABC-|123`,
     *   but a minus sign stays on its number (` -12,00`, `ã-123`, `)-123`, `.-123`);
     * - before a letter, `(`, `"`, `%`, `+` or a currency sign, always,
     *   even word-initial (` -|abc`, `)-|abc`, `ab-|(123`);
     * - before anything else (a full stop, a comma, a closing bracket, a space…), never:
     *   only the characters above were measured, and not breaking keeps a value whole.
     */
    private static function breaksAfterHyphen(string $previous, string $next): bool
    {
        if (preg_match('/^[0-9]$/', $next)) {
            return preg_match('/^[A-Za-z0-9]$/', $previous) === 1;
        }

        return preg_match('/^[\p{L}("%+\p{Sc}]$/u', $next) === 1;
    }

    /** @param list<array{c: string, b: bool, l: ?int}> $word */
    private function tooWide(array $word, int $width, bool $anywhere, ?string $owner): void
    {
        foreach ($word as $glyph) {
            if ($glyph['l'] !== null) {
                $lock = $this->locks[$glyph['l']];
                $this->problem('E-04', 'value_too_wide', $lock['owner'], $lock['subject'], ['width' => $width]);

                return;
            }
        }

        if (! $anywhere) {
            $text = implode('', array_column($word, 'c'));
            $this->problem('E-04', 'word_too_wide', $owner, $text, ['width' => $width]);
        }
    }

    /**
     * @param  list<array{c: string, b: bool, l: ?int}>  $glyphs
     * @return list<array{c: string, b: bool, l: ?int}>
     */
    private function pad(array $glyphs, int $width, string $align): array
    {
        $glyphs = array_slice($glyphs, 0, max(0, $width));
        $free   = $width - count($glyphs);
        $left   = match ($align) {
            'right'  => $free,
            'center' => intdiv($free, 2),
            default  => 0,
        };

        return [...$this->glyphs(str_repeat(' ', $left)), ...$glyphs, ...$this->glyphs(str_repeat(' ', $free - $left))];
    }

    /** @return list<array{c: string, b: bool, l: ?int}> */
    private function glyphs(string $ascii): array
    {
        return array_map(fn (string $c): array => ['c' => $c, 'b' => false, 'l' => null], $ascii === '' ? [] : str_split($ascii));
    }

    /**
     * @param  list<array{c: string, b: bool, l: ?int}>  $glyphs
     * @param  list<?string>  $sources
     * @return array{kind: 'line', text: string, double: bool, bold: list<array{0: int, 1: int}>, sources: list<string>, group: int, joint: string}
     */
    private function lineBlock(array $glyphs, bool $double, array $sources, int $group, string $joint = ' '): array
    {
        $bold  = [];
        $start = null;

        foreach ([...$glyphs, ['c' => '', 'b' => false, 'l' => null]] as $index => $glyph) {
            if ($glyph['b'] && $start === null) {
                $start = $index;
            } elseif (! $glyph['b'] && $start !== null) {
                $bold[] = [$start, $index];
                $start  = null;
            }
        }

        return [
            'kind'    => 'line',
            'text'    => implode('', array_column($glyphs, 'c')),
            'double'  => $double,
            'bold'    => $bold,
            'sources' => array_values(array_unique(array_filter($sources, fn (?string $s): bool => $s !== null && $s !== ''))),
            'group'   => $group,
            'joint'   => $joint,
        ];
    }

    /* ---------------------------------------------------------------------
     * Reading the markup
     * ------------------------------------------------------------------ */

    /**
     * The inherited formatting under an element: alignment, bold, double size and nowrap
     * (all four inherit in CSS).
     *
     * @param  array{align: string, bold: bool, double: bool, nowrap: bool}  $context
     * @return array{align: string, bold: bool, double: bool, nowrap: bool}
     */
    private function contextOf(DOMElement $element, array $context): array
    {
        $tag = strtolower($element->tagName);

        foreach (['left', 'center', 'right'] as $align) {
            if ($this->hasClass($element, 'esc-'.$align)) {
                $context['align'] = $align;
            }
        }

        $context['bold']   = $context['bold'] || $this->hasClass($element, 'esc-bold') || in_array($tag, ['b', 'strong'], true);
        $context['double'] = $context['double'] || $this->hasClass($element, 'esc-double');
        $context['nowrap'] = $context['nowrap'] || $this->hasClass($element, 'esc-nowrap');

        return $context;
    }

    private function isBlock(DOMNode $node): bool
    {
        if (! $node instanceof DOMElement) {
            return false;
        }

        if (in_array(strtolower($node->tagName), self::BLOCK_TAGS, true)
            || in_array($node->getAttribute('data-tpl-type'), self::BLOCK_TYPES, true)) {
            return true;
        }

        foreach (self::BLOCK_CLASSES as $class) {
            if ($this->hasClass($node, $class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * How an element's text may break:
     *
     * - `whole` — never: in a nowrap context (`esc-nowrap`, an unwrapped `.block`) or a
     *   formatted data field (money, number, date…). If it does not fit, it is E-04;
     * - `words` — a legal text with no format: the editor's mark (`data-tpl-legal="1"`, which
     *   it writes on every legal field it saves: components.js) or a binding `$fields` says
     *   is legal (markup saved before the mark existed), with a space in it. It wraps as the
     *   PDF wraps it, on spaces and hyphens, and never inside a word;
     * - `whole` too — a legal value with no space in it (a NIF, an ATCUD): a token stays a
     *   token, hyphen or not, and wider than the line is E-04;
     * - null — ordinary text, broken as the browser breaks it.
     *
     * @return 'whole'|'words'|null
     */
    private function lockKind(DOMElement $element, bool $nowrap): ?string
    {
        $field = $element->getAttribute('data-tpl-type') === 'data-field';

        if ($nowrap || ($field && $element->getAttribute('data-tpl-format') !== '')) {
            return 'whole';
        }

        if ($element->getAttribute('data-tpl-legal') !== '1'
            && ! ($field && isset($this->legal[$element->getAttribute('data-tpl-binding')]))) {
            return null;
        }

        // A legal token (no breaking space inside; NBSP is none) stays whole, hyphen or not.
        return preg_match('/[ \t\n\r\f]/', trim((string) $element->textContent)) ? 'words' : 'whole';
    }

    /** The lock a block's or a cell's own text takes, if any (see `lockKind`). */
    private function lockFor(DOMElement $element, bool $nowrap): ?int
    {
        $kind = $this->lockKind($element, $nowrap);

        return $kind === null ? null : $this->lock($element, words: $kind === 'words');
    }

    /**
     * A text column of the line table: no format and no formula. The rule is
     * `RowHeights::isText` (private there): change both together.
     *
     * @param  array<string, mixed>  $column
     */
    private static function isTextColumn(array $column): bool
    {
        $printed = (string) ($column['formulaKey'] ?? $column['key'] ?? '');

        return ($column['format'] ?? '') === ''
            && empty($column['formula'])
            && ! str_starts_with($printed, 'formula_');
    }

    private function fieldOf(DOMElement $element): ?string
    {
        return $element->getAttribute('data-tpl-type') === 'data-field' && $element->getAttribute('data-tpl-binding') !== ''
            ? $element->getAttribute('data-tpl-binding')
            : null;
    }

    /**
     * A new locked run, reported by the element's id and its field (or id). With `$words`
     * it still breaks on its spaces (a legal text), never inside a word.
     */
    private function lock(DOMElement $element, ?string $subject = null, ?string $owner = null, bool $words = false): int
    {
        $owner ??= $this->nearestId($element);

        $this->locks[] = [
            'owner'   => $owner,
            'subject' => $subject ?? $this->fieldOf($element) ?? $owner ?? strtolower($element->tagName),
            'words'   => $words,
        ];

        return count($this->locks) - 1;
    }

    private function inlineAlign(DOMElement $cell): ?string
    {
        return preg_match('/(?:^|;)\s*text-align\s*:\s*([a-z]+)/i', $cell->getAttribute('style'), $match)
            ? $this->alignment(strtolower($match[1]))
            : null;
    }

    private function alignment(string $value): ?string
    {
        return in_array($value, ['left', 'center', 'right'], true) ? $value : null;
    }

    private function hasClass(DOMElement $element, string $class): bool
    {
        return in_array($class, preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [], true);
    }

    private function descendant(DOMElement $element, string $path): ?DOMElement
    {
        $found = (new DOMXPath($element->ownerDocument))->query('.//'.$path, $element);
        $first = $found === false ? null : $found->item(0);

        return $first instanceof DOMElement ? $first : null;
    }

    /** The element's id, or its nearest ancestor's: the component the editor selects. */
    private function nearestId(DOMElement $element): ?string
    {
        for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode) {
            if (in_array(strtolower($node->tagName), ['body', 'html'], true)) {
                break;
            }

            if ($node->getAttribute('id') !== '') {
                return $node->getAttribute('id');
            }
        }

        return null;
    }

    /** @param array<string, scalar> $replace */
    private function problem(string $code, string $key, ?string $componentId, string $subject, array $replace = []): void
    {
        $seen = $code.'|'.$key.'|'.$componentId.'|'.$subject.'|'.($replace['char'] ?? '');

        if (isset($this->reported[$seen])) {
            return;
        }

        $this->reported[$seen] = true;
        $this->problems[]      = [
            'code'         => $code,
            'component_id' => $componentId,
            'message'      => __('templates::editor.escpos.'.$key, ['subject' => $subject, 'columns' => $this->columns] + $replace),
            'key'          => $key,
            'subject'      => $subject,
        ];
    }
}
