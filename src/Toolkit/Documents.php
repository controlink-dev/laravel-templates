<?php

namespace Controlink\Templates\Toolkit;

use Controlink\Templates\Output\Pdf\DocumentComposer;
use Controlink\Templates\Output\Pdf\LayoutValidator;
use Controlink\Templates\Output\Pdf\PdfHash;
use Controlink\Templates\Output\Pdf\PdfRenderer;
use Controlink\Templates\Output\Pdf\RenderValidator;
use Controlink\Templates\Types\TemplateType;
use Controlink\Templates\Versions\CanonicalHash;

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
     * @return array<int, array{code: string, page: int|null, message: string}>
     */
    public function validateRender(string $pdf, array $fields, array $data, array $geometry, float $seconds, array $codeSlotBoxes = []): array
    {
        return $this->renderValidator->validate($pdf, $this->carrier($fields, $geometry), $data, $seconds, $codeSlotBoxes);
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
