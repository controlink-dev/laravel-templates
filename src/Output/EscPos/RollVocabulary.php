<?php

namespace Controlink\Templates\Output\EscPos;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * V-21: a receipt (a roll) written only in the receipt vocabulary (CGE-97).
 *
 * A roll prints on a thermal printer through ESC/POS, which knows a fixed set of things:
 * double size, bold, alignment, blank lines, rows of cells N characters wide (the `esc-*`
 * classes of print.css), rules, the line table on its character grid, and images and codes
 * of a size. Anything else the PDF would draw and the printer would not, so the PDF would
 * stop being what the customer gets. This rule finds it, naming the selector or element.
 *
 * The editor runs the same rule live as L-ESC: `resources/js/editor/roll-vocabulary.js`.
 * The two must give the same verdict on the same layout; the cases both tests read are in
 * `tests/Fixtures/roll-vocabulary-cases.json`. Change the lists here and there together.
 *
 * Not part of the save validation: it runs at activation and before an ESC/POS translation.
 */
class RollVocabulary
{
    /**
     * The receipt classes print.css ships (Task 3), and nothing else starting with `esc-`.
     * Mirror: `ROLL_CLASSES` in roll-vocabulary.js. A test reads print.css against this.
     */
    public const CLASSES = [
        'esc-double', 'esc-bold', 'esc-left', 'esc-center', 'esc-right',
        'esc-gap-1', 'esc-gap-2', 'esc-gap-3', 'esc-row', 'esc-cell', 'esc-nowrap',
    ];

    /*
     * What the editor writes on every save, which must pass (CGE-97 Task 4, Step 0). Read
     * from the save path (editor.js `saveVersion`: `editor.getHtml()` / `editor.getCss()`
     * with no options) and confirmed on versions saved in the local database:
     *
     * - CSS: GrapesJS's default `protectedCss`, `* { box-sizing: border-box; } body
     *   {margin: 0;}`, leads every save; then `.page{--margin-*;--header-height;
     *   --footer-height}`, the page variables (editor.js `pageVariables`, added to the CSS
     *   composer at load), always in mm; then a `#id{…}` rule per styled component, because
     *   GrapesJS writes a component's style as a rule on its id, not inline. On a receipt
     *   that is the size of the code slot (`#tpl-qr{width:30mm;height:30mm}`, table.js) and
     *   of an image (25mm by default, components.js).
     * - HTML: `<body>…</body>` around the page; no inline style from GrapesJS itself; the
     *   code slot is an `<img data-tpl-type="code-slot">` with no style (its size is the
     *   `#id` rule above); the line table writes `style` itself (table.js `thStyle`,
     *   line-rows.js `colgroupHTML`/`rowsHTML`): `text-align` on every th/td and `width` on
     *   th/col, as `normalizeWidth` wrote it.
     *
     * A line table width, in its `data-tpl-columns` (roll_column_width) and in a cell's
     * inline `width` (roll_inline_style), must be a whole number of characters (`12ch`): a
     * receipt is a grid of characters. A column in mm is reported by both, which also catches
     * hand-written markup whose cells disagree with its `data-tpl-columns`.
     *
     * Not on the list, and reported if they appear: a print-rule's own size and colour
     * (components.js `applyGeometry` writes `#id{height;background-color}`; a receipt's
     * rule is a plain `hr`), and the width the roll snap writes on a block (panels.js).
     */
    private const PROTECTED_CSS = [
        '*'    => ['box-sizing' => 'border-box'],
        'body' => ['margin' => '0'],
    ];

    /** The page variables the editor writes on `.page`, all lengths in mm. */
    private const PAGE_VARIABLES = [
        '--margin-top', '--margin-right', '--margin-bottom', '--margin-left',
        '--header-height', '--footer-height',
    ];

    /** The block types whose size an `#id` rule (or inline style) may set, in mm. */
    private const SIZED_TYPES = ['code-slot', 'image'];

    private const ALIGNMENTS = ['left', 'center', 'right'];

    private const MM = '/^(\d+(\.\d+)?|\.\d+)mm$/D';

    private const CHARACTERS = '/^[1-9]\d*ch$/D';

    /**
     * @return list<array{code: 'V-21', component_id: ?string, message: string, key: string, subject: string}>
     */
    public static function problems(string $html, string $css, int $columns): array
    {
        $body     = self::body($html);
        $elements = $body === null ? [] : self::elements($body);

        $ids = [];

        foreach ($elements as $element) {
            $id = $element->getAttribute('id');

            if ($id !== '' && ! isset($ids[$id])) {
                $ids[$id] = $element;
            }
        }

        $problems = [];

        foreach (self::rules($css) as [$selector, $declarations]) {
            if (! self::allowedRule($selector, $declarations, $ids)) {
                $problems[] = self::problem('roll_css', self::firstId($selector), $selector, $columns);
            }
        }

        foreach ($elements as $element) {
            array_push($problems, ...self::elementProblems($element, $columns));
        }

        return $problems;
    }

    /* ---------------------------------------------------------------------
     * CSS
     * ------------------------------------------------------------------ */

    /**
     * The rules of a stylesheet, `[selector, declarations]`, in order. An at-rule comes back
     * as its prelude (`@media print`) with null declarations: none is allowed.
     *
     * @return list<array{string, ?string}>
     */
    private static function rules(string $css): array
    {
        $css   = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        $rules = [];
        $at    = 0;
        $end   = strlen($css);

        while ($at < $end) {
            $open = strpos($css, '{', $at);
            $semi = strpos($css, ';', $at);

            // `@import …;` and the like: an at-rule with no block.
            if ($semi !== false && ($open === false || $semi < $open) && str_starts_with(ltrim(substr($css, $at, $semi - $at)), '@')) {
                $rules[] = [self::squash(substr($css, $at, $semi - $at)), null];
                $at      = $semi + 1;

                continue;
            }

            if ($open === false) {
                break;
            }

            $prelude = self::squash(substr($css, $at, $open - $at));
            $depth   = 1;
            $cursor  = $open + 1;

            while ($cursor < $end && $depth > 0) {
                $char = $css[$cursor];
                $depth += $char === '{' ? 1 : ($char === '}' ? -1 : 0);
                $cursor++;
            }

            $block = substr($css, $open + 1, max(0, $cursor - $open - 2));
            $rules[] = [$prelude, str_starts_with($prelude, '@') ? null : $block];
            $at      = $cursor;
        }

        return $rules;
    }

    /** @param array<string, DOMElement> $ids */
    private static function allowedRule(string $selector, ?string $block, array $ids): bool
    {
        if ($block === null) {
            return false;
        }

        $declarations = self::declarations($block);

        foreach (explode(',', $selector) as $part) {
            if (! self::allowedFor(trim($part), $declarations, $ids)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, string>  $declarations
     * @param  array<string, DOMElement>  $ids
     */
    private static function allowedFor(string $selector, array $declarations, array $ids): bool
    {
        if (isset(self::PROTECTED_CSS[$selector])) {
            return $declarations === self::PROTECTED_CSS[$selector];
        }

        if ($selector === '.page') {
            foreach ($declarations as $property => $value) {
                if (! in_array($property, self::PAGE_VARIABLES, true) || ! preg_match(self::MM, $value)) {
                    return false;
                }
            }

            return true;
        }

        if (preg_match('/^#([A-Za-z_][\w-]*)$/D', $selector, $match) && isset($ids[$match[1]]) && self::isSized($ids[$match[1]])) {
            return self::onlySizes($declarations);
        }

        return false;
    }

    /** @return array<string, string> property => value, lowercased property, last one wins */
    private static function declarations(string $block): array
    {
        $declarations = [];

        foreach (explode(';', $block) as $declaration) {
            if (trim($declaration) === '') {
                continue;
            }

            $colon = strpos($declaration, ':');

            // A declaration with no value is nothing the printer could print either.
            $property = strtolower(trim($colon === false ? $declaration : substr($declaration, 0, $colon)));
            $declarations[$property] = $colon === false ? '' : self::squash(substr($declaration, $colon + 1));
        }

        return $declarations;
    }

    /** @param array<string, string> $declarations */
    private static function onlySizes(array $declarations): bool
    {
        foreach ($declarations as $property => $value) {
            if (! in_array($property, ['width', 'height'], true) || ! preg_match(self::MM, $value)) {
                return false;
            }
        }

        return true;
    }

    /* ---------------------------------------------------------------------
     * HTML
     * ------------------------------------------------------------------ */

    private static function body(string $html): ?DOMElement
    {
        if (trim($html) === '') {
            return null;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $document->getElementsByTagName('body')->item(0);

        return $body instanceof DOMElement ? $body : null;
    }

    /** @return list<DOMElement> every element under the body, in document order */
    private static function elements(DOMNode $node): array
    {
        $elements = [];

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $elements[] = $child;
                array_push($elements, ...self::elements($child));
            }
        }

        return $elements;
    }

    /** @return list<array{code: 'V-21', component_id: ?string, message: string, key: string, subject: string}> */
    private static function elementProblems(DOMElement $element, int $columns): array
    {
        $problems  = [];
        $component = self::nearestId($element);
        $lineTable = self::lineTableOf($element);

        if (trim($element->getAttribute('style')) !== '' && ! self::allowedStyle($element, $lineTable)) {
            $problems[] = self::problem('roll_inline_style', $component, self::describe($element), $columns);
        }

        foreach (preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [] as $class) {
            if (str_starts_with($class, 'esc-') && ! in_array($class, self::CLASSES, true)) {
                $problems[] = self::problem('roll_class', $component, $class, $columns);
            }
        }

        if ($element->hasAttribute('data-tpl-cols')) {
            $cols = $element->getAttribute('data-tpl-cols');

            if (! preg_match('/^\d+$/D', $cols) || (int) $cols > $columns) {
                $problems[] = self::problem('roll_cols', $component, $cols, $columns);
            }
        }

        if (strtolower($element->tagName) === 'table' && $lineTable === null) {
            $problems[] = self::problem('roll_table', $component, self::describe($element), $columns);
        }

        if ($element->getAttribute('data-tpl-type') === 'line-table') {
            foreach (self::columnsOf($element) as $column) {
                $width = strtolower(trim((string) ($column['width'] ?? '')));

                if ($width !== '' && ! preg_match(self::CHARACTERS, $width)) {
                    $label      = (string) ($column['label'] ?? $column['key'] ?? '');
                    $problems[] = self::problem('roll_column_width', $component, $label.' ('.$width.')', $columns);
                }
            }
        }

        return $problems;
    }

    private static function allowedStyle(DOMElement $element, ?DOMElement $lineTable): bool
    {
        $declarations = self::declarations($element->getAttribute('style'));

        if (self::isSized($element)) {
            return self::onlySizes($declarations);
        }

        if ($lineTable !== null && in_array(strtolower($element->tagName), ['col', 'th', 'td'], true)) {
            foreach ($declarations as $property => $value) {
                $allowed = match ($property) {
                    'text-align' => in_array($value, self::ALIGNMENTS, true),
                    'width'      => (bool) preg_match(self::CHARACTERS, $value),
                    default      => false,
                };

                if (! $allowed) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /** An image, the code slot or an image block: the elements a size in mm may be set on. */
    private static function isSized(DOMElement $element): bool
    {
        return strtolower($element->tagName) === 'img'
            || in_array($element->getAttribute('data-tpl-type'), self::SIZED_TYPES, true);
    }

    /** The line table this element sits in (or is), or null. */
    private static function lineTableOf(DOMElement $element): ?DOMElement
    {
        for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode) {
            if ($node->getAttribute('data-tpl-type') === 'line-table') {
                return $node;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> the visible columns of a line table */
    private static function columnsOf(DOMElement $table): array
    {
        $columns = json_decode($table->getAttribute('data-tpl-columns'), true);

        if (! is_array($columns)) {
            return [];
        }

        return array_values(array_filter(
            $columns,
            fn ($column) => is_array($column) && empty($column['hidden']),
        ));
    }

    /** The element's own id, or its nearest ancestor's: the component the editor selects. */
    private static function nearestId(DOMElement $element): ?string
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

    /** `div#id`, else `div.class.other`, else `div`. */
    private static function describe(DOMElement $element): string
    {
        $tag = strtolower($element->tagName);

        if ($element->getAttribute('id') !== '') {
            return $tag.'#'.$element->getAttribute('id');
        }

        $classes = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];
        $classes = array_filter($classes, fn (string $class) => $class !== '');

        return $classes === [] ? $tag : $tag.'.'.implode('.', $classes);
    }

    /* ---------------------------------------------------------------------
     * Shared
     * ------------------------------------------------------------------ */

    private static function firstId(string $selector): ?string
    {
        return preg_match('/#([A-Za-z_][\w-]*)/', $selector, $match) ? $match[1] : null;
    }

    private static function squash(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /** @return array{code: 'V-21', component_id: ?string, message: string, key: string, subject: string} */
    private static function problem(string $key, ?string $componentId, string $subject, int $columns): array
    {
        return [
            'code'         => 'V-21',
            'component_id' => $componentId,
            'message'      => __('templates::editor.validation.'.$key, ['subject' => $subject, 'columns' => $columns]),
            'key'          => $key,
            'subject'      => $subject,
        ];
    }
}
