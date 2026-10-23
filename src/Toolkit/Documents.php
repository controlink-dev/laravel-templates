<?php

namespace Controlink\Templates\Toolkit;

use Controlink\Templates\Formulas\Formula;
use Controlink\Templates\Output\Pdf\DocumentComposer;
use Controlink\Templates\Output\Pdf\LayoutDocument;
use Controlink\Templates\Output\Pdf\LayoutValidator;
use Controlink\Templates\Output\Pdf\PdfHash;
use Controlink\Templates\Output\Pdf\PdfRenderer;
use Controlink\Templates\Output\Pdf\RenderValidator;
use Controlink\Templates\Rendering\FormatSpec;
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
     * of them (V-18). They do not refuse a save; the host asks the user to confirm.
     *
     * @param  array{project_data: array<string, mixed>, html?: string, css?: string}  $layout
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $geometry
     * @return array<int, array{code: string, component_id: string, message: string}>
     */
    public function layoutWarnings(array $layout, array $fields, array $geometry = []): array
    {
        return $this->occlusionsCoded('V-18', $layout, $fields, $geometry);
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
        return $this->occlusionsCoded('V-15', $layout, $fields, $geometry);
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

        foreach (LayoutDocument::fromProjectData($projectData)->dataFields() as $field) {
            $key = (string) ($field['attributes']['data-tpl-binding'] ?? '');

            try {
                $placed[$key][] = FormatSpec::fromAttributes($field['attributes']);
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return $placed;
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

        $placed = [];

        foreach ((new \DOMXPath($document))->query('//*[@data-tpl-type="data-field"]') ?: [] as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $attributes = [];
            foreach ($node->attributes as $attribute) {
                $attributes[$attribute->name] = $attribute->value;
            }

            try {
                $placed[(string) ($attributes['data-tpl-binding'] ?? '')][] = FormatSpec::fromAttributes($attributes);
            } catch (InvalidArgumentException) {
                continue;
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
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $geometry
     * @return array<int, array{code: string, component_id: string, message: string}>
     */
    private function occlusionsCoded(string $code, array $layout, array $fields, array $geometry): array
    {
        return array_values(array_filter(
            $this->layoutValidator->occlusions($layout['project_data'], $this->carrier($fields, $geometry)),
            fn (array $problem): bool => $problem['code'] === $code,
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $geometry
     */
    private function carrier(array $fields, array $geometry): TemplateType
    {
        $zones = (array) ($geometry['zones'] ?? []);
        unset($geometry['zones']);

        return TemplateType::fromConfig('document', [
            'output'         => 'pdf',
            'bindings'       => $fields,
            'pdf'            => $geometry,
            'initial_layout' => ['zones' => $zones],
        ]);
    }
}
