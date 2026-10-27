<?php

namespace Controlink\Templates\Output\Pdf;

use Controlink\Templates\Codes\CodeImageGenerator;
use Controlink\Templates\Exceptions\UnsafeTemplateException;
use Controlink\Templates\Formulas\FormulaSet;
use Controlink\Templates\Rendering\TemplateRenderer;
use Controlink\Templates\Support\PageGeometry;
use Controlink\Templates\Types\TemplateType;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Turns what the editor exported into the single HTML document handed to the PDF
 * engine (spec 7.1, steps 1 to 3).
 *
 * Two rules shape everything here:
 *
 * - The canvas and the engine are handed the same print stylesheet, so any visual
 *   divergence between what the user drew and what is printed is a defect rather than
 *   a configuration difference (spec section 5).
 * - The engine has no network access, so every asset is embedded. Fonts arrive as
 *   data URIs built from the very file the browser canvas loaded (spec 3.2).
 */
class DocumentComposer
{
    private const PRINT_STYLESHEET = __DIR__.'/../../../resources/css/print.css';

    /** Anything that could execute, or reach outside the document. */
    private const FORBIDDEN_HTML = [
        '/<\s*script/i',
        '/<\s*iframe/i',
        '/\son[a-z]+\s*=/i',
        '/javascript\s*:/i',
    ];

    /**
     * @param  array<string, mixed>  $dataset  the values to render, real or fixture
     *
     * @throws UnsafeTemplateException when the payload carries script or external URLs
     * @throws RuntimeException when the template or the code slots cannot be rendered
     */
    public function compose(string $html, string $css, TemplateType $type, array $dataset = []): string
    {
        /*
         * Formulas (CGE-37), before anything else reads the markup.
         *
         * Their source is user-typed text sitting in attributes, and a text literal may
         * well contain `{{`, `{% %}` or `javascript:`. Taken out here, it never reaches
         * the guard (which would refuse a harmless document) nor Twig (which would try
         * to run it). The results go into the dataset now, over the WHOLE dataset —
         * PageStructure slices the collections per sheet next, and a SUM computed after
         * that would be one sheet's subtotal printed as the document's total.
         *
         * A formula that reads a page value (CGE-79) is evaluated again per sheet below,
         * over the same whole dataset, once that sheet's page values are known.
         */
        $formulas = FormulaSet::fromHtml($html);
        $html     = $formulas->strippedHtml();
        $whole    = $dataset;
        $dataset  = $formulas->inject($dataset);

        $this->guardHtml($html);
        $this->guardCss($css);

        /*
         * Step 3: the compositor decides the sheets.
         *
         * Not the engine: its pagination drops the repeated header when the last page
         * is short, and padding the body to fix that pushes out blank pages. Both were
         * measured; see PageStructure and NOTAS-FIDELIDADE.
         */
        /*
         * The version CSS travels with the body. The editor runs GrapesJS with
         * `avoidInlineStyle: true`, so a block dragged on the canvas has its `top`
         * written into a `#id{…}` rule in this CSS, not inline — and the compositor
         * needs that `top` to order the line tables and compute each page's capacity
         * (spec 3.1). Without it every editor-placed table would resolve to top 0.
         */
        $structure = app(PageStructure::class)->build($this->bodyOf($html), $type, $dataset, $css, $formulas->pageFields());
        $geometry  = PageGeometry::fromType($type->pdf);

        // A roll's line table text breaks on words (CGE-97); a sheet gets no new bytes.
        if ($geometry->medium === 'roll') {
            $structure['css'] .= app(PageStructure::class)->rollWrapCss($this->bodyOf($html), $css);
        }

        $renderer = app(TemplateRenderer::class);
        $codes    = app(CodeImageGenerator::class);
        $bindings = $type->bindingsByKey();

        $body = '';

        foreach ($structure['pages'] as $page) {
            /*
             * Step 4: Twig, over one sheet at a time.
             *
             * Per sheet because each one carries its own slice of the line collection
             * and its own page numbers — which is what lets `{% for %}` stay the only
             * tag the sandbox allows and the page number be an ordinary binding.
             *
             * Deliberately not over the whole document: the stylesheets carry braces of
             * their own and one of them is a base64 font, and a template engine let
             * loose on all of that is one stray `{{` away from a render failure with no
             * explanation.
             */
            $page['dataset'] = $formulas->injectPage($page['dataset'], $whole);

            $sheet = $renderer->render($page['template'], $page['dataset']);

            // Step 5: the code slots, generated on the server and embedded.
            $body .= $codes->inject($sheet, $page['dataset'], $bindings);
        }

        $lang = e(str_replace('_', '-', app()->getLocale()));

        return '<!doctype html><html lang="'.$lang.'"><head><meta charset="utf-8">'
            .'<style>'.$this->fontFaces().'</style>'
            .'<style>'.$this->printStylesheet().'</style>'
            .'<style>'.$this->pageVariables($type).'</style>'
            .'<style>'.$this->geometryStyles($geometry).'</style>'
            .'<style>'.$css.'</style>'
            .'<style>'.$structure['css'].'</style>'
            .'</head><body>'.$body.'</body></html>';
    }

    /**
     * The page geometry as a CSS override, emitted after print.css so it wins by
     * cascade. Sheets restate the named format; rolls set width and auto height.
     */
    public function geometryStyles(PageGeometry $g): string
    {
        if ($g->medium === 'email') {
            // Email renders HTML, never a PDF: reaching the PDF composer is a routing bug.
            throw new \LogicException('An email template has no page geometry and must not be rendered as a PDF.');
        }

        if ($g->medium === 'roll') {
            // Provisional height: PdfRenderer re-emits the measured one.
            $height = $g->maxHeightMm ?: 4000.0;

            // The roll's margins become the page padding (box-sizing keeps the paper width):
            // without them the content runs to the paper edge, falls outside the printable
            // area (R-04) and the edge-most values are clipped out of the PDF (R-02). The
            // BOTTOM is 0: a roll is cut at its content, and a bottom pad is trailing white
            // the measure-to-fit cannot see, so it would overflow into a phantom page 2.
            $m       = $g->margins;
            $padding = $m['top'].'mm '.$m['right'].'mm 0 '.$m['left'].'mm';

            return '@page{size: '.$g->widthMm.'mm '.$height.'mm;margin:0}'
                .'.page{width: '.$g->widthMm.'mm;height: auto;overflow: visible;'
                .'box-sizing: border-box;padding: '.$padding.'}'
                .self::rollGrid($g);
        }

        // A label: a sheet sized in mm, so the @page is explicit millimetres (no CSS
        // format name) and the .page is a fixed box — it clips rather than paginating,
        // and all four margins become its padding.
        if ($g->paperFormat === null && $g->widthMm > 0 && $g->heightMm > 0) {
            $m       = $g->margins;
            $padding = $m['top'].'mm '.$m['right'].'mm '.$m['bottom'].'mm '.$m['left'].'mm';

            return '@page{size: '.$g->widthMm.'mm '.$g->heightMm.'mm;margin:0}'
                .'.page{width: '.$g->widthMm.'mm;height: '.$g->heightMm.'mm;overflow: hidden;'
                .'box-sizing: border-box;padding: '.$padding.'}';
        }

        // The box too, not only the paper (CGE-56): print.css draws `.page` at A4, and an
        // A5 paper carrying an A4 box spilled it over several pages. `html .page` for the
        // reason pageVariables() gives — the saved layout CSS comes later.
        $size = $g->sheetSizeMm();

        return '@page{size: '.$g->paperFormat.';margin:0}'
            .'html .page{width: '.$size['width'].'mm;height: '.$size['height'].'mm}';
    }

    /**
     * A roll is a monospace character grid (CGE-97): `columns` characters fill its
     * printable width, as they do on the ESC/POS printer. The editor's canvas emits the
     * same rule (`rollGridCss` in `roll-grid.js`).
     *
     * `html .page`, not `.page`: `pageVariables()` sets the family on `html .page` first,
     * and a `.page` rule would lose to it. The dashed rule is the roll's only: on a sheet a
     * rule block is a filled box, and these declarations would move and redraw it.
     */
    private static function rollGrid(PageGeometry $g): string
    {
        // A roll with no width has no grid to draw (the editor's rollGridCss agrees).
        if ($g->charWidthMm() <= 0) {
            return '';
        }

        return 'html .page{font-family:"Liberation Mono","Courier New",monospace;'
            .'font-size:'.round($g->fontSizeMm(), 3).'mm;line-height:1.25}'
            .'.page hr,.page [data-tpl-type="rule"]{border:0;border-top:1px dashed #000;background:none;margin:0.5em 0}';
    }

    /**
     * One `@font-face` per family and weight, with each font file inlined.
     *
     * A face listed in the catalogue whose file is missing is fatal, not something to
     * skip: the engine would silently substitute or synthesise it, and every truncation
     * and legibility measurement would then be made against metrics nobody chose.
     */
    public function fontFaces(): string
    {
        $rules = '';

        foreach ((array) config('templates.fonts') as $family => $font) {
            foreach ((array) ($font['weights'] ?? []) as $weight => $face) {
                $path = self::fontPath($face['path']);

                if (! File::exists($path)) {
                    throw new RuntimeException("Template font file is missing: {$face['path']}");
                }

                $uri = 'data:'.$face['mime'].';base64,'.base64_encode(File::get($path));

                $rules .= '@font-face{font-family:"'.$family.'";font-style:normal;'
                    .'font-weight:'.(int) $weight.';'
                    .'src:url('.$uri.') format("'.$face['format'].'")}';
            }
        }

        return $rules;
    }

    /** The print stylesheet, read from the same file the editor canvas is given. */
    public function printStylesheet(): string
    {
        if (! File::exists(self::PRINT_STYLESHEET)) {
            throw new RuntimeException('Print stylesheet is missing: '.self::PRINT_STYLESHEET);
        }

        return File::get(self::PRINT_STYLESHEET);
    }

    /**
     * A short fingerprint of the print stylesheet, so a caller (the editor's canvas
     * bootstrap) can tell whether the copy it cached is still the one the compositor
     * would embed.
     */
    public function printStylesheetVersion(): string
    {
        return substr(hash('sha256', $this->printStylesheet()), 0, 16);
    }

    /**
     * The template's page geometry, as the custom properties `print.css` reads: the
     * four margins and the height of the two zone bands.
     *
     * The editor canvas is given the same values from the same config, so the header
     * band the user sees is the header band that prints.
     *
     * Also carries the page's font family. `print.css` is a fixed, host-agnostic
     * asset — it cannot know which family a given host configured — so it ships with a
     * placeholder (`CGestSans`, the original host's own family). Emitting the real,
     * configured family here overrides that placeholder: this block is written after
     * `print.css` in `<head>`, so the later declaration wins. Without it, a host that
     * never overrides `templates.fonts` gets a document whose `@font-face` embeds
     * `LiberationSans` while `.page` still asks for a family nothing declares, and
     * Chromium silently substitutes whatever sans-serif the Gotenberg container
     * happens to carry — the canvas/PDF fidelity guarantee this module exists for,
     * broken out of the box.
     *
     * @throws RuntimeException when no font family is configured at all
     */
    public function pageVariables(TemplateType $type): string
    {
        $margins = $type->pdf['margins'] ?? [];
        $zones   = $type->initialLayout['zones'] ?? [];

        $declarations = '';

        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $declarations .= '--margin-'.$side.':'.(float) ($margins[$side] ?? 0).'mm;';
        }

        $declarations .= '--header-height:'.(float) ($zones['header_height'] ?? 35).'mm;';
        $declarations .= '--footer-height:'.(float) ($zones['footer_height'] ?? 20).'mm;';

        $family = array_key_first((array) config('templates.fonts'));

        if ($family === null) {
            throw new RuntimeException('No template font is configured.');
        }

        $declarations .= 'font-family:"'.addcslashes((string) $family, '"\\').'",sans-serif;';

        // `html .page`, not `.page` (CGE-50): the layout's own CSS comes later in the
        // document and carries a `.page{--margin-…}` rule the editor wrote with the values
        // of its day. The geometry is the server's; at equal specificity the stale rule won.
        return 'html .page{'.$declarations.'}';
    }

    /**
     * Run the safety guards without composing anything.
     *
     * The static validation needs the same verdict the compositor reaches, as a
     * validation error rather than as an exception at render time (V-07).
     *
     * @throws UnsafeTemplateException
     */
    public function assertSafe(string $html, string $css): void
    {
        // The same markup compose() guards: formula sources are removed first there,
        // so a literal like "javascript:" in one must not fail the layout here either.
        $this->guardHtml(FormulaSet::fromHtml($html)->strippedHtml());
        $this->guardCss($css);
    }

    /** @throws UnsafeTemplateException */
    private function guardHtml(string $html): void
    {
        foreach (self::FORBIDDEN_HTML as $pattern) {
            if (preg_match($pattern, $html) === 1) {
                throw new UnsafeTemplateException('Template HTML carries executable content.');
            }
        }
    }

    /**
     * Every `url()` in the stylesheet has to be a data URI. An external one would
     * make the rendered document depend on the network the engine does not have, and
     * would leak a request out of it.
     *
     * Two more things are refused before that check ever runs, because both let a
     * "CSS" payload reach past the `<style>` tag it is injected into (`compose()`
     * wraps `$css` in `<style>…</style>` with nothing escaping it): a literal
     * `</style>` closes the element early and hands the rest of the payload to the
     * HTML parser as ordinary markup — `<script>`, in the payload this guards
     * against — and `@import` reaches outside the document exactly like an external
     * `url()` would, only unconditionally and before any `url()` pattern is matched.
     *
     * @throws UnsafeTemplateException
     */
    private function guardCss(string $css): void
    {
        if (preg_match('/<\s*\/\s*style/i', $css) === 1) {
            throw new UnsafeTemplateException('Template CSS closes its enclosing <style> tag.');
        }

        if (preg_match('/@import\b/i', $css) === 1) {
            throw new UnsafeTemplateException('Template CSS uses @import.');
        }

        preg_match_all('/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', $css, $matches);

        foreach ($matches[2] as $reference) {
            if (! str_starts_with(mb_strtolower(trim($reference)), 'data:')) {
                throw new UnsafeTemplateException('Template CSS references an external URL: '.$reference);
            }
        }
    }

    /** The editor exports its document wrapped in `<body>`; the compositor supplies its own. */
    private function bodyOf(string $html): string
    {
        $trimmed = trim($html);

        if (preg_match('/^<body[^>]*>(.*)<\/body>$/is', $trimmed, $matches) === 1) {
            return $matches[1];
        }

        return $trimmed;
    }

    /**
     * Absolute paths (the package's own default font) are used as they are; relative
     * ones are relative to the host's public directory, where the canvas loads them.
     */
    public static function fontPath(string $path): string
    {
        return preg_match('/^([a-zA-Z]:[\\\\\/]|\/)/', $path) === 1 ? $path : public_path($path);
    }
}
