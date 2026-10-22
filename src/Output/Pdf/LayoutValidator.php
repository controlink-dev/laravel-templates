<?php

namespace Controlink\Templates\Output\Pdf;

use Controlink\Templates\Contracts\BindingResolver;
use Controlink\Templates\Exceptions\UnsafeTemplateException;
use Controlink\Templates\Formulas\Formula;
use Controlink\Templates\Formulas\FormulaException;
use Controlink\Templates\Formulas\FormulaSet;
use Controlink\Templates\Rendering\FormatSpec;
use Controlink\Templates\Support\PageGeometry;
use Controlink\Templates\Types\TemplateType;
use InvalidArgumentException;

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
 * - **Occlusion is the exception: it is checked in every zone of a sheet.** It compares
 *   two blocks of the same zone in that zone's own coordinates, so it needs no zone band
 *   (and holds on a roll), and a text block with no height is one line tall (blocks never
 *   wrap). It still needs a stated width.
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
        $formulas = $this->formulasOf($document);
        $bindings = $this->documentBindings($projectData, $formulas, $type);

        $problems = array_merge(
            $this->checkStructure($document, $bindings, $type, $html, $css),
            $this->checkFormulas($document, $formulas, $bindings),
            $this->checkFormulaExport($document, $formulas, $html),
            $this->checkLegalVisibility($document, $bindings, $type),
            // Only the blocking half: a covered field the law does not require is a
            // warning (V-18), which the host asks the user to confirm, never a refusal.
            array_filter(
                $this->checkOcclusion($document, $bindings, $type),
                fn (array $problem): bool => $problem['code'] === 'V-15',
            ),
        );

        return array_values($problems);
    }

    /**
     * Every field or code slot covered by a block placed on top of it (CGE-27).
     *
     * V-15 when the covered binding is legal — it blocks saving and activation — and V-18
     * otherwise, a warning: the field may not appear when printed, and that is the user's
     * call. Occlusion is a property of the layout the render is blind to (covered text is
     * still in the PDF's content stream), so it is decided here, from the stated boxes.
     *
     * @param  array<string, mixed>  $projectData
     * @return array<int, array{code: string, component_id: string, message: string}>
     */
    public function occlusions(array $projectData, TemplateType $type): array
    {
        $document = LayoutDocument::fromProjectData($projectData);
        $bindings = $this->documentBindings($projectData, $this->formulasOf($document), $type);

        return $this->checkOcclusion($document, $bindings, $type);
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
        // A roll is one continuous page whose height is measured only after the content
        // flows, so a code's absolute box from the layout's zones does not match where it
        // actually lands on the rendered page. Hand R-05 no box: it then scans the page in
        // bands to find the code (see RenderValidator::decodeCode), which is how a flowing
        // code is meant to be located.
        if (PageGeometry::fromType($type->pdf)->medium === 'roll') {
            return [];
        }

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
        $exports  = new ExportedMarkup($html);

        foreach ($document->dataFields() as $field) {
            $key = $field['attributes']['data-tpl-binding'] ?? '';

            // A formula key with no source prints empty; V-19 reports it, once, as that.
            if (FormulaSet::isFieldKey((string) $key) && $this->formulaSource($field['attributes']['data-tpl-formula'] ?? null) === '') {
                continue;
            }

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

                continue;
            }

            // CGE-49: the arguments come from closed lists, and the exported expression
            // (what Twig actually renders) has to be the one the attributes describe.
            try {
                $spec = FormatSpec::fromAttributes($field['attributes']);
            } catch (InvalidArgumentException) {
                $problems[] = $this->problem('V-01', $field, 'unknown_format', ['format' => $format]);

                continue;
            }

            // A block with no id yet (dropped, never styled) cannot be found in the export;
            // the filters still refuse any argument outside its list at render.
            $fieldId  = (string) ($field['attributes']['id'] ?? '');
            $exported = $fieldId === '' ? null : $exports->fieldTexts($fieldId);
            $expected = $spec?->expression($key) ?? '{{ '.$key.' }}';

            // Every element under the id must print the expression, and there must be
            // one: a renamed or duplicated id is not a way around the comparison.
            if ($exported !== null && ($exported === [] || array_diff($exported, [$expected]) !== [])) {
                $problems[] = $this->problem('V-01', $field, 'format_mismatch', ['format' => $format]);
            }
        }

        // A line table: its columns' choices come from the closed lists, and every value it
        // prints is a canonical format expression with arguments from those lists. The
        // cells are not matched column by column: a starting layout may compose a cell
        // by hand (the roll's sub-line of price and VAT), which the editor never does.
        foreach ($document->blocksOfType('line-table') as $table) {
            $columns = json_decode((string) ($table['attributes']['data-tpl-columns'] ?? '[]'), true);

            try {
                // Unreadable columns hide whatever the table carries — its formulas among
                // them (CGE-37) — from every rule here, while the markup still prints them.
                if (! is_array($columns)) {
                    throw new InvalidArgumentException('The table\'s columns are not a list.');
                }

                foreach ($columns as $column) {
                    $this->assertKnownFormat(FormatSpec::fromColumn((array) $column));
                }

                // CGE-37: a column that carries a formula, or claims the formula prefix,
                // under a key of any other shape (`formula_3…`) is never computed, so the
                // row would print a value that does not exist. Refused like an unknown field.
                // With a `formulaKey` the result prints under it, so that is the key judged;
                // the column's own `key` then has to be itself or a column of the collection
                // (a catalogue column turned into a formula), never a name of nothing.
                $collection = (string) ($table['attributes']['data-tpl-collection'] ?? '');

                foreach ($columns as $column) {
                    $column = (array) $column;
                    $key    = (string) ($column['key'] ?? '');

                    if (! array_key_exists('formulaKey', $column)) {
                        if ((str_starts_with($key, 'formula_') || array_key_exists('formula', $column)) && ! FormulaSet::isColumnKey($key)) {
                            $problems[] = $this->problem('V-01', $table, 'unknown_binding', ['binding' => $key]);
                        }

                        continue;
                    }

                    $formulaKey = is_string($column['formulaKey']) ? $column['formulaKey'] : '';

                    if (! FormulaSet::isColumnKey($formulaKey)) {
                        $problems[] = $this->problem('V-01', $table, 'unknown_binding', ['binding' => $formulaKey]);
                    } elseif ($key !== $formulaKey && $collection !== '' && ! $this->isCollectionColumn($collection, $key, $bindings, $type)) {
                        // An unbound table has no catalogue; V-19 already refuses its formula.
                        $problems[] = $this->problem('V-01', $table, 'unknown_binding', ['binding' => $key]);
                    }
                }

                $tableId = (string) ($table['attributes']['id'] ?? '');

                if ($tableId !== '' && $exports->has($tableId) === false) {
                    throw new InvalidArgumentException('The table is missing from the export.');
                }

                foreach ($exports->tableExpressions($tableId) ?? [] as $printed) {
                    $this->assertKnownFormat(FormatSpec::fromExpression($printed));
                }
            } catch (InvalidArgumentException) {
                $problems[] = $this->problem('V-01', $table, 'format_mismatch', ['format' => '']);
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

        // V-03: at most one line table per collection. Two tables over the same
        // collection would print its lines twice, and the compositor paginates each
        // collection against one table. Tables over different collections are the
        // multi-table case and are fine; unbound tables (no collection) are skipped.
        $seen = [];
        foreach ($document->blocksOfType('line-table') as $table) {
            $key = (string) ($table['attributes']['data-tpl-collection'] ?? '');

            if ($key === '') {
                continue;
            }

            if (! isset($seen[$key])) {
                $seen[$key] = true;

                continue;
            }

            if ($seen[$key] === true) {
                $seen[$key] = false; // report once, on the second table

                $label = $bindings[$key]['label'] ?? $key;

                $problems[] = $this->problem('V-03', $table, 'duplicate_table', ['field' => $label]);
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

            if ($this->exceeds($box, ['left' => 0.0, 'top' => 0.0, 'width' => $zoneBox['width'], 'height' => $zoneBox['height']], PageGeometry::fromType($type->pdf)->medium !== 'roll')) {
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
     * Formulas (V-19, CGE-37)
     * -------------------------------------------------------------------- */

    /**
     * Maps `FormulaException::$reason` to the message key. A wrong argument count is a
     * syntax problem to the user; anything unexpected is too, never a crash or no error.
     */
    private const FORMULA_REASONS = [
        'syntax'            => 'formula_syntax',
        'arity'             => 'formula_syntax',
        'unknown_function'  => 'formula_unknown_function',
        'unknown_reference' => 'formula_unknown_reference',
        'scope'             => 'formula_scope',
    ];

    /**
     * Every formula the layout carries: data fields bound to `formula.<id>` and line-table
     * columns whose result prints under `formula_<id>` (their `formulaKey`, or their `key`
     * when they have none), each with its source (trimmed, '' when there is none).
     *
     * Only keys of the exported shape count, as in `FormulaSet` (what the compositor
     * evaluates): a `data-tpl-formula` on any other binding means nothing, and that binding
     * is judged by V-01 as it always was.
     *
     * @return list<array{kind: string, key: string, source: string, block: array<string, mixed>, collection: ?string, label: string}>
     */
    private function formulasOf(LayoutDocument $document): array
    {
        $formulas = [];
        $label    = __('templates::editor.formula.label');

        foreach ($document->dataFields() as $field) {
            $key = (string) ($field['attributes']['data-tpl-binding'] ?? '');

            if (FormulaSet::isFieldKey($key)) {
                $formulas[] = [
                    'kind'       => 'field',
                    'key'        => $key,
                    'source'     => $this->formulaSource($field['attributes']['data-tpl-formula'] ?? null),
                    'block'      => $field,
                    'collection' => null,
                    'label'      => $label,
                ];
            }
        }

        foreach ($document->blocksOfType('line-table') as $table) {
            $collection = (string) ($table['attributes']['data-tpl-collection'] ?? '');

            foreach ($this->tableColumns($table) as $column) {
                // Keyed as it prints, `formulaKey ?? key`, like the compositor reads it.
                $key = FormulaSet::columnFormulaKey($column);

                if (! is_string($key) || ! FormulaSet::isColumnKey($key)) {
                    continue;
                }

                $formulas[] = [
                    'kind'       => 'column',
                    'key'        => $key,
                    'source'     => $this->formulaSource($column['formula'] ?? null),
                    'block'      => $table,
                    'collection' => $collection === '' ? null : $collection,
                    // The table is the component, so the column is named by its header.
                    'label'      => is_string($column['label'] ?? null) && $column['label'] !== '' ? $column['label'] : $label,
                ];
            }
        }

        return $formulas;
    }

    /**
     * What a formula may reference in this type, resolved through the host's graph.
     *
     * The keys resolved are the ones the sources read, so a field the type never declared
     * is known exactly as if it were placed (`documentBindings`). It is the very scope V-19
     * checks against; the editor's live preview (`Documents::previewFormulas`) has no
     * layout, so a model-backed collection offers the columns the sources name.
     *
     * @param  list<string>  $sources
     * @return array{0: list<string>, 1: array<string, list<string>>}  singular keys, then collection => columns
     */
    public function formulaCatalogue(array $sources, TemplateType $type): array
    {
        $keys = [];

        foreach ($sources as $source) {
            array_push($keys, ...Formula::references($source));
        }

        return $this->formulaScope($this->bindingsByKey($type, array_values(array_unique($keys))));
    }

    /**
     * The V-19 message of a failed `Formula::check`, as the save reports it.
     *
     * @param  string  $formula  how the formula is named in the message
     */
    public function formulaMessage(FormulaException $error, string $formula): string
    {
        return __('templates::editor.validation.'.$this->formulaReasonKey($error), ['formula' => $formula]);
    }

    private function formulaReasonKey(FormulaException $error): string
    {
        return self::FORMULA_REASONS[$error->reason] ?? 'formula_syntax';
    }

    /**
     * Splits bindings into the singular keys and the collections with their columns.
     * `formula.*` is never a known key (no chaining).
     *
     * @param  array<string, array<string, mixed>>  $bindings
     * @return array{0: list<string>, 1: array<string, list<string>>}
     */
    private function formulaScope(array $bindings, ?LayoutDocument $document = null): array
    {
        $knownKeys   = [];
        $collections = [];

        foreach ($bindings as $key => $binding) {
            if (($binding['type'] ?? null) === 'collection') {
                $collections[$key] = $this->collectionColumns($document, $key, $binding, $bindings);
            } elseif (! str_starts_with((string) $key, 'formula.')) {
                $knownKeys[] = (string) $key;
            }
        }

        return [$knownKeys, $collections];
    }

    /**
     * V-19: every formula has a source, a key no other formula of its kind uses, and a
     * source the closed language accepts against this document's catalogue.
     *
     * - The duplicate is reported on the second and later occurrences: a copied formula
     *   that kept its key would print the first one's result (field keys and column keys,
     *   across every table, are two separate key spaces).
     * - Known keys are every singular binding — declared, or resolved by the host for a
     *   key a formula or a block references (`documentBindings`). `formula.*` never is.
     * - A row formula is checked as a row of its table's collection; an unbound table has
     *   no row, so its column references are judged like a single field's.
     *
     * @param  list<array{kind: string, key: string, source: string, block: array<string, mixed>, collection: ?string, label: string}>  $formulas
     * @param  array<string, array<string, mixed>>  $bindings
     * @return array<int, array{code: string, component_id: string, message: string}>
     */
    private function checkFormulas(LayoutDocument $document, array $formulas, array $bindings): array
    {
        if ($formulas === []) {
            return [];
        }

        [$knownKeys, $collections] = $this->formulaScope($bindings, $document);

        $problems = [];
        $seen     = ['field' => [], 'column' => []];

        foreach ($formulas as $formula) {
            $replacements = ['formula' => $formula['label']];

            if (isset($seen[$formula['kind']][$formula['key']])) {
                $problems[] = $this->problem('V-19', $formula['block'], 'formula_duplicate_key', $replacements);

                continue;
            }

            $seen[$formula['kind']][$formula['key']] = true;

            if ($formula['source'] === '') {
                $problems[] = $this->problem('V-19', $formula['block'], 'formula_without_source', $replacements);

                continue;
            }

            // A table bound to no collection has no rows, so the compositor never fills
            // its formula column: it would print empty whatever the formula says.
            if ($formula['kind'] === 'column' && $formula['collection'] === null) {
                $problems[] = $this->problem('V-19', $formula['block'], 'formula_scope', $replacements);

                continue;
            }

            $error = Formula::check($formula['source'], $knownKeys, $collections, $formula['collection']);

            if ($error !== null) {
                $problems[] = $this->problem('V-19', $formula['block'], $this->formulaReasonKey($error), $replacements);
            }
        }

        return $problems;
    }

    /**
     * V-01 `format_mismatch`: the formulas validated here are the ones that print.
     *
     * The rules above read the sources in `project_data`, but the compositor evaluates
     * the ones in the exported markup (`FormulaSet`). The editor writes both from the same
     * component, so they only differ when the payload was assembled by hand — and then a
     * formula that passed V-19 is not the one that would print. Compared as `FormulaSet`
     * reads them: fields by key, columns by collection and key (a column of an unbound
     * table is not evaluated, and V-19 already refuses it), the last occurrence of a key
     * winning, sources trimmed. A difference is reported on the block that holds it in
     * `project_data` (for a column only the markup has, its collection's table), or on no
     * block for a field only the markup has.
     *
     * Reported as the existing mismatch between what the block says and what it exports,
     * rather than a V-19 key: it is the same defect V-01 already names for formats. Markup
     * the formula reader refuses is V-07's (`assertSafe` runs the same reader).
     *
     * @param  list<array{kind: string, key: string, source: string, block: array<string, mixed>, collection: ?string, label: string}>  $formulas
     * @return array<int, array{code: string, component_id: string, message: string}>
     */
    private function checkFormulaExport(LayoutDocument $document, array $formulas, string $html): array
    {
        if ($html === '') {
            return [];
        }

        try {
            $exported = FormulaSet::fromHtml($html);
        } catch (UnsafeTemplateException) {
            return [];
        }

        $printed = [];

        foreach ($exported->fields() as $key => $source) {
            $printed['field:'.$key] = $this->formulaSource($source);
        }

        foreach ($exported->columns() as $column) {
            $printed['column:'.$column['collection'].':'.$column['key']] = $this->formulaSource($column['source']);
        }

        $validated = [];
        $blocks    = [];

        // A column only the markup has is still pinned to its table, found by collection.
        foreach ($exported->columns() as $column) {
            foreach ($document->blocksOfType('line-table') as $table) {
                if (($table['attributes']['data-tpl-collection'] ?? '') === $column['collection']) {
                    $blocks['column:'.$column['collection'].':'.$column['key']] ??= $table;
                }
            }
        }

        foreach ($formulas as $formula) {
            if ($formula['kind'] === 'column' && $formula['collection'] === null) {
                continue;
            }

            $id = $formula['kind'] === 'field'
                ? 'field:'.$formula['key']
                : 'column:'.$formula['collection'].':'.$formula['key'];

            $validated[$id] = $formula['source'];
            $blocks[$id] ??= $formula['block'];
        }

        $problems = [];

        foreach (array_keys($validated + $printed) as $id) {
            if (($validated[$id] ?? null) === ($printed[$id] ?? null)) {
                continue;
            }

            $problems[] = isset($blocks[$id])
                ? $this->problem('V-01', $blocks[$id], 'format_mismatch', ['format' => ''])
                : [
                    'code'         => 'V-01',
                    'component_id' => '',
                    'message'      => __('templates::editor.validation.format_mismatch', ['format' => '']),
                ];
        }

        return $problems;
    }

    /**
     * The columns a formula may read from a collection, never a formula column (no chaining).
     *
     * Declared columns when the type lists them. A model-backed collection lists none
     * (`columns: null`, the host's graph offers them), so there the columns are the ones
     * the host resolver knows as `<collection>.<column>` (`documentBindings` asked it for
     * every referenced key) plus the ones this document's own tables over it already show,
     * which the editor only offers from that same graph. Anything else is refused as an
     * unknown reference: the conservative side, since a wrong column prints empty.
     *
     * @param  array<string, mixed>  $binding
     * @param  array<string, array<string, mixed>>  $bindings
     * @return list<string>
     */
    private function collectionColumns(?LayoutDocument $document, string $key, array $binding, array $bindings): array
    {
        $columns = [];

        if (is_array($binding['columns'] ?? null)) {
            foreach ($binding['columns'] as $column) {
                $columns[] = (string) ($column['key'] ?? '');
            }
        } else {
            foreach ($bindings as $resolved => $descriptor) {
                if (str_starts_with((string) $resolved, $key.'.') && ($descriptor['type'] ?? null) !== 'collection') {
                    $columns[] = substr((string) $resolved, strlen($key) + 1);
                }
            }

            foreach ($document?->blocksOfType('line-table') ?? [] as $table) {
                if (($table['attributes']['data-tpl-collection'] ?? '') === $key) {
                    foreach ($this->tableColumns($table) as $column) {
                        $columns[] = is_string($column['key'] ?? null) ? $column['key'] : '';
                    }
                }
            }
        }

        return array_values(array_unique(array_filter(
            $columns,
            fn (string $column): bool => $column !== '' && ! str_starts_with($column, 'formula_'),
        )));
    }

    /**
     * Whether a key names a real column of the collection: one the type declares, or, for
     * a model-backed collection (`columns: null`), one the host resolver knows as
     * `<collection>.<column>`. Unlike `collectionColumns` this never counts the document's
     * own tables, so a column cannot vouch for itself. A formula column is never one.
     *
     * @param  array<string, array<string, mixed>>  $bindings
     */
    private function isCollectionColumn(string $collection, string $column, array $bindings, TemplateType $type): bool
    {
        $binding = $bindings[$collection] ?? null;

        if ($column === '' || str_starts_with($column, 'formula_') || ($binding['type'] ?? null) !== 'collection') {
            return false;
        }

        if (is_array($binding['columns'] ?? null)) {
            return in_array($column, array_map(fn (mixed $declared): string => (string) (((array) $declared)['key'] ?? ''), $binding['columns']), true);
        }

        $resolved = $bindings[$collection.'.'.$column] ?? $this->resolver->resolve($collection.'.'.$column, $type);

        return $resolved !== null && ($resolved['type'] ?? null) !== 'collection';
    }

    /**
     * A line table's declared columns, each an array (anything else in the list is dropped).
     *
     * @param  array<string, mixed>  $table
     * @return list<array<string, mixed>>
     */
    private function tableColumns(array $table): array
    {
        $columns = json_decode((string) ($table['attributes']['data-tpl-columns'] ?? '[]'), true);

        return array_values(array_filter(is_array($columns) ? $columns : [], 'is_array'));
    }

    /** A formula source as the validator judges it: '' when absent, blank or not text. */
    private function formulaSource(mixed $source): string
    {
        return is_string($source) ? Formula::normalize($source) : '';
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
        // and does not repeat. A roll is one continuous page: there are no other pages
        // to miss, so the requirement is trivially met wherever the field sits.
        if (($binding['scope'] ?? null) === 'every_page'
            && PageGeometry::fromType($type->pdf)->medium !== 'roll'
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

            if ($this->exceeds($absolute, $printable, PageGeometry::fromType($type->pdf)->medium !== 'roll')) {
                $problems[] = $this->problem('V-14', $field, 'outside_printable', $label);
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

                if ($this->exceeds($absolute, $printable, PageGeometry::fromType($type->pdf)->medium !== 'roll')) {
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
        // A roll has its width and no fixed height (INF): nothing can run past a bottom
        // that does not exist.
        return PageGeometry::fromType($type->pdf)->sheetSizeMm();
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
     * `$horizontalBounds` is false on a roll, whose content flows and keeps no fixed right
     * edge: a block's `left` is reflowed there, so an origin past the paper width is not a
     * placement fault and must not be refused (A4 positions are tolerated when a layout is
     * saved as a roll). A sheet positions absolutely, so there the right edge is real.
     *
     * @param  array{left: float|null, top: float|null, width: float|null, height: float|null}  $box
     * @param  array{left: float, top: float, width: float, height: float}  $container
     */
    private function exceeds(array $box, array $container, bool $horizontalBounds = true): bool
    {
        $tolerance = (float) config('templates.geometry_tolerance_mm', 0.5);

        if ($box['left'] !== null && $box['left'] < $container['left'] - $tolerance) {
            return true;
        }

        if ($box['top'] !== null && $box['top'] < $container['top'] - $tolerance) {
            return true;
        }

        // The origin alone can land past the far edge — a `left` beyond the right side, a
        // `top` below the bottom — and then the box is outside whatever its size, even with
        // none stated. Without this an auto-width field dragged off the sheet passes every
        // check (its right edge is unknown), and the layout is then rendered to a page metres
        // wide: measured, rasterising and cropping that page ran the activation past its time
        // limit (`RenderedPdf::crop`) rather than failing on the placement. On a roll there is
        // no fixed right edge, so the left origin is not bounded (`top` stays INF-safe anyway).
        if ($horizontalBounds && $box['left'] !== null
            && $box['left'] > $container['left'] + $container['width'] + $tolerance) {
            return true;
        }

        if ($box['top'] !== null && $box['top'] > $container['top'] + $container['height'] + $tolerance) {
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

    /* -----------------------------------------------------------------------
     * Occlusion (V-15 legal, V-18 warning)
     * -------------------------------------------------------------------- */

    /**
     * The placeholder the editor gives an image before a file is chosen (`TRANSPARENT_PIXEL`
     * in resources/js/editor/components.js). It paints nothing, so it hides nothing.
     */
    private const EMPTY_IMAGE = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    /** The font size a text block prints at when it states none (the browser's 16px). */
    private const DEFAULT_FONT_PT = 12.0;

    /**
     * Each covered data field and code slot, once, against the first block on top of it.
     *
     * Boxes are compared relative to their zone, so any zone qualifies — the body's blocks
     * are absolutely positioned too — and so does a roll: it only pads the page, and two
     * blocks of one zone overlap there exactly as on a sheet.
     *
     * @param  array<string, array<string, mixed>>  $bindings
     * @return array<int, array{code: string, component_id: string, message: string}>
     */
    private function checkOcclusion(LayoutDocument $document, array $bindings, TemplateType $type): array
    {
        $problems = [];

        foreach ($document->blocks() as $target) {
            $blockType = $target['attributes']['data-tpl-type'] ?? '';

            if (! in_array($blockType, ['data-field', 'code-slot'], true)) {
                continue;
            }

            $key = $target['attributes']['data-tpl-binding'] ?? '';

            // An unknown binding is V-01's to report; a code slot with no binding prints
            // fixed text and is required by nobody.
            if ($blockType === 'data-field' && ! isset($bindings[$key])) {
                continue;
            }

            $binding = $bindings[$key] ?? null;
            $box     = $this->occlusionBox($document, $target);

            if ($box === null || $this->firstOccluder($document, $target, $box) === null) {
                continue;
            }

            $label = ['field' => $binding['label'] ?? __('templates::editor.blocks.code_slot')];

            $problems[] = ($binding['legal'] ?? false)
                ? $this->problem('V-15', $target, 'occluded', $label)
                : $this->problem('V-18', $target, 'occluded_warning', $label);
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  array{left: float, top: float, width: float, height: float}  $box
     * @return array<string, mixed>|null
     */
    private function firstOccluder(LayoutDocument $document, array $target, array $box): ?array
    {
        $tolerance = (float) config('templates.geometry_tolerance_mm', 0.5);

        foreach ($document->blocks() as $candidate) {
            // Later in document order paints on top; there is no z-index to say otherwise.
            if ($candidate['index'] <= $target['index'] || $candidate['zone'] !== $target['zone']
                || in_array($target['id'], array_column($candidate['ancestors'], 'id'), true)
                || ! $this->isOpaqueOccluder($document, $candidate)) {
                continue;
            }

            $cover = $this->occlusionBox($document, $candidate);

            if ($cover === null) {
                continue;
            }

            // Overlapping by more than the engine's rounding on both axes: a separator line
            // grazing the field's line box is not covering it.
            $width  = min($box['left'] + $box['width'], $cover['left'] + $cover['width']) - max($box['left'], $cover['left']);
            $height = min($box['top'] + $box['height'], $cover['top'] + $cover['height']) - max($box['top'], $cover['top']);

            if ($width > $tolerance && $height > $tolerance) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Whether a block paints over whatever is under it: an opaque background, an image
     * with a file (unless translucent, like a translucent background), or a code — a QR
     * prints its white quiet zone across its whole slot. Text alone hides nothing.
     *
     * @param  array<string, mixed>  $block
     */
    private function isOpaqueOccluder(LayoutDocument $document, array $block): bool
    {
        $style      = $document->styleOf($block);
        $background = Colour::parse($style['background-color'] ?? $style['background'] ?? null);

        if ($background !== null && $background->isOpaque()) {
            return true;
        }

        $blockType = $block['attributes']['data-tpl-type'] ?? '';

        if ($blockType === 'code-slot') {
            return true;
        }

        if ($blockType !== 'image') {
            return false;
        }

        $source = $block['attributes']['src'] ?? '';

        return str_starts_with($source, 'data:')
            && $source !== self::EMPTY_IMAGE
            && (! isset($style['opacity']) || (float) $style['opacity'] >= 1.0);
    }

    /**
     * The block's box relative to its zone, or null when the layout does not state it.
     *
     * A text block with no height is one line of its own font: blocks do not wrap
     * (`white-space: nowrap` in print.css) unless "Várias linhas" is on, and even then
     * the first line is always there — the editor gives a wrapped block its height, and
     * without one the first line is the least it covers. That line is measured as its em box, the
     * font size, not a padded line box: the band under the descenders is where an
     * underline is drawn, and counting it would refuse a rule that only touches the text.
     * A missing width says nothing (auto-width fields are a known gap of the static rule),
     * and neither does anything else missing.
     *
     * @param  array<string, mixed>  $block
     * @return array{left: float, top: float, width: float, height: float}|null
     */
    private function occlusionBox(LayoutDocument $document, array $block): ?array
    {
        $box    = $document->boxOf($block);
        $height = $box['height'];

        if ($height === null
            && in_array($block['attributes']['data-tpl-type'] ?? '', ['data-field', 'static-text'], true)) {
            $points = LayoutDocument::points($document->styleOf($block)['font-size'] ?? null) ?? self::DEFAULT_FONT_PT;
            $height = $points * 25.4 / 72;
        }

        if ($box['left'] === null || $box['top'] === null || $box['width'] === null || $height === null) {
            return null;
        }

        return ['left' => $box['left'], 'top' => $box['top'], 'width' => $box['width'], 'height' => $height];
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
            // `formula.*` is the formulas' own namespace: no catalogue declares it and the
            // host is never asked about it (see `documentBindings`).
            if ($key === '' || isset($indexed[$key]) || str_starts_with($key, 'formula.')) {
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
     * The bindings this document needs, formulas included (CGE-37).
     *
     * The keys resolved through the graph are the placed ones plus every key a formula
     * references, so a referenced field the type never declared is known exactly as if it
     * were placed. Then each `formula.<id>` field with a source gets a synthetic, never
     * mandatory nor legal, text descriptor: it is a known binding for V-01 (any format,
     * still compared with the export) and it has a label for the occlusion warning. One
     * without a source gets none — V-19 reports it, and V-01 skips it.
     *
     * @param  array<string, mixed>  $projectData
     * @param  list<array{kind: string, key: string, source: string, block: array<string, mixed>, collection: ?string, label: string}>  $formulas
     * @return array<string, array<string, mixed>>
     */
    private function documentBindings(array $projectData, array $formulas, TemplateType $type): array
    {
        $keys = $this->placedKeys($projectData);

        foreach ($formulas as $formula) {
            array_push($keys, ...Formula::references($formula['source']));
        }

        $bindings = $this->bindingsByKey($type, array_values(array_unique($keys)));

        foreach ($formulas as $formula) {
            if ($formula['kind'] === 'field' && $formula['source'] !== '') {
                $bindings[$formula['key']] = [
                    'key'            => $formula['key'],
                    'label'          => __('templates::editor.formula.label'),
                    'type'           => 'text',
                    'mandatory'      => false,
                    'legal'          => false,
                    'scope'          => null,
                    'default_format' => null,
                ];
            }
        }

        return $bindings;
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

    /** @throws InvalidArgumentException when the format is not in `templates.formats` */
    private function assertKnownFormat(?FormatSpec $spec): void
    {
        if ($spec !== null && ! in_array($spec->name(), (array) config('templates.formats'), true)) {
            throw new InvalidArgumentException("Unknown format [{$spec->name()}].");
        }
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
