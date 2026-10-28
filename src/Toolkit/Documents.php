<?php

namespace Controlink\Templates\Toolkit;

use Controlink\Templates\Codes\BarcodeFailure;
use Controlink\Templates\Codes\Code128Fit;
use Controlink\Templates\Codes\CodePayload;
use Controlink\Templates\Formulas\Formula;
use Controlink\Templates\Output\Labels\LabelBatchFailed;
use Controlink\Templates\Output\EscPos\EscPosEncoder;
use Controlink\Templates\Output\EscPos\EscPosValidator;
use Controlink\Templates\Output\EscPos\ReceiptBuilder;
use Controlink\Templates\Output\EscPos\ReceiptPreview;
use Controlink\Templates\Output\EscPos\RollVocabulary;
use Controlink\Templates\Output\Pdf\DocumentComposer;
use Controlink\Templates\Output\Pdf\LayoutDocument;
use Controlink\Templates\Output\Pdf\LayoutValidator;
use Controlink\Templates\Output\Pdf\PdfHash;
use Controlink\Templates\Output\Pdf\PdfRenderer;
use Controlink\Templates\Output\Pdf\RenderValidator;
use Controlink\Templates\Rendering\FormatSpec;
use Controlink\Templates\Support\PageGeometry;
use Controlink\Templates\Types\TemplateType;
use Controlink\Templates\Versions\CanonicalHash;
use InvalidArgumentException;

/**
 * The stateless public facade over the pure pipeline: compose, render, validate, hash.
 *
 * It owns no catalogue and no persistence. Callers hand in what the pipeline needs as
 * plain arrays:
 *
 * - layout:   `{project_data, html, css}`, as the editor exports it;
 * - fields:   the bindings, each `{key, label, type, mandatory?, legal?, scope?,
 *             default_format?, columns?}`;
 * - geometry: the `pdf` block (`paper_format`, `margins`, `max_pages`,
 *             `visibility_limits`, `render_time_limit_seconds`, `line_row_height_mm`,
 *             `body_reserve_mm`) plus an optional `zones` entry
 *             (`header_height`, `footer_height`).
 *
 * The internal pipeline still reads those through a `TemplateType`; one is built here,
 * from the arguments, purely as an internal carrier and never exposed.
 */
final class Documents
{
    public function __construct(
        private readonly DocumentComposer $composer,
        private readonly PdfRenderer $renderer,
        private readonly LayoutValidator $layoutValidator,
        private readonly RenderValidator $renderValidator,
    ) {}

    /**
     * Compose the layout with the data and render it to PDF bytes.
     *
     * @param  array{project_data?: array<string, mixed>, html: string, css?: string}  $layout
     * @param  array<string, mixed>  $data  the values to print
     * @param  array<string, mixed>  $geometry
     * @param  string  $css  overrides the layout's own stylesheet when not empty
     * @param  list<array<string, mixed>>  $fields  needed to bind the code slots (qr, barcode)
     */
    public function render(array $layout, array $data, array $geometry = [], string $css = '', array $fields = []): string
    {
        return $this->renderForActivation($layout, $data, $geometry, $css, $fields)['pdf'];
    }

    /**
     * Render like `render()`, but also return the PDF hash and the render duration, which
     * is what activation records and what `validateRender()` takes as `$seconds`.
     *
     * @param  array{project_data?: array<string, mixed>, html: string, css?: string}  $layout
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $geometry
     * @param  list<array<string, mixed>>  $fields
     * @return array{pdf: string, hash: string, seconds: float}
     */
    public function renderForActivation(array $layout, array $data, array $geometry = [], string $css = '', array $fields = []): array
    {
        $type  = $this->carrier($fields, $geometry);
        $start = microtime(true);
        $html  = $this->composer->compose($layout['html'], $css !== '' ? $css : (string) ($layout['css'] ?? ''), $type, $data);
        $pdf   = $this->renderer->render($html, $type)['pdf'];

        return ['pdf' => $pdf, 'hash' => PdfHash::of($pdf), 'seconds' => microtime(true) - $start];
    }

    /**
     * A batch of labels as one PDF, one page per dataset, in the order given (CGE-98).
     *
     * One engine call for the whole batch. The batch is all or nothing: every dataset's
     * barcode is checked first (`labelProblems()`), and when any fails nothing is
     * rendered — a roll with a label missing misaligns every label after it. A label
     * whose content overflows is cut at its edges, never spilled onto a new page, so
     * the page count is always the number of datasets.
     *
     * @param  array{project_data?: array<string, mixed>, html: string, css?: string}  $layout
     * @param  list<array<string, mixed>>  $datasets  one per label, repeats allowed
     * @param  array<string, mixed>  $geometry  a label: a sheet with `width_mm` and `height_mm`
     * @param  string  $css  overrides the layout's own stylesheet when not empty
     * @param  list<array<string, mixed>>  $fields  needed to bind the code slots (qr, barcode)
     *
     * @throws InvalidArgumentException when the geometry is not a label, or there is no dataset
     * @throws LabelBatchFailed when any dataset's barcode cannot be printed
     */
    public function labels(array $layout, array $datasets, array $geometry = [], string $css = '', array $fields = []): string
    {
        $type = $this->labelCarrier($fields, $geometry);

        if ($datasets === []) {
            throw new InvalidArgumentException('A batch of labels needs at least one dataset.');
        }

        $css      = $css !== '' ? $css : (string) ($layout['css'] ?? '');
        $failures = $this->barcodeFailures($layout['html'], $css, array_values($datasets), $fields);

        if ($failures !== []) {
            throw new LabelBatchFailed($failures);
        }

        $html = $this->composer->composeMany($layout['html'], $css, $type, array_values($datasets));

        return $this->renderer->render($html, $type)['pdf'];
    }

    /**
     * The batch pre-check alone, without rendering: each barcode slot's value, per
     * dataset, against Code 128 (printable ASCII) and against the slot's width at whole
     * printer dots (`Code128Fit`). The width is the slot's inline `width:…mm`, else the
     * version CSS `#id{width:…mm}` the editor writes.
     *
     * @param  array{project_data?: array<string, mixed>, html: string, css?: string}  $layout
     * @param  list<array<string, mixed>>  $datasets
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $geometry  a label
     * @return list<array{index: int, content: string, reason: 'does_not_fit'|'unencodable'}>  `index` is the dataset's position
     *
     * @throws InvalidArgumentException when the geometry is not a label
     */
    public function labelProblems(array $layout, array $datasets, array $fields, array $geometry): array
    {
        $this->labelCarrier($fields, $geometry);

        return $this->barcodeFailures($layout['html'], (string) ($layout['css'] ?? ''), array_values($datasets), $fields);
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $geometry
     *
     * @throws InvalidArgumentException unless the geometry is a label
     */
    private function labelCarrier(array $fields, array $geometry): TemplateType
    {
        $type  = $this->carrier($fields, $geometry);
        $paper = PageGeometry::fromType($type->pdf);

        if ($paper->medium !== 'sheet' || $paper->paperFormat !== null || $paper->widthMm <= 0 || $paper->heightMm <= 0) {
            throw new InvalidArgumentException('Only a label (a sheet sized in millimetres) prints as a batch of labels.');
        }

        return $type;
    }

    /**
     * @param  list<array<string, mixed>>  $datasets
     * @param  list<array<string, mixed>>  $fields
     * @return list<array{index: int, content: string, reason: 'does_not_fit'|'unencodable'}>
     */
    private function barcodeFailures(string $html, string $css, array $datasets, array $fields): array
    {
        $types = [];
        foreach ($fields as $field) {
            $types[(string) ($field['key'] ?? '')] = $field['type'] ?? null;
        }

        $slots = array_filter(
            $this->codeSlots($html, $css),
            fn (array $slot): bool => ($types[$slot['binding']] ?? null) === 'barcode',
        );

        $failures = [];

        foreach ($datasets as $index => $dataset) {
            foreach ($slots as $slot) {
                $content = (string) ($dataset[$slot['binding']] ?? '');

                try {
                    Code128Fit::assertEncodable($content);
                } catch (BarcodeFailure) {
                    $failures[] = ['index' => $index, 'content' => $content, 'reason' => 'unencodable'];

                    continue;
                }

                try {
                    Code128Fit::moduleDots($content, $slot['width']);
                } catch (BarcodeFailure) {
                    $failures[] = ['index' => $index, 'content' => $content, 'reason' => 'does_not_fit'];
                }
            }
        }

        return $failures;
    }

    /**
     * The layout's dynamic code slots, with their binding and width in millimetres. A
     * slot with no width anywhere gets the 30 mm the image generator assumes.
     *
     * @return list<array{binding: string, width: float}>
     */
    private function codeSlots(string $html, string $css): array
    {
        if (! str_contains($html, 'data-tpl-type="code-slot"')) {
            return [];
        }

        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><body>'.$html.'</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $widths = $this->cssWidthsById($css);
        $slots  = [];

        foreach ((new \DOMXPath($document))->query('//*[@data-tpl-type="code-slot"]') ?: [] as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $attributes = [];
            foreach ($node->attributes as $attribute) {
                $attributes[$attribute->name] = $attribute->value;
            }

            // A fixed-text code is a QR composed from its own attributes, never a barcode.
            if (CodePayload::fromAttributes($attributes) !== null) {
                continue;
            }

            $width = self::widthMm($node->getAttribute('style')) ?? $widths[$node->getAttribute('id')] ?? 30.0;

            $slots[] = ['binding' => $node->getAttribute('data-tpl-binding'), 'width' => $width];
        }

        return $slots;
    }

    /**
     * Each `#id` rule's width in millimetres; a later rule wins, as in the cascade.
     *
     * @return array<string, float>
     */
    private function cssWidthsById(string $css): array
    {
        $css    = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        $widths = [];

        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $blocks, PREG_SET_ORDER);

        foreach ($blocks as $block) {
            $width = self::widthMm($block[2]);

            if ($width === null) {
                continue;
            }

            foreach (explode(',', $block[1]) as $selector) {
                if (preg_match('/^#([\w-]+)$/', trim($selector), $m) === 1) {
                    $widths[$m[1]] = $width;
                }
            }
        }

        return $widths;
    }

    /** `width:…mm` in a declaration list (not `max-width` nor `min-width`), or null. */
    private static function widthMm(string $declarations): ?float
    {
        return preg_match('/(?<![-\w])width\s*:\s*([\d.]+)mm/i', $declarations, $m) === 1 ? (float) $m[1] : null;
    }

    /**
     * A roll as the thermal printer prints it (CGE-97): the ESC/POS bytes, their preview
     * in HTML and the receipt's problems, all from one `Receipt`.
     *
     * In order: the receipt vocabulary (V-21) over the saved layout — a roll outside it is
     * returned as is, never translated; then the document is composed exactly as
     * `renderForActivation()` composes it for the PDF, laid on the character grid, and
     * checked (E-01 a mandatory value missing, E-02 the QR payload, E-03/E-04 the
     * builder's). With any problem there are no bytes (`''`): what reaches paper must have
     * passed. The preview is still made, so the user sees what failed.
     *
     * @param  array{project_data?: array<string, mixed>, html: string, css?: string}  $layout
     * @param  array<string, mixed>  $data  the values to print
     * @param  array<string, mixed>  $geometry  a roll (`medium: roll`)
     * @param  string  $css  overrides the layout's own stylesheet when not empty
     * @param  list<array<string, mixed>>  $fields  the bindings: what is mandatory and legal
     * @return array{bytes: string, preview: string, problems: list<array{code: string, component_id: ?string, message: string, key: string, subject: string}>}
     *
     * @throws InvalidArgumentException when the geometry is not a roll
     */
    public function escPos(array $layout, array $data, array $geometry = [], string $css = '', array $fields = []): array
    {
        $type  = $this->carrier($fields, $geometry);
        $paper = PageGeometry::fromType($type->pdf);

        if ($paper->medium !== 'roll' || $paper->columns === null) {
            throw new InvalidArgumentException('Only a roll prints as an ESC/POS receipt.');
        }

        $css = $css !== '' ? $css : (string) ($layout['css'] ?? '');

        $vocabulary = RollVocabulary::problems($layout['html'], $css, $paper->columns);

        if ($vocabulary !== []) {
            return ['bytes' => '', 'preview' => '', 'problems' => $vocabulary];
        }

        $html     = $this->composer->compose($layout['html'], $css, $type, $data);
        $receipt  = (new ReceiptBuilder)->build($html, $data, $paper, $fields);
        $problems = app(EscPosValidator::class)->validate(
            $receipt,
            $fields,
            $data,
            $this->placedFormats((array) ($layout['project_data'] ?? []), $layout['html']),
        );

        return [
            'bytes'    => $problems === [] ? app(EscPosEncoder::class)->encode($receipt, $paper) : '',
            'preview'  => app(ReceiptPreview::class)->html($receipt),
            'problems' => $problems,
        ];
    }

    /**
     * The boxes of the layout's code slots, the last argument of `validateRender()`.
     *
     * @param  array{project_data: array<string, mixed>, html?: string, css?: string}  $layout
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $geometry
     * @return array<string, array{left: float, top: float, width: float, height: float, attributes: array<string, string>}>
     */
    public function codeSlotBoxes(array $layout, array $fields, array $geometry = []): array
    {
        return $this->layoutValidator->codeSlotBoxes($layout['project_data'], $this->carrier($fields, $geometry));
    }

    /**
     * Layer 1: static validation of the layout against the fields and geometry.
     *
     * @param  array{project_data: array<string, mixed>, html?: string, css?: string}  $layout
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $geometry
     * @return array<int, array{code: string, component_id: string, message: string}>
     */
    public function validateLayout(array $layout, array $fields, array $geometry = []): array
    {
        return $this->layoutValidator->validate(
            $layout['project_data'],
            $this->carrier($fields, $geometry),
            (string) ($layout['html'] ?? ''),
            (string) ($layout['css'] ?? ''),
        );
    }

    /**
     * The layout's warnings: fields not required by law that are covered by a block on top
     * of them (V-18), and blocks with content overlapping each other (V-20, CGE-90). They
     * do not refuse a save; the host asks the user to confirm.
     *
     * @param  array{project_data: array<string, mixed>, html?: string, css?: string}  $layout
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $geometry
     * @param  array<string, mixed>  $fixture  the type's sample values, by key: V-20 measures a data field's text by them, and without one takes its whole box
     * @return array<int, array{code: string, component_id: string, message: string}>
     */
    public function layoutWarnings(array $layout, array $fields, array $geometry = [], array $fixture = []): array
    {
        return $this->occlusionsCoded(['V-18', 'V-20'], $layout, $fields, $geometry, $fixture);
    }

    /**
     * The covered legal fields and codes (V-15), alone: what activation must refuse without
     * re-running every other layer-1 rule. The render cannot see occlusion.
     *
     * @param  array{project_data: array<string, mixed>, html?: string, css?: string}  $layout
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $geometry
     * @return array<int, array{code: string, component_id: string, message: string}>
     */
    public function legalOcclusions(array $layout, array $fields, array $geometry = []): array
    {
        return $this->occlusionsCoded(['V-15'], $layout, $fields, $geometry);
    }

    /**
     * Layer 2: validation of a rendered PDF.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $data  the values the PDF was rendered with
     * @param  array<string, mixed>  $geometry
     * @param  float  $seconds  how long the render took
     * @param  array<string, array<string, mixed>>  $codeSlotBoxes
     * @param  array{project_data?: array<string, mixed>}  $layout  the rendered layout, so R-02 looks for each value in the format its field was placed with (CGE-49); without it, the binding's default format
     * @return array<int, array{code: string, page: int|null, message: string}>
     */
    public function validateRender(string $pdf, array $fields, array $data, array $geometry, float $seconds, array $codeSlotBoxes = [], array $layout = []): array
    {
        return $this->renderValidator->validate(
            $pdf,
            $this->carrier($fields, $geometry),
            $data,
            $seconds,
            $codeSlotBoxes,
            $this->placedFormats((array) ($layout['project_data'] ?? []), (string) ($layout['html'] ?? '')),
        );
    }

    /**
     * Every placed data field's format, by binding. A format that does not parse is left
     * out: V-01 refuses it before a render is ever validated.
     *
     * A version that was never opened in the editor (a seeded one) has no project data,
     * only its HTML — and that HTML carries the same `data-tpl-format*` attributes. Read
     * there, a date placed as "2026/12/31" is looked for as such, not in the binding's
     * default format, which would report it missing (R-02) and refuse the activation.
     *
     * @param  array<string, mixed>  $projectData
     * @return array<string, list<FormatSpec|null>>
     */
    private function placedFormats(array $projectData, string $html = ''): array
    {
        if ($projectData === []) {
            return $this->placedFormatsInHtml($html);
        }

        $placed = [];

        $formulas = [];

        foreach (LayoutDocument::fromProjectData($projectData)->dataFields() as $field) {
            $key        = (string) ($field['attributes']['data-tpl-binding'] ?? '');
            $formulas[] = $field['attributes']['data-tpl-formula'] ?? null;

            try {
                $placed[$key][] = FormatSpec::fromAttributes($field['attributes']);
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return $this->withFormulaReferences($placed, $formulas);
    }

    /**
     * `placedFormats` for a layout known only by its HTML.
     *
     * @return array<string, list<FormatSpec|null>>
     */
    private function placedFormatsInHtml(string $html): array
    {
        if (! str_contains($html, 'data-tpl-type="data-field"')) {
            return [];
        }

        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><body>'.$html.'</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $placed   = [];
        $formulas = [];

        foreach ((new \DOMXPath($document))->query('//*[@data-tpl-type="data-field"]') ?: [] as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $attributes = [];
            foreach ($node->attributes as $attribute) {
                $attributes[$attribute->name] = $attribute->value;
            }

            $formulas[] = $attributes['data-tpl-formula'] ?? null;

            try {
                $placed[(string) ($attributes['data-tpl-binding'] ?? '')][] = FormatSpec::fromAttributes($attributes);
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return $this->withFormulaReferences($placed, $formulas);
    }

    /**
     * A field named only inside a data field's formula is placed with no text of its own
     * to look for (CGE-69): the formula may wrap or reformat it, so R-02 is given an empty
     * list for it. A field also placed by its own block keeps that block's formats.
     *
     * @param  array<string, list<FormatSpec|null>>  $placed
     * @param  list<mixed>  $formulas  each data field's `data-tpl-formula`, if any
     * @return array<string, list<FormatSpec|null>>
     */
    private function withFormulaReferences(array $placed, array $formulas): array
    {
        foreach ($formulas as $source) {
            if (! is_string($source) || $source === '') {
                continue;
            }

            foreach (Formula::references($source) as $key) {
                $placed[$key] ??= [];
            }
        }

        return $placed;
    }

    /**
     * The live preview of formulas for the editor: each one checked as V-19 would at save
     * and, when it passes, evaluated against the dataset the canvas shows.
     *
     * A formula with a `collection` is a column of that collection: it is checked as a
     * row formula and evaluated on the FIRST row of the dataset's collection, the one row
     * the canvas draws; with no rows it is empty. One that fails the check comes back as
     * `value` '' and `error` the translated V-19 message, the one the save would report.
     * Never throws: that is the contract `Formula::evaluate` keeps at render.
     *
     * @param  list<array{source?: mixed, collection?: mixed}>  $items
     * @param  array<string, mixed>  $dataset
     * @return list<array{value: string|int|float, error: ?string}>
     */
    public function previewFormulas(array $items, array $dataset, TemplateType $type): array
    {
        $sources = array_map(
            fn (array $item): string => is_string($item['source'] ?? null) ? Formula::normalize($item['source']) : '',
            $items,
        );

        [$knownKeys, $collections] = $this->layoutValidator->formulaCatalogue($sources, $type);

        $label   = __('templates::editor.formula.label');
        $results = [];

        foreach ($items as $index => $item) {
            $source     = $sources[$index];
            $collection = is_string($item['collection'] ?? null) && $item['collection'] !== '' ? $item['collection'] : null;

            if ($source === '') {
                $results[] = ['value' => '', 'error' => __('templates::editor.validation.formula_without_source', ['formula' => $label])];

                continue;
            }

            $error = Formula::check($source, $knownKeys, $collections, $collection);

            if ($error !== null) {
                $results[] = ['value' => '', 'error' => $this->layoutValidator->formulaMessage($error, $label)];

                continue;
            }

            $row = $collection === null ? null : ((array) ($dataset[$collection] ?? []))[0] ?? null;

            $results[] = $collection !== null && ! is_array($row)
                ? ['value' => '', 'error' => null]
                : ['value' => Formula::evaluate($source, $dataset, $row, $collection), 'error' => null];
        }

        return $results;
    }
    /**
     * The reproducibility anchor over the inputs: same layout and geometry, same hash.
     *
     * @param  array<string, mixed>  $projectData
     * @param  array<string, mixed>|null  $geometry
     */
    public function inputHash(array $projectData, ?array $geometry = null): string
    {
        return CanonicalHash::of($projectData, $geometry);
    }

    /**
     * @param  array{project_data: array<string, mixed>, html?: string, css?: string}  $layout
     * @param  list<string>  $codes
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $geometry
     * @param  array<string, mixed>  $fixture
     * @return array<int, array{code: string, component_id: string, message: string}>
     */
    private function occlusionsCoded(array $codes, array $layout, array $fields, array $geometry, array $fixture = []): array
    {
        return array_values(array_filter(
            $this->layoutValidator->occlusions($layout['project_data'], $this->carrier($fields, $geometry, $fixture)),
            fn (array $problem): bool => in_array($problem['code'], $codes, true),
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $geometry
     * @param  array<string, mixed>  $fixture
     */
    private function carrier(array $fields, array $geometry, array $fixture = []): TemplateType
    {
        $zones = (array) ($geometry['zones'] ?? []);
        unset($geometry['zones']);

        return TemplateType::fromConfig('document', [
            'output'         => 'pdf',
            'bindings'       => $fields,
            'pdf'            => $geometry,
            'initial_layout' => ['zones' => $zones],
            'fixture'        => $fixture,
        ]);
    }
}
