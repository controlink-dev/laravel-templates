<?php

namespace Controlink\Templates\Output\Pdf;

use Controlink\Templates\Codes\CodePayload;
use Controlink\Templates\Rendering\FormatSpec;
use Controlink\Templates\Rendering\TemplateRenderer;
use Controlink\Templates\Support\PageGeometry;
use Controlink\Templates\Types\TemplateType;
use Throwable;

/**
 * Layer 2 of the validation: over the rendered PDF, at activation (spec 8.2).
 *
 * This is the proof. Layer 1 reads the layout and can only know what the layout says;
 * this reads the document the engine actually produced, and asks whether the values a
 * customer will hold in their hand are there, whole, on the pages they must be on.
 *
 * The two layers fail differently on purpose. A static rule that cannot decide
 * something stays silent — that is why truncation has no static rule at all — and every
 * one of those silences is answered here, where the text has a measured box and a page
 * number.
 *
 * Failures name the page, because "the ATCUD is truncated" without a page is not
 * something a user can act on.
 */
class RenderValidator
{
    /** Stands for "the binding's `default_format`" where no placed field is known. */
    private const DEFAULT_FORMAT = 'binding-default';

    /**
     * Resolutions R-05 rasterises a code at, in order, retrying the next when one fails: a
     * module grid can alias against one dpi and read cleanly at another, so a single pass is
     * flaky. Both the cropped-slot read and the roll band scan walk this same list.
     */
    private const CODE_DPIS = [300, 250, 200];

    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly CodeReader $codes,
    ) {}

    /**
     * @param  array<string, mixed>  $dataset  the fixture the PDF was rendered with
     * @param  array<string, array{left: float, top: float, width: float, height: float, attributes: array<string, string>}>  $codeSlotBoxes  where each code sits on the page, keyed by binding for a dynamic slot or by component id for a fixed-text one, in millimetres
     * @param  array<string, list<FormatSpec|null>>  $placedFormats  the format of every placed data field, by binding (CGE-49); a binding absent from it is looked for in its default format
     * @return array<int, array{code: string, page: int|null, message: string, component_id?: string}> `component_id` is only present for a fixed-text code slot, which is the only failure with a component to jump to
     */
    public function validate(
        string $pdf,
        TemplateType $type,
        array $dataset,
        float $seconds,
        array $codeSlotBoxes = [],
        array $placedFormats = [],
    ): array {
        $problems = $this->checkBudget($type, $seconds);

        return RenderedPdf::open($pdf, function (RenderedPdf $rendered) use ($type, $dataset, $problems, $codeSlotBoxes, $placedFormats): array {
            $problems = array_merge($problems, $this->checkPageCount($rendered, $type));

            $sizeProblems = $this->checkPageSize($rendered, PageGeometry::fromType($type->pdf));
            $problems     = array_merge($problems, $sizeProblems);

            // CGE-25 Peça 1 — never hang: once the page is the wrong size (R-08), the
            // per-binding checks below would rasterise it to read its codes, and a
            // metres-wide or metres-long page is where the 30s render timeout happened.
            // The activation is already refused, so stop before measuring a document that
            // has already failed. R-08 leaves a roll's height free, so this gates a sheet
            // on both sides and a roll on its width.
            if ($sizeProblems !== []) {
                return array_values($problems);
            }

            foreach ($type->bindings as $binding) {
                $problems = array_merge(
                    $problems,
                    $this->checkBinding($rendered, $binding, $type, $dataset, $codeSlotBoxes, $placedFormats),
                );
            }

            foreach ($codeSlotBoxes as $id => $slot) {
                $problems = array_merge(
                    $problems,
                    $this->checkFixedCode($rendered, (string) $id, $slot),
                );
            }

            return array_values($problems);
        });
    }

    /**
     * R-07: the render has to fit its time budget.
     *
     * Checked before the PDF is even opened: a render that took too long already failed,
     * and inspecting it would only add to the time.
     *
     * @return array<int, array{code: string, page: int|null, message: string}>
     */
    private function checkBudget(TemplateType $type, float $seconds): array
    {
        $limit = (float) ($type->pdf['render_time_limit_seconds'] ?? config('templates.render_time_limit_seconds', 5));

        if ($seconds <= $limit) {
            return [];
        }

        return [$this->problem('R-07', null, 'too_slow', [
            'seconds' => number_format($seconds, 1),
            'limit'   => number_format($limit, 1),
        ])];
    }

    /**
     * R-08: the page the engine produced has to be the page the geometry asked for.
     *
     * A sheet is checked on both sides; a roll on its width only, since its height is
     * whatever the content measured.
     *
     * @return array<int, array{code: string, page: int|null, message: string}>
     */
    private function checkPageSize(RenderedPdf $rendered, PageGeometry $geometry): array
    {
        $tolerance = (float) config('templates.geometry_tolerance_mm', 0.5);
        $expected  = $this->expectedPageSize($geometry);
        $actual    = $rendered->pageSizeMm();

        $wrong = abs($actual['width'] - $expected['width']) > $tolerance
            || ($expected['height'] !== null && abs($actual['height'] - $expected['height']) > $tolerance);

        if (! $wrong) {
            return [];
        }

        return [$this->problem('R-08', null, 'page_size_mismatch', [
            'actual'   => round($actual['width']).' x '.round($actual['height']).' mm',
            'expected' => round($expected['width']).' x '.($expected['height'] === null ? '...' : round($expected['height'])).' mm',
        ])];
    }

    /**
     * The page the geometry asks for, in millimetres; a roll has no fixed height (null).
     *
     * @return array{width: float, height: float|null}
     */
    private function expectedPageSize(PageGeometry $geometry): array
    {
        $size = $geometry->sheetSizeMm();

        return ['width' => $size['width'], 'height' => is_infinite($size['height']) ? null : $size['height']];
    }

    /**
     * R-06: a document longer than its type allows is a runaway layout, not a long
     * invoice.
     *
     * @return array<int, array{code: string, page: int|null, message: string}>
     */
    private function checkPageCount(RenderedPdf $rendered, TemplateType $type): array
    {
        $limit = (int) ($type->pdf['max_pages'] ?? 0);
        $pages = $rendered->pageCount();

        if ($limit <= 0 || $pages <= $limit) {
            return [];
        }

        return [$this->problem('R-06', null, 'too_many_pages', ['pages' => $pages, 'limit' => $limit])];
    }

    /**
     * @param  array<string, mixed>  $binding
     * @param  array<string, mixed>  $dataset
     * @return array<int, array{code: string, page: int|null, message: string}>
     */
    private function checkBinding(
        RenderedPdf $rendered,
        array $binding,
        TemplateType $type,
        array $dataset,
        array $codeSlotBoxes,
        array $placedFormats = [],
    ): array {
        // A collection is not a value on the page; its columns are, and each of those is
        // checked by whether the lines rendered at all.
        if ($binding['type'] === 'collection') {
            return [];
        }

        if (in_array($binding['type'], ['qr', 'barcode'], true)) {
            return ($binding['legal'] ?? false)
                ? $this->checkCode($rendered, $binding, $dataset, $codeSlotBoxes[$binding['key']] ?? null)
                : [];
        }

        // CGE-49: the text to look for is what the placed fields print, each with its own
        // format and arguments. Any one of them on the page will do: the same binding
        // placed twice in two formats is one value shown twice. With no placement known
        // (a caller that did not pass the layout), the binding's default format.
        $specs = array_key_exists($binding['key'], $placedFormats)
            ? $placedFormats[$binding['key']]
            : [self::DEFAULT_FORMAT];

        $candidates = array_values(array_unique(array_filter(array_map(
            fn (FormatSpec|string|null $spec): string => $this->expectedText($binding, $dataset, $spec),
            $specs,
        ), fn (string $text): bool => $text !== '')));

        if ($candidates === []) {
            return [];
        }

        $problems = [];
        $pages    = $rendered->pageCount();

        // R-02: a mandatory value has to be somewhere in the document.
        $found    = [];
        $expected = $candidates[0];

        foreach ($candidates as $candidate) {
            for ($page = 1; $page <= $pages; $page++) {
                if ($this->pageContains($rendered, $page, $candidate)) {
                    $found[] = $page;
                }
            }

            if ($found !== []) {
                $expected = $candidate;

                break;
            }
        }

        if (($binding['mandatory'] ?? false) && $found === []) {
            $problems[] = $this->problem('R-02', null, 'value_missing', ['field' => $binding['label']]);

            // Nothing else can be said about a value that is not there.
            return $problems;
        }

        if (! ($binding['legal'] ?? false)) {
            return $problems;
        }

        // R-03: `every_page` means every page, and this is the rule the engine's own
        // pagination used to fail while leaving the text present elsewhere.
        if (($binding['scope'] ?? null) === 'every_page') {
            for ($page = 1; $page <= $pages; $page++) {
                if (! in_array($page, $found, true)) {
                    $problems[] = $this->problem('R-03', $page, 'missing_on_page', ['field' => $binding['label']]);
                }
            }
        }

        // R-04: on every page it appears, the value has to be whole and inside the
        // printable area. Wholeness is the truncation check that layer 1 deliberately
        // refuses to guess at.
        foreach ($found as $page) {
            $problems = array_merge(
                $problems,
                $this->checkBox($rendered, $page, $binding, $expected, $type),
            );
        }

        return $problems;
    }

    /**
     * R-04: the box of the extracted value, against the printable area.
     *
     * @param  array<string, mixed>  $binding
     * @return array<int, array{code: string, page: int|null, message: string}>
     */
    private function checkBox(
        RenderedPdf $rendered,
        int $page,
        array $binding,
        string $expected,
        TemplateType $type,
    ): array {
        $box = $this->locate($rendered, $page, $expected);

        if ($box === null) {
            return [];
        }

        $margins   = $type->pdf['margins'] ?? [];
        $tolerance = (float) config('templates.geometry_tolerance_mm', 0.5);

        $size = $this->expectedPageSize(PageGeometry::fromType($type->pdf));

        $left  = (float) ($margins['left'] ?? 15);
        $top   = (float) ($margins['top'] ?? 15);
        $right = $size['width'] - (float) ($margins['right'] ?? 15);

        // A roll is as tall as its content: there is no bottom to fall off.
        $bottom = $size['height'] === null ? null : $size['height'] - (float) ($margins['bottom'] ?? 15);

        $outside = $box['left'] < $left - $tolerance
            || $box['top'] < $top - $tolerance
            || $box['right'] > $right + $tolerance
            || ($bottom !== null && $box['bottom'] > $bottom + $tolerance);

        return $outside
            ? [$this->problem('R-04', $page, 'outside_printable', ['field' => $binding['label']])]
            : [];
    }

    /**
     * R-05: the code on the page has to be readable, and to say what it should.
     *
     * A QR that renders but does not decode is the failure mode that matters here: it
     * looks perfect on screen and the tax authority's app rejects it.
     *
     * @param  array<string, mixed>  $binding
     * @param  array<string, mixed>  $dataset
     * @return array<int, array{code: string, page: int|null, message: string}>
     */
    private function checkCode(RenderedPdf $rendered, array $binding, array $dataset, ?array $slot): array
    {
        $expected = (string) ($dataset[$binding['key']] ?? '');

        return $this->checkCodeContent(
            $rendered,
            $expected,
            (string) $binding['label'],
            (string) ($binding['scope'] ?? 'any_page'),
            $slot,
        );
    }

    /**
     * R-05 for a fixed-text code slot: it carries no binding, so there is no dataset
     * value and no catalogue label to check against — its expected content comes from
     * its own attributes, through the same `CodePayload` the generator drew the image
     * with. The message names the content type the user chose (`Phone`, `Wi-Fi`, …),
     * the same translated name the editor shows — never the component id, which is a
     * machine key for selecting a block on the canvas, not a word for a person. The id
     * is not lost: it travels separately as `component_id`, which is how the editor
     * still jumps to the offending block.
     *
     * A dynamic slot passed here (its attributes say `dynamic`, or say nothing at
     * all) yields no expected content and is silently skipped: it is already checked
     * above, through `$type->bindings`.
     *
     * @param  array{left: float, top: float, width: float, height: float, attributes: array<string, string>}  $slot
     * @return array<int, array{code: string, page: int|null, message: string, component_id: string}>
     */
    private function checkFixedCode(RenderedPdf $rendered, string $id, array $slot): array
    {
        $expected = CodePayload::fromAttributes($slot['attributes'] ?? []);

        if ($expected === null) {
            return [];
        }

        $type  = (string) ($slot['attributes']['data-tpl-code-type'] ?? '');
        $label = __('templates::editor.code_types.'.$type);

        $problems = $this->checkCodeContent($rendered, $expected, $label, 'any_page', $slot);

        return array_map(
            fn (array $problem): array => $problem + ['component_id' => $id],
            $problems,
        );
    }

    /**
     * The decoding check shared by a dynamic code (bound to a dataset field) and a
     * fixed-text one (composed from its own attributes): decode the crop on every page,
     * and compare it with what this particular code was supposed to say.
     *
     * @return array<int, array{code: string, page: int|null, message: string}>
     */
    private function checkCodeContent(RenderedPdf $rendered, string $expected, string $label, string $scope, ?array $slot): array
    {
        if ($expected === '') {
            return [];
        }

        if (! $this->codes->isAvailable()) {
            return [$this->problem('R-05', null, 'code_reader_missing', ['field' => $label])];
        }

        $pages = $rendered->pageCount();
        $seen  = false;

        $problems = [];

        for ($page = 1; $page <= $pages; $page++) {
            $decoded = $this->decodeCode($rendered, $page, $slot);

            if ($decoded === $expected) {
                $seen = true;

                continue;
            }

            // A code that decodes to something else is worse than one that does not
            // decode: it points the reader at the wrong document.
            if ($decoded !== null && $decoded !== $expected) {
                $problems[] = $this->problem('R-05', $page, 'code_mismatch', ['field' => $label]);

                continue;
            }

            if ($scope === 'every_page') {
                $problems[] = $this->problem('R-05', $page, 'code_unreadable', ['field' => $label]);
            }
        }

        if (! $seen && $scope !== 'every_page') {
            $problems[] = $this->problem('R-05', null, 'code_unreadable', ['field' => $label]);
        }

        return $problems;
    }

    /**
     * Read a code off one page, returning the first non-null decode (match or mismatch) for
     * the caller to judge.
     *
     * A code with a known slot box is cropped to it and retried across a few resolutions (a
     * module grid can alias against one dpi and read cleanly at the next). A code with NO
     * box — a slot in a flowing layout, e.g. a roll receipt — sits somewhere in a tall,
     * narrow page where a whole-page scan cannot find it; it is sought in full-width
     * horizontal bands from the bottom up (a receipt puts its code low), each band small
     * enough for the decoder to locate the finder patterns.
     *
     * @param  array{left: float, top: float, width: float, height: float}|null  $slot
     */
    private function decodeCode(RenderedPdf $rendered, int $page, ?array $slot): ?string
    {
        if ($slot !== null) {
            foreach (self::CODE_DPIS as $dpi) {
                $decoded = $this->codes->read($rendered->rasterOf($page, $dpi, $slot));

                if ($decoded !== null) {
                    return $decoded;
                }
            }

            return null;
        }

        ['width' => $width, 'height' => $height] = $rendered->pageSizeMm();
        $band = 55.0;
        $step = 35.0;

        for ($top = max(0.0, $height - $band); ; $top -= $step) {
            $box = ['left' => 0.0, 'top' => max(0.0, $top), 'width' => $width, 'height' => min($band, $height)];

            foreach (self::CODE_DPIS as $dpi) {
                $decoded = $this->codes->read($rendered->rasterOf($page, $dpi, $box));

                if ($decoded !== null) {
                    return $decoded;
                }
            }

            if ($top <= 0.0) {
                return null;
            }
        }
    }

    /**
     * The exact string the value takes in the document.
     *
     * Rendered through the same filters the template uses, because the fixture holds raw
     * values: comparing the page against `1229999.98` would fail on a document correctly
     * showing `1 229 999,98 €`.
     *
     * @param  array<string, mixed>  $binding
     * @param  array<string, mixed>  $dataset
     */
    private function expectedText(array $binding, array $dataset, FormatSpec|string|null $spec = self::DEFAULT_FORMAT): string
    {
        if ($spec === self::DEFAULT_FORMAT) {
            $format = $binding['default_format'] ?? '';

            $template = $format !== ''
                ? '{{ '.$binding['key'].'|'.$format.' }}'
                : '{{ '.$binding['key'].' }}';
        } else {
            $template = $spec instanceof FormatSpec
                ? $spec->expression($binding['key'])
                : '{{ '.$binding['key'].' }}';
        }

        try {
            return html_entity_decode(
                $this->renderer->render($template, $dataset),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8',
            );
        } catch (Throwable) {
            // A value the dataset does not carry is a dataset problem, not a layout
            // one, and it is not this rule's business to report it.
            return '';
        }
    }

    /** Whether a page's text carries the value, whitespace normalised. */
    private function pageContains(RenderedPdf $rendered, int $page, string $expected): bool
    {
        return str_contains(
            $this->normalise($rendered->textOn($page)),
            $this->normalise($expected),
        );
    }

    /**
     * The union box of the words that make up the value on a page.
     *
     * The extractor breaks a value into words, so the run of consecutive words whose
     * text matches is what has to be found — and its union box is the box of the value
     * as printed.
     *
     * @return array{left: float, top: float, right: float, bottom: float}|null
     */
    private function locate(RenderedPdf $rendered, int $page, string $expected): ?array
    {
        $words  = $rendered->wordsOn($page);
        $target = $this->normalise($expected);
        $count  = count($words);

        for ($start = 0; $start < $count; $start++) {
            $text = '';

            for ($end = $start; $end < $count; $end++) {
                $text   = trim($text.' '.$words[$end]['text']);
                $joined = $this->normalise($text);

                if ($joined === $target) {
                    $run = array_slice($words, $start, $end - $start + 1);

                    return [
                        'left'   => min(array_column($run, 'left')),
                        'top'    => min(array_column($run, 'top')),
                        'right'  => max(array_column($run, 'right')),
                        'bottom' => max(array_column($run, 'bottom')),
                    ];
                }

                if (! str_starts_with($target, $joined)) {
                    break;
                }
            }
        }

        return null;
    }

    /**
     * Whitespace-insensitive comparison.
     *
     * The extractor splits on spacing of its own, and currency formatting carries
     * non-breaking spaces the ICU put there — neither of which says anything about
     * whether the value is present and whole.
     */
    private function normalise(string $value): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $value));
    }

    /**
     * @param  array<string, mixed>  $replacements
     * @return array{code: string, page: int|null, message: string}
     */
    private function problem(string $code, ?int $page, string $key, array $replacements = []): array
    {
        return [
            'code'    => $code,
            'page'    => $page,
            'message' => __('templates::editor.render.'.$key, $replacements + ['page' => $page]),
        ];
    }
}
