<?php

namespace Controlink\Templates\Output\Pdf;

use Controlink\Templates\Contracts\BindingResolver;
use Controlink\Templates\Exceptions\UnsafeTemplateException;
use Controlink\Templates\Types\TemplateType;

/**
 * Layer 1 of the validation: static, over `project_data`, at save time (spec 8.1).
 *
 * It is a deterministic traversal of the document — no text analysis, because the user
 * writes no template syntax, and no rendering, because layer 1 is the fast filter and
 * the PDF is the proof (layer 2, `RenderValidator`).
 *
 * What that means in practice, and what it deliberately does NOT claim:
 *
 * - **Geometry is checked in the absolute zones only.** A block in the flowing body has
 *   no position until something lays it out, so V-04 and V-14 stay silent there and
 *   R-04 measures it on the rendered PDF instead. A block with no explicit width has
 *   no width either: the rules check the edges the document states and no others.
 * - **Truncation has no static rule at all.** It depends on font metrics and resolved
 *   box widths, and an estimate that is wrong in either direction is worse than no
 *   estimate — a false refusal blocks a layout that fits, a false pass ships a cut
 *   document. R-04 compares the extracted text with the fixture, which is exact.
 * - **Bindings whose block type does not exist yet are not required.** A skip covering
 *   a block type a host has not built yet stays here narrow and named, so removing it
 *   is part of adding that block.
 */
class LayoutValidator
{
    public function __construct(private readonly BindingResolver $resolver)
    {
    }

    /**
     * Which block places which kind of binding.
     *
     * Presence has to be asked of the right block: a collection can only be bound by
     * the line table and a code only by a slot, so looking for either among the data
     * fields would report every document as missing them.
     */
    private const PLACED_BY = [
        'text'       => 'data-field',
        'number'     => 'data-field',
        'date'       => 'data-field',
        'money'      => 'data-field',
        'image'      => 'data-field',
        'qr'         => 'code-slot',
        'barcode'    => 'code-slot',
        'collection' => 'line-table',
    ];

    /** Blocks that only make sense in some zones (V-06). */
    private const ZONE_RESTRICTIONS = [
        // The table paginates, and only the body paginates.
        'line-table' => ['body'],
        // A page number in the body would appear once, in the middle of the lines.
        'page-number' => ['header', 'footer'],
    ];

    /** Zones whose blocks have a position the document states outright. */
    private const ABSOLUTE_ZONES = ['header', 'footer'];

    /**
     * @param  array<string, mixed>  $projectData
     * @return array<int, array{code: string, component_id: string, message: string}>
     */
    public function validate(array $projectData, TemplateType $type, string $html = '', string $css = ''): array
    {
        $document = LayoutDocument::fromProjectData($projectData);
        $bindings = $this->bindingsByKey($type, $this->placedKeys($projectData));

        $problems = array_merge(
            $this->checkStructure($document, $bindings, $type, $html, $css),
            $this->checkLegalVisibility($document, $bindings, $type),
        );

        return array_values($problems);
    }

    /**
     * Where each code slot sits on the page, in millimetres from the top left.
     *
     * Layer 2 needs this because the QR decoder cannot find a small code inside a whole
     * page — it has to be handed a crop. The geometry is the layout's, so it is
     * resolved here, where the zone boxes already are, rather than guessed from the PDF.
     *
     * A dynamic slot is indexed by its binding, the only handle it has. A fixed-text
     * slot carries no binding at all, so it is indexed by its component id instead —
     * still unique, still enough for `RenderValidator` to address it. Every entry also
     * carries its own `attributes`, which is what tells R-05 whether the slot is fixed
     * or dynamic and, for a fixed one, what it is supposed to decode to.
     *
     * @param  array<string, mixed>  $projectData
     * @return array<string, array{left: float, top: float, width: float, height: float, attributes: array<string, string>}> keyed by binding, or by component id for a fixed-text slot
     */
    public function codeSlotBoxes(array $projectData, TemplateType $type): array
    {
        $document = LayoutDocument::fromProjectData($projectData);
        $boxes    = [];

        foreach ($document->blocksOfType('code-slot') as $slot) {
            $key   = $slot['attributes']['data-tpl-binding'] ?? '';
            $index = $key !== '' ? $key : $slot['id'];
            $box   = $document->boxOf($slot);

            if ($index === '' || $box['left'] === null || $box['top'] === null
                || $box['width'] === null || $box['height'] === null) {
                continue;
            }

            $zoneBox = $this->zoneBox($slot['zone'], $type);

            // A slot in the flowing body has no stated position, so there is no crop to
            // take and R-05 falls back to scanning the page — which is worse, and is why
            // V-06 will keep codes out of the body once a document type needs it.
            if ($zoneBox === null) {
                continue;
            }

            $boxes[$index] = [
                'left'       => $zoneBox['left'] + $box['left'],
                'top'        => $zoneBox['top'] + $box['top'],
                'width'      => $box['width'],
                'height'     => $box['height'],
                'attributes' => $slot['attributes'],
            ];
        }

        return $boxes;
    }

    /* -----------------------------------------------------------------------
     * Structure (V-01 to V-07)
     * -------------------------------------------------------------------- */

    /**
     * @param  array<string, array<string, mixed>>  $bindings
     * @return array<int, array{code: string, component_id: string, message: string}>
     */
    private function checkStructure(
        LayoutDocument $document,
        array $bindings,
        TemplateType $type,
        string $html,
        string $css,
    ): array {
        $problems = [];
        $placed   = [];

        foreach ($document->dataFields() as $field) {
            $key = $field['attributes']['data-tpl-binding'] ?? '';

            // V-01: a field bound to something the catalogue does not declare would
            // render as an empty expression, silently.
            if ($key === '' || ! isset($bindings[$key])) {
                $problems[] = $this->problem('V-01', $field, 'unknown_binding', ['binding' => $key]);

                continue;
            }

            $placed[$key] = true;

            $format = $field['attributes']['data-tpl-format'] ?? '';

            if ($format !== '' && ! in_array($format, (array) config('templates.formats'), true)) {
                $problems[] = $this->problem('V-01', $field, 'unknown_format', ['format' => $format]);
            }
        }

        // V-02: every mandatory binding has to be somewhere in the document, placed by
        // whichever block can bind it.
        foreach ($bindings as $key => $binding) {
            if (! ($binding['mandatory'] ?? false) || $this->isPlaced($document, $binding)) {
                continue;
            }

            // A legal field that is absent is reported once, by V-10, whose message
            // says it is a legal requirement. V-10 is a reinforcement of V-02, but
            // reporting both puts two lines in the panel for one defect and leaves the
            // user guessing whether there are two things to fix.
            if ($binding['legal'] ?? false) {
                continue;
            }

            $problems[] = $this->missing('V-02', 'missing_mandatory', $binding);
        }

        // V-03: exactly one line table per collection the document type requires. Two
        // tables over the same collection would print the lines twice, and the
        // compositor paginates against one table.
        foreach ($bindings as $key => $binding) {
            if ($binding['type'] !== 'collection' || ! ($binding['mandatory'] ?? false)) {
                continue;
            }

            $tables = array_values(array_filter(
                $document->blocksOfType('line-table'),
                fn (array $block): bool => ($block['attributes']['data-tpl-collection'] ?? '') === $key,
            ));

            if (count($tables) > 1) {
                $problems[] = $this->problem('V-03', $tables[1], 'duplicate_table', ['field' => $binding['label']]);
            }
        }

        // V-06: blocks that only work in certain zones.
        foreach (self::ZONE_RESTRICTIONS as $blockType => $allowed) {
            foreach ($document->blocksOfType($blockType) as $block) {
                if (in_array($block['zone'], $allowed, true)) {
                    continue;
                }

                $problems[] = $this->problem('V-06', $block, 'wrong_zone', [
                    'block' => __('templates::editor.blocks.'.str_replace('-', '_', $blockType)),
                    'zones' => implode(', ', array_map(
                        fn (string $zone): string => __('templates::editor.zones.'.$zone),
                        $allowed,
                    )),
                ]);
            }
        }

        // V-05: an image with no source prints nothing, and one over the limit is
        // refused rather than shrunk — an illegible logo on a certified document is
        // worse than a rejected file.
        foreach ($document->blocksOfType('image') as $image) {
            $source = $image['attributes']['src'] ?? '';

            if (! str_starts_with($source, 'data:')) {
                $problems[] = $this->problem('V-05', $image, 'image_source');

                continue;
            }

            if ($this->dataUriBytes($source) > (int) config('templates.image_max_bytes')) {
                $problems[] = $this->problem('V-05', $image, 'image_too_large');
            }
        }

        // V-04: a block that runs past its own zone prints clipped or in the wrong
        // band. Absolute zones only — see the class note.
        foreach ($this->positionedBlocks($document) as $block) {
            $zoneBox = $this->zoneBox($block['zone'], $type);
            $box     = $document->boxOf($block);

            if ($zoneBox === null) {
                continue;
            }

            if ($this->exceeds($box, ['left' => 0.0, 'top' => 0.0, 'width' => $zoneBox['width'], 'height' => $zoneBox['height']])) {
                $problems[] = $this->problem('V-04', $block, 'outside_zone', ['zone' => __('templates::editor.zones.'.$block['zone'])]);
            }
        }

        // V-07: the guards refuse rather than strip, so anything they reject means the
        // payload was tampered with on the way in.
        if ($html !== '') {
            try {
                app(DocumentComposer::class)->assertSafe($html, $css);
            } catch (UnsafeTemplateException) {
                $problems[] = [
                    'code'         => 'V-07',
                    'component_id' => '',
                    'message'      => __('templates::editor.validation.unsafe_markup'),
                ];
            }
        }

        return $problems;
    }

    /* -----------------------------------------------------------------------
     * Legal field visibility (V-10 to V-17)
     * -------------------------------------------------------------------- */

    /**
     * @param  array<string, array<string, mixed>>  $bindings
     * @return array<int, array{code: string, component_id: string, message: string}>
     */
    private function checkLegalVisibility(LayoutDocument $document, array $bindings, TemplateType $type): array
    {
        $limits   = $type->pdf['visibility_limits'] ?? [];
        $problems = [];

        $fieldsByBinding = [];

        foreach ($document->dataFields() as $field) {
            $fieldsByBinding[$field['attributes']['data-tpl-binding'] ?? ''][] = $field;
        }

        foreach ($bindings as $key => $binding) {
            if (! ($binding['legal'] ?? false)) {
                continue;
            }

            // A legal code is a slot, not a data field, and its rules are its size and
            // its place rather than its typography.
            if (in_array($binding['type'], ['qr', 'barcode'], true)) {
                $problems = array_merge(
                    $problems,
                    $this->checkLegalCode($document, $binding, $limits, $type),
                );

                continue;
            }

            if ((self::PLACED_BY[$binding['type']] ?? '') !== 'data-field') {
                continue;
            }

            // V-10: the same absence V-02 reports, with a message that says it is a
            // legal requirement rather than a configuration one.
            if (! isset($fieldsByBinding[$key])) {
                $problems[] = $this->missing('V-10', 'missing_legal', $binding);

                continue;
            }

            foreach ($fieldsByBinding[$key] as $field) {
                $problems = array_merge(
                    $problems,
                    $this->checkLegalField($document, $field, $binding, $limits, $type),
                );
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $binding
     * @param  array<string, mixed>  $limits
     * @return array<int, array{code: string, component_id: string, message: string}>
     */
    private function checkLegalField(
        LayoutDocument $document,
        array $field,
        array $binding,
        array $limits,
        TemplateType $type,
    ): array {
        $problems = [];
        $style    = $document->styleOf($field);
        $label    = ['field' => $binding['label']];

        // V-11
        $size    = LayoutDocument::points($style['font-size'] ?? null);
        $minimum = (float) ($limits['font_size_min_pt'] ?? 7);

        if ($size !== null && $size < $minimum) {
            $problems[] = $this->problem('V-11', $field, 'font_size', $label + ['minimum' => $minimum]);
        }

        // V-13, before V-12: a hidden field has no contrast worth measuring, and two
        // errors for one defect only make the panel harder to act on.
        $hidden = $this->hiddenBy($document, $field);

        if ($hidden !== null) {
            $problems[] = $this->problem('V-13', $field, $hidden, $label);

            return $problems;
        }

        $opacity = isset($style['opacity']) ? (float) $style['opacity'] : 1.0;

        if ($opacity < (float) ($limits['opacity_min'] ?? 1.0)) {
            $problems[] = $this->problem('V-13', $field, 'opacity', $label);

            return $problems;
        }

        // V-12: the text colour composited over the first opaque background found up
        // the ancestor chain. A rectangle drawn behind the text is not part of this —
        // that is occlusion (V-15), and counting it here would report one defect twice.
        $text       = Colour::parse($style['color'] ?? null);
        $background = $this->effectiveBackground($document, $field);

        if ($text !== null && $background !== null) {
            $contrast = $text->over($background)->contrastWith($background);
            $required = (float) ($limits['contrast_min'] ?? 4.5);

            if ($contrast < $required) {
                $problems[] = $this->problem('V-12', $field, 'contrast', $label + [
                    'contrast' => number_format($contrast, 1),
                    'minimum'  => number_format($required, 1),
                ]);
            }
        }

        // V-16: a value required on every page cannot come from the body, which flows
        // and does not repeat.
        if (($binding['scope'] ?? null) === 'every_page'
            && ! in_array($field['zone'], self::ABSOLUTE_ZONES, true)) {
            $problems[] = $this->problem('V-16', $field, 'scope', $label);
        }

        // V-14: inside the printable area. Absolute zones only — see the class note.
        $zoneBox = $this->zoneBox($field['zone'], $type);

        if ($zoneBox !== null) {
            $printable = $this->printableArea($type);
            $box       = $document->boxOf($field);

            $absolute = [
                'left'   => $box['left'] === null ? null : $zoneBox['left'] + $box['left'],
                'top'    => $box['top'] === null ? null : $zoneBox['top'] + $box['top'],
                'width'  => $box['width'],
                'height' => $box['height'],
            ];

            if ($this->exceeds($absolute, $printable)) {
                $problems[] = $this->problem('V-14', $field, 'outside_printable', $label);
            }

            $occluder = $this->occluderOf($document, $field, $zoneBox);

            if ($occluder !== null) {
                $problems[] = $this->problem('V-15', $field, 'occluded', $label);
            }
        }

        return $problems;
    }

    /**
     * Whether a binding is placed by the block that can bind it.
     *
     * @param  array<string, mixed>  $binding
     */
    private function isPlaced(LayoutDocument $document, array $binding): bool
    {
        $blockType = self::PLACED_BY[$binding['type']] ?? 'data-field';
        $attribute = $blockType === 'line-table' ? 'data-tpl-collection' : 'data-tpl-binding';

        foreach ($document->blocksOfType($blockType) as $block) {
            if (($block['attributes'][$attribute] ?? '') === $binding['key']) {
                return true;
            }
        }

        return false;
    }

    /**
     * A legal QR or barcode: present, big enough (V-17) and inside the printable area.
     *
     * The minimum side is the SLOT, not the printed code: a QR carries a mandatory
     * quiet zone of four modules on each side, so a 30mm slot prints about 24mm of
     * actual code. The limit is read as the slot because that is the only number the
     * layout states.
     *
     * @param  array<string, mixed>  $binding
     * @param  array<string, mixed>  $limits
     * @return array<int, array{code: string, component_id: string, message: string}>
     */
    private function checkLegalCode(
        LayoutDocument $document,
        array $binding,
        array $limits,
        TemplateType $type,
    ): array {
        $slots = array_values(array_filter(
            $document->blocksOfType('code-slot'),
            fn (array $block): bool => ($block['attributes']['data-tpl-binding'] ?? '') === $binding['key'],
        ));

        if ($slots === []) {
            return [$this->missing('V-10', 'missing_legal', $binding)];
        }

        $problems = [];
        $minimum  = (float) ($limits['qr_size_min_mm'] ?? 30);

        foreach ($slots as $slot) {
            $box = $document->boxOf($slot);

            // A slot with no stated size is not a slot that passed: the printed size
            // is what the legal minimum is about, and the layout has to say it.
            if ($box['width'] === null || $box['height'] === null) {
                $problems[] = $this->problem('V-17', $slot, 'code_size_unknown', ['field' => $binding['label']]);

                continue;
            }

            if (min($box['width'], $box['height']) < $minimum) {
                $problems[] = $this->problem('V-17', $slot, 'code_too_small', [
                    'field'   => $binding['label'],
                    'minimum' => $minimum,
                ]);
            }

            $zoneBox = $this->zoneBox($slot['zone'], $type);

            if ($zoneBox !== null) {
                $printable = $this->printableArea($type);
                $absolute  = [
                    'left'   => $box['left'] === null ? null : $zoneBox['left'] + $box['left'],
                    'top'    => $box['top'] === null ? null : $zoneBox['top'] + $box['top'],
                    'width'  => $box['width'],
                    'height' => $box['height'],
                ];

                if ($this->exceeds($absolute, $printable)) {
                    $problems[] = $this->problem('V-14', $slot, 'outside_printable', ['field' => $binding['label']]);
                }
            }
        }

        return $problems;
    }

    /* -----------------------------------------------------------------------
     * Geometry and style resolution
     * -------------------------------------------------------------------- */

    /**
     * @return array<int, array<string, mixed>>
     */
    private function positionedBlocks(LayoutDocument $document): array
    {
        return array_values(array_filter(
            $document->blocks(),
            fn (array $block): bool => in_array($block['zone'], self::ABSOLUTE_ZONES, true)
                && ($block['attributes']['data-tpl-type'] ?? '') !== '',
        ));
    }

    /**
     * The zone's band, in millimetres from the top left of the page.
     *
     * @return array{left: float, top: float, width: float, height: float}|null
     */
    private function zoneBox(?string $zone, TemplateType $type): ?array
    {
        if (! in_array($zone, self::ABSOLUTE_ZONES, true)) {
            return null;
        }

        $page      = $this->pageSize($type);
        $margins   = $type->pdf['margins'] ?? [];
        $zones     = $type->initialLayout['zones'] ?? [];
        $left      = (float) ($margins['left'] ?? 0);
        $width     = $page['width'] - $left - (float) ($margins['right'] ?? 0);
        $topMargin = (float) ($margins['top'] ?? 0);

        if ($zone === 'header') {
            return [
                'left'   => $left,
                'top'    => $topMargin,
                'width'  => $width,
                'height' => (float) ($zones['header_height'] ?? 35),
            ];
        }

        $height = (float) ($zones['footer_height'] ?? 20);

        return [
            'left'   => $left,
            'top'    => $page['height'] - (float) ($margins['bottom'] ?? 0) - $height,
            'width'  => $width,
            'height' => $height,
        ];
    }

    /**
     * @return array{left: float, top: float, width: float, height: float}
     */
    private function printableArea(TemplateType $type): array
    {
        $page    = $this->pageSize($type);
        $margins = $type->pdf['margins'] ?? [];

        return [
            'left'   => (float) ($margins['left'] ?? 0),
            'top'    => (float) ($margins['top'] ?? 0),
            'width'  => $page['width'] - (float) ($margins['left'] ?? 0) - (float) ($margins['right'] ?? 0),
            'height' => $page['height'] - (float) ($margins['top'] ?? 0) - (float) ($margins['bottom'] ?? 0),
        ];
    }

    /**
     * @return array{width: float, height: float}
     */
    private function pageSize(TemplateType $type): array
    {
        return match ($type->pdf['paper_format'] ?? 'A4') {
            'A5'    => ['width' => 148.0, 'height' => 210.0],
            default => ['width' => 210.0, 'height' => 297.0],
        };
    }

    /**
     * Whether a box runs outside the container.
     *
     * Only the edges the document actually states are compared: a missing width says
     * nothing about the right edge, and inventing one would produce refusals the user
     * cannot understand or fix. The tolerance absorbs the engine's pixel snapping —
     * without it, a block deliberately flush with the margin fails on rounding rather
     * than on placement.
     *
     * @param  array{left: float|null, top: float|null, width: float|null, height: float|null}  $box
     * @param  array{left: float, top: float, width: float, height: float}  $container
     */
    private function exceeds(array $box, array $container): bool
    {
        $tolerance = (float) config('templates.geometry_tolerance_mm', 0.5);

        if ($box['left'] !== null && $box['left'] < $container['left'] - $tolerance) {
            return true;
        }

        if ($box['top'] !== null && $box['top'] < $container['top'] - $tolerance) {
            return true;
        }

        if ($box['left'] !== null && $box['width'] !== null
            && $box['left'] + $box['width'] > $container['left'] + $container['width'] + $tolerance) {
            return true;
        }

        if ($box['top'] !== null && $box['height'] !== null
            && $box['top'] + $box['height'] > $container['top'] + $container['height'] + $tolerance) {
            return true;
        }

        return false;
    }

    /**
     * An opaque block, later in document order, whose box covers the field's.
     *
     * Both boxes have to be fully stated for this to mean anything, which in practice
     * limits it to a rectangle or an image over a sized field. The general case — a
     * logo dropped over an auto-width legal field — is caught on the rendered PDF by
     * R-04.
     *
     * @param  array<string, mixed>  $field
     * @param  array{left: float, top: float, width: float, height: float}  $zoneBox
     * @return array<string, mixed>|null
     */
    private function occluderOf(LayoutDocument $document, array $field, array $zoneBox): ?array
    {
        $target = $document->boxOf($field);

        if ($target['width'] === null || $target['height'] === null
            || $target['left'] === null || $target['top'] === null) {
            return null;
        }

        foreach ($document->blocks() as $candidate) {
            if ($candidate['index'] <= $field['index'] || $candidate['zone'] !== $field['zone']) {
                continue;
            }

            $style      = $document->styleOf($candidate);
            $background = Colour::parse($style['background-color'] ?? $style['background'] ?? null);

            if ($background === null || ! $background->isOpaque()) {
                continue;
            }

            $box = $document->boxOf($candidate);

            if ($box['left'] === null || $box['top'] === null
                || $box['width'] === null || $box['height'] === null) {
                continue;
            }

            $overlapsHorizontally = $box['left'] < $target['left'] + $target['width']
                && $target['left'] < $box['left'] + $box['width'];
            $overlapsVertically = $box['top'] < $target['top'] + $target['height']
                && $target['top'] < $box['top'] + $box['height'];

            if ($overlapsHorizontally && $overlapsVertically) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The first opaque background up the ancestor chain, ending at the page, which is
     * white because that is what paper is.
     *
     * @param  array<string, mixed>  $field
     */
    private function effectiveBackground(LayoutDocument $document, array $field): ?Colour
    {
        $chain = array_merge([$field], array_reverse($field['ancestors']));

        foreach ($chain as $node) {
            $style  = $document->styleOf($node);
            $colour = Colour::parse($style['background-color'] ?? $style['background'] ?? null);

            if ($colour !== null && $colour->isOpaque()) {
                return $colour;
            }
        }

        return Colour::parse('#ffffff');
    }

    /**
     * Which rule hides the field, if any — its own or an ancestor's.
     *
     * @param  array<string, mixed>  $field
     * @return string|null the translation key suffix
     */
    private function hiddenBy(LayoutDocument $document, array $field): ?string
    {
        foreach (array_merge([$field], $field['ancestors']) as $node) {
            $style = $document->styleOf($node);

            if (($style['display'] ?? '') === 'none') {
                return 'display_none';
            }

            if (($style['visibility'] ?? '') === 'hidden') {
                return 'visibility_hidden';
            }
        }

        return null;
    }

    /* -----------------------------------------------------------------------
     * Helpers
     * -------------------------------------------------------------------- */

    /**
     * The bindings this document needs, indexed by key.
     *
     * Two sources, and the order matters: the type's own declared list first, because
     * it carries `mandatory`, `legal`, `scope` and the hand-written fixture; then the
     * graph, for a key the type never declared. A key placed in the document that
     * exists in neither remains absent from this map, and V-01 reports it as an unknown
     * binding exactly as before.
     *
     * Only the keys the document actually places are resolved through the graph. Indexing
     * every field of every node would read the schema of every model on every validation,
     * for fields nobody used.
     *
     * @param  array<int, string>  $placed  the keys the document places
     * @return array<string, array<string, mixed>>
     */
    private function bindingsByKey(TemplateType $type, array $placed = []): array
    {
        $indexed = $type->bindingsByKey();

        foreach ($placed as $key) {
            if ($key === '' || isset($indexed[$key])) {
                continue;
            }

            $resolved = $this->resolver->resolve($key, $type);

            if ($resolved !== null) {
                $indexed[$key] = $resolved;
            }
        }

        return $indexed;
    }

    /**
     * Every `data-tpl-binding` the layout places, whatever the block type.
     *
     * Walks `$projectData` and NOT the `LayoutDocument`: `validate()` builds the document
     * through `LayoutDocument::fromProjectData()`, so by then it is an object and
     * `array_walk_recursive` has nothing to walk. The raw project data is the array.
     *
     * @param  array<string, mixed>  $projectData
     * @return array<int, string>
     */
    private function placedKeys(array $projectData): array
    {
        $keys = [];

        array_walk_recursive($projectData, function (mixed $value, string|int $attribute) use (&$keys): void {
            if ($attribute === 'data-tpl-binding' && is_string($value) && $value !== '') {
                $keys[] = $value;
            }
        });

        return array_values(array_unique($keys));
    }

    /** Decoded byte length of a data URI payload. */
    private function dataUriBytes(string $source): int
    {
        $comma = strpos($source, ',');

        if ($comma === false) {
            return 0;
        }

        $payload = substr($source, $comma + 1);

        return str_contains(substr($source, 0, $comma), ';base64')
            ? (int) (strlen($payload) * 3 / 4)
            : strlen(urldecode($payload));
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $replacements
     * @return array{code: string, component_id: string, message: string}
     */
    private function problem(string $code, array $block, string $key, array $replacements = []): array
    {
        return [
            'code'         => $code,
            'component_id' => $block['id'],
            'message'      => __('templates::editor.validation.'.$key, $replacements),
        ];
    }

    /**
     * @param  array<string, mixed>  $binding
     * @return array{code: string, component_id: string, message: string}
     */
    private function missing(string $code, string $key, array $binding): array
    {
        return [
            'code'         => $code,
            'component_id' => '',
            'message'      => __('templates::editor.validation.'.$key, ['field' => $binding['label']]),
        ];
    }
}
