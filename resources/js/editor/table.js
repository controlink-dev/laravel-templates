/* ============================================================================
 * Line table and code slot (spec 6.4, phase F4).
 *
 * Both follow the rule the data field established: the user configures, and only
 * `toHTML()` produces template text. The table's `{% for %}` and the slot's marker
 * attribute exist nowhere in `project_data`.
 * ========================================================================= */

import { TRANSPARENT_PIXEL, nodeOptionsOf, fieldsOfNode, nodeOfBinding } from './components.js';
import { icons } from './icons.js';
import { previewRows } from './preview-filter.js';

/**
 * @param {object} editor  GrapesJS instance
 * @param {object} config  bindings, nodes, codeTypes, formats, strings and limits
 */
export function registerTableAndSlot(editor, config) {
    registerColumnsTrait(editor, config);
    registerCodeContentTrait(editor, config);
    registerLineTable(editor, config);
    registerCodeSlot(editor, config);
}

/* ---------------------------------------------------------------------------
 * Line table
 * ------------------------------------------------------------------------ */
function registerLineTable(editor, config) {
    const collections = config.bindings
        .filter((binding) => binding.type === 'collection')
        .map((binding) => ({ id: binding.key, name: binding.label }));

    editor.DomComponents.addType('line-table', {
        isComponent: (el) => el?.dataset?.tplType === 'line-table',
        model: {
            defaults: {
                name: config.strings['block.line_table'],
                tagName: 'div',
                // A block holds no other block; see the data field (CGE-48).
                droppable: false,
                attributes: {
                    class: 'block block--lines',
                    'data-tpl-type': 'line-table',
                    'data-tpl-collection': collections[0]?.id ?? '',
                },
                // The table is furniture, not free content: the user configures its
                // columns and nothing else inside it is editable.
                editable: false,
                highlightable: true,
                traits: [
                    {
                        type: 'select',
                        name: 'data-tpl-collection',
                        label: config.strings['trait.collection'],
                        options: collections,
                    },
                    {
                        type: 'tpl-columns',
                        name: 'data-tpl-columns',
                        label: config.strings['trait.columns'],
                    },
                ],
                stylable: ['left', 'top', 'font-size', 'color', 'width'],
            },

            init() {
                this.on('change:attributes:data-tpl-collection', this.onCollectionChange);
                this.on('change:attributes:data-tpl-columns', this.renderPreview);

                if (!this.getAttributes()['data-tpl-columns']) {
                    this.setColumns(this.defaultColumns());
                }

                this.renderPreview();
            },

            /**
             * NOT named `collection`: a GrapesJS component is a Backbone model, and
             * Backbone already uses `this.collection` for the collection that contains
             * it. Shadowing it with a method broke the component at construction time.
             */
            collectionKey() {
                return this.getAttributes()['data-tpl-collection'] ?? '';
            },

            /** @returns {Array<object>} the configured columns */
            columns() {
                try {
                    return JSON.parse(this.getAttributes()['data-tpl-columns'] || '[]');
                } catch {
                    return [];
                }
            },

            setColumns(columns) {
                this.addAttributes({ 'data-tpl-columns': JSON.stringify(columns) });
            },

            /**
             * Every column the catalogue declares for the collection, in its order.
             * A table that starts empty is a table the user has to build twice.
             */
            defaultColumns() {
                const binding = config.bindings.find((item) => item.key === this.collectionKey());

                return (binding?.columns ?? []).map((column) => ({
                    key: column.key,
                    label: column.label,
                    width: '',
                    align: column.default_format === 'money' || column.default_format === 'number'
                        ? 'right'
                        : 'left',
                    format: column.default_format ?? '',
                }));
            },

            onCollectionChange() {
                // The columns of one collection mean nothing in another.
                this.setColumns(this.defaultColumns());
                this.renderPreview();
            },

            /**
             * The canvas shows the header row and one sample row — or, when real lines are
             * being previewed, up to a handful of them — because a table whose body only
             * exists at render time is impossible to lay out by eye.
             */
            renderPreview() {
                const content = this.previewContent();

                /*
                 * `content` and not `components()`: the preview table is furniture the
                 * user configures through traits, never by clicking a cell. As child
                 * components its rows and cells were 30 rows in the layer tree; as
                 * content they are none, and there is nothing to select by accident.
                 */
                if (this.components().length) {
                    this.components().reset();
                }

                if (this.get('content') !== content) {
                    this.set('content', content);
                }
            },

            /** Redraw from the current preview state (real lines picked or cleared). */
            syncPreview() {
                this.renderPreview();
            },

            /**
             * The markup the canvas shows. `example` forces the catalogue's single sample
             * row and ignores any real lines — what a saved version must carry.
             */
            previewContent(example = false) {
                const columns = this.columns().filter((column) => !column.hidden);
                const key = this.collectionKey();
                const sample = config.fixture?.[key] ?? [];
                const rows = example
                    ? [Array.isArray(sample) ? (sample[0] ?? {}) : {}]
                    : previewRows(config, key);

                const head = columns
                    .map((column) => `<th style="${thStyle(column)}">`
                        + `${escapeText(column.label)}</th>`)
                    .join('');

                // No columns yet (a collection that declares none, or a document with no
                // line collection at all — a label): an empty <table> collapses to 0×0 and
                // reads as "the table vanished on drop". Show a sized placeholder instead, so
                // the block is visible and can be configured or removed. Canvas-only (never
                // printed); dropped in the clean view.
                if (columns.length === 0) {
                    return `<div class="block--lines__empty">`
                        + `${escapeText(config.strings['block.line_table'] ?? 'Tabela')}</div>`;
                }

                const body = (rows.length > 0 ? rows : [{}])
                    .map((first) => '<tr>' + columns
                        .map((column) => `<td style="text-align:${column.align || 'left'}">`
                            + `${escapeText(String(first[column.key] ?? column.label))}</td>`)
                        .join('') + '</tr>')
                    .join('');

                return `<table class="lines"><thead><tr>${head}</tr></thead>`
                    + `<tbody>${body}</tbody></table>`;
            },

            /**
             * The only place a `{% for %}` exists (spec 7.2).
             *
             * The header goes in a `thead`: that is what makes the engine repeat it on
             * every page the table spills onto, and it is the same mechanism the
             * compositor uses for the document's own header band.
             */
            toHTML() {
                const columns = this.columns().filter((column) => !column.hidden);
                const collection = this.collectionKey();
                const item = 'linha';

                const head = columns
                    .map((column) => `<th style="${thStyle(column)}">`
                        + `${escapeText(column.label)}</th>`)
                    .join('');

                const cells = columns
                    .map((column) => {
                        const value = column.format
                            ? `{{ ${item}.${column.key}|${column.format} }}`
                            : `{{ ${item}.${column.key} }}`;

                        return `<td style="text-align:${column.align || 'left'}">${value}</td>`;
                    })
                    .join('');

                return `<div${attributesToHTML(this)}><table class="lines">`
                    + `<thead><tr>${head}</tr></thead><tbody>`
                    + `{% for ${item} in ${collection} %}<tr>${cells}</tr>{% endfor %}`
                    + '</tbody></table></div>';
            },
        },
    });

    editor.BlockManager.add('line-table', {
        media: icons.tableList,
        label: escapeText(config.strings['block.line_table']),
        category: { id: 'templates', label: '' },
        content: { type: 'line-table' },
    });
}

/**
 * The columns editor.
 *
 * GrapesJS has no repeatable trait, so this is a small table of its own: one row per
 * column, with the label, width, alignment and format the user can change. The set of
 * columns itself comes from the catalogue and cannot be invented here — a column bound
 * to nothing would render as an empty cell on every line.
 */
function registerColumnsTrait(editor, config) {
    editor.TraitManager.addType('tpl-columns', {
        createInput({ component }) {
            const wrapper = document.createElement('div');
            wrapper.className = 'tpl-columns';

            // The column being dragged by its grip; a drop moves it before the card it
            // lands on. Reordering here IS reordering the printed columns, so it goes
            // through `setColumns` like any other edit.
            let dragFrom = null;

            const redraw = () => {
                wrapper.replaceChildren();

                component.columns().forEach((column, index) => {
                    wrapper.append(columnCard(component, column, index, config, redraw, reorder));
                });
            };

            const reorder = {
                start(index) { dragFrom = index; },
                allow(event) { if (dragFrom !== null) event.preventDefault(); },
                drop(index) {
                    if (dragFrom === null || dragFrom === index) {
                        dragFrom = null;

                        return;
                    }

                    const columns = component.columns();
                    const [moved] = columns.splice(dragFrom, 1);
                    columns.splice(index, 0, moved);
                    dragFrom = null;
                    component.setColumns(columns);
                    redraw();
                },
                end() { dragFrom = null; },
            };

            redraw();
            component.on('change:attributes:data-tpl-collection', redraw);

            return wrapper;
        },
    });
}

/**
 * One column, as a card: a header with the source key, a drag grip and a remove button,
 * then the label, the width (in millimetres) and alignment side by side, and the format.
 * The card gives each control room the old single-row layout could not, in a trait panel
 * too narrow for four fields abreast.
 */
function columnCard(component, column, index, config, redraw, reorder) {
    const card = document.createElement('div');
    card.className = 'tpl-col';

    const isHidden = column.hidden === true;

    if (isHidden) {
        card.classList.add('tpl-col--hidden');
    }

    const update = (patch) => {
        const columns = component.columns();
        columns[index] = { ...columns[index], ...patch };
        component.setColumns(columns);
    };

    // The card is a drop target; the grip is what the user actually drags, so dragging a
    // card never fights the text selection inside its inputs.
    card.addEventListener('dragover', (event) => reorder.allow(event));
    card.addEventListener('drop', (event) => { event.preventDefault(); event.stopPropagation(); reorder.drop(index); });

    const head = document.createElement('div');
    head.className = 'tpl-col__head';

    const grip = document.createElement('span');
    grip.className = 'tpl-col__grip';
    grip.setAttribute('draggable', 'true');
    grip.title = config.strings['trait.column_reorder'];
    grip.setAttribute('aria-label', config.strings['trait.column_reorder']);
    grip.innerHTML = gripIcon();
    grip.addEventListener('dragstart', (event) => {
        reorder.start(index);

        if (event.dataTransfer) {
            event.dataTransfer.effectAllowed = 'move';
            // A private MIME, never `text/plain`: a grip released over the canvas must not
            // let the editor's external-drop handler read the index as text and insert it
            // as a block.
            event.dataTransfer.setData('application/x-tpl-column', String(index));
            event.dataTransfer.setDragImage(card, 12, 12);
        }
    });
    grip.addEventListener('dragend', () => reorder.end());

    const key = document.createElement('span');
    key.className = 'tpl-col__key';
    key.textContent = column.key;
    key.title = column.key;

    // A visibility toggle, not a delete: the columns come from the catalogue, so a removed
    // one can only be brought back by resetting the whole list. Hiding keeps the column and
    // its settings, drops it from the printed table, and is undone with the same click.
    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'tpl-col__toggle';
    toggle.title = config.strings[isHidden ? 'trait.column_show' : 'trait.column_hide'];
    toggle.setAttribute('aria-label', toggle.title);
    toggle.setAttribute('aria-pressed', String(!isHidden));
    toggle.innerHTML = isHidden ? eyeOffIcon() : eyeIcon();
    toggle.addEventListener('click', () => {
        update({ hidden: !isHidden });
        redraw();
    });

    head.append(grip, key, toggle);

    const labelField = columnField(config.strings['trait.column_label']);
    const label = document.createElement('input');
    label.type = 'text';
    label.className = 'gjs-field';
    label.value = column.label ?? '';
    label.addEventListener('change', () => update({ label: label.value }));
    labelField.append(label);

    const two = document.createElement('div');
    two.className = 'tpl-col__two';

    const widthField = columnField(config.strings['trait.column_width']);
    const widthWrap = document.createElement('div');
    widthWrap.className = 'tpl-col__wunit';
    const width = document.createElement('input');
    width.type = 'number';
    width.min = '0';
    width.step = '1';
    width.inputMode = 'decimal';
    width.className = 'gjs-field';
    width.value = column.width ?? '';
    width.placeholder = 'auto';
    width.addEventListener('change', () => update({ width: width.value.trim() }));
    const unit = document.createElement('span');
    unit.className = 'tpl-col__unit';
    unit.textContent = 'mm';
    widthWrap.append(width, unit);
    widthField.append(widthWrap);

    const alignField = columnField(config.strings['trait.column_align']);
    const seg = document.createElement('div');
    seg.className = 'tpl-col__seg';
    [['left', alignIcon('left')], ['center', alignIcon('center')], ['right', alignIcon('right')]]
        .forEach(([value, svg]) => {
            const option = document.createElement('button');
            option.type = 'button';
            option.innerHTML = svg;
            option.title = config.strings['align.' + value];
            option.setAttribute('aria-label', config.strings['align.' + value]);

            const on = (column.align ?? 'left') === value;
            option.setAttribute('aria-pressed', String(on));
            option.classList.toggle('is-on', on);

            option.addEventListener('click', () => {
                seg.querySelectorAll('button').forEach((other) => {
                    other.classList.remove('is-on');
                    other.setAttribute('aria-pressed', 'false');
                });
                option.classList.add('is-on');
                option.setAttribute('aria-pressed', 'true');
                update({ align: value });
            });

            seg.append(option);
        });
    alignField.append(seg);

    two.append(widthField, alignField);

    const formatField = columnField(config.strings['trait.format']);
    const format = document.createElement('select');
    format.className = 'gjs-field';
    [['', 'format.none'], ...config.formats.map((name) => [name, 'format.' + name])]
        .forEach(([value, key2]) => {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = config.strings[key2];
            option.selected = (column.format ?? '') === value;
            format.append(option);
        });
    format.addEventListener('change', () => update({ format: format.value }));
    formatField.append(format);

    card.append(head, labelField, two, formatField);

    return card;
}

/** A labelled field slot: the uppercase caption above, the control appended by the caller. */
function columnField(labelText) {
    const field = document.createElement('div');
    field.className = 'tpl-col__field';

    const caption = document.createElement('label');
    caption.className = 'tpl-col__label';
    caption.textContent = labelText;
    field.append(caption);

    return field;
}

function gripIcon() {
    return '<svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor" aria-hidden="true">'
        + '<circle cx="9" cy="6" r="1.5"/><circle cx="15" cy="6" r="1.5"/>'
        + '<circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/>'
        + '<circle cx="9" cy="18" r="1.5"/><circle cx="15" cy="18" r="1.5"/></svg>';
}

function eyeIcon() {
    return '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor"'
        + ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        + '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>';
}

function eyeOffIcon() {
    return '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor"'
        + ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        + '<path d="M9.9 4.24A9.1 9.1 0 0 1 12 4c6.5 0 10 7 10 7a13.3 13.3 0 0 1-2.16 2.92M6.1 6.1A13.3 13.3 0 0 0 2 11s3.5 7 10 7a9 9 0 0 0 4.9-1.4M1 1l22 22"/>'
        + '<path d="M9.5 9.54a3 3 0 0 0 4.24 4.24"/></svg>';
}

function alignIcon(direction) {
    const lines = {
        left: 'M4 6h16M4 12h10M4 18h13',
        center: 'M4 6h16M7 12h10M6 18h12',
        right: 'M4 6h16M10 12h10M7 18h13',
    };

    return '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor"'
        + ' stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="'
        + lines[direction] + '"/></svg>';
}

/* ---------------------------------------------------------------------------
 * QR / barcode slot
 * ------------------------------------------------------------------------ */
function registerCodeSlot(editor, config) {
    /*
     * A freshly dropped slot must NOT be born legal, and that is the whole reason this is
     * more than `bindings[0]`.
     *
     * `applyLegalMarking()` keys off the block's CURRENT binding: a block that lands on a
     * legal key is immediately undeletable, unreassignable, and — for a code slot — locked
     * to `dynamic`, because a legal code is locked by leaving the origin select nothing
     * else to choose. The catalogue declares exactly one code binding and it is legal
     * (`documento.qr`), so defaulting to "the first qr/barcode binding" made "Texto fixo"
     * unreachable in the product: every slot the user dropped was born locked out of it.
     *
     * So: prefer a NON-LEGAL qr/barcode binding, and when the catalogue declares none —
     * which is today's case — be born as a fixed-text slot with no binding at all.
     * `LayoutValidator::codeSlotBoxes()` indexes such a slot by its component id, which is
     * exactly what it was written to do.
     */
    const codeBinding = config.bindings.find(
        (binding) => (binding.type === 'qr' || binding.type === 'barcode') && !binding.legal,
    );

    const defaultCodeAttributes = codeBinding
        ? {
            'data-tpl-code-source': 'dynamic',
            'data-tpl-node': nodeOfBinding(codeBinding.key),
            'data-tpl-binding': codeBinding.key,
        }
        : { 'data-tpl-code-source': 'fixed' };

    const sourceOptions = [
        { id: 'dynamic', name: config.strings['code_source.dynamic'] },
        { id: 'fixed', name: config.strings['code_source.fixed'] },
    ];

    const minimum = Number(config.visibilityLimits?.qr_size_min_mm ?? 30);

    editor.DomComponents.addType('code-slot', {
        isComponent: (el) => el?.dataset?.tplType === 'code-slot',
        model: {
            defaults: {
                name: config.strings['block.code_slot'],
                tagName: 'div',
                // A block holds no other block; see the data field (CGE-48).
                droppable: false,
                attributes: {
                    class: 'block block--code',
                    'data-tpl-type': 'code-slot',
                    ...defaultCodeAttributes,
                },
                editable: false,
                /*
                 * Two origins (spec 7.1): a select toggles between them, and a custom
                 * trait draws whatever the chosen one needs — the two chained selects
                 * of section 5 for `dynamic`, or the content type and its fields for
                 * `fixed`. A plain `select` trait cannot change what it draws when a
                 * sibling attribute changes, which is why the second one is custom.
                 */
                traits: [
                    {
                        type: 'select',
                        name: 'data-tpl-code-source',
                        label: config.strings['trait.code_source'],
                        options: sourceOptions,
                    },
                    {
                        type: 'tpl-code-content',
                        name: 'data-tpl-code-content',
                    },
                ],
                stylable: ['left', 'top', 'width', 'height'],
                style: { width: minimum + 'mm', height: minimum + 'mm' },
            },

            init() {
                /*
                 * A slot that carries a binding is dynamic by definition — a fixed-text slot
                 * never has one (its content is typed, not pulled from the dataset). The type's
                 * default attributes seed a blank slot as `fixed`, and GrapesJS merges that
                 * default onto a slot parsed from HTML that declares a binding but no explicit
                 * source — exactly the catalogue's legal `document.qr` slot. Left fixed, it has
                 * no content type and renders as the R-01 "content type that does not exist".
                 * Correct it here, before applyLegalMarking and everything downstream reads it.
                 */
                if (this.binding() !== '' && this.getAttributes()['data-tpl-code-source'] !== 'dynamic') {
                    this.addAttributes({ 'data-tpl-code-source': 'dynamic' });
                }

                /*
                 * A slot saved before the origin existed carries only `data-tpl-binding`
                 * — the node is recovered from it so the dynamic select opens on the
                 * right value instead of the graph's first one.
                 */
                const node = this.getAttributes()['data-tpl-node'] || nodeOfBinding(this.binding());

                // Only when there IS one: a fixed-text slot carries no binding, so there
                // is no node to recover and writing an empty attribute would put a
                // meaningless `data-tpl-node=""` into the saved layout.
                if (node !== '' && this.getAttributes()['data-tpl-node'] !== node) {
                    this.addAttributes({ 'data-tpl-node': node });
                }

                this.on('change:attributes:data-tpl-code-source', this.applyLegalMarking);
                this.on('change:attributes:data-tpl-binding', this.applyLegalMarking);
                this.applyLegalMarking();

                // Any attribute can be the one that completes or breaks a fixed-text
                // payload — the content type, or any one of its own value fields — so the
                // marking listens to the lot. `applyFixedContentMarking()` writes only
                // when the state actually flips, so the attribute it sets itself cannot
                // loop.
                this.on('change:attributes', this.applyFixedContentMarking);
                this.applyFixedContentMarking();
                /*
                 * A placeholder, because the real image only exists at render time —
                 * the server generates it from the slot (spec 7.1, step 5). As
                 * `content` and not as a child component: a child would be two rows in
                 * the layer tree ("Text" and "Box") for something the user can neither
                 * select nor change.
                 */
                if (this.components().length) {
                    this.components().reset();
                }

                this.set('content', `<span class="block--code__hint">${escapeText(config.strings['block.code_slot'])}</span>`);
            },

            binding() {
                return this.getAttributes()['data-tpl-binding'] ?? '';
            },

            /**
             * A dynamic slot's legal marking still comes from the declared binding it
             * carries (spec 7.6, the `documento.qr` case); a fixed-text slot is never
             * legal — the certification team declares a value the law requires, never
             * a template the user typed.
             */
            applyLegalMarking() {
                const isFixed = this.getAttributes()['data-tpl-code-source'] === 'fixed';
                const binding = isFixed ? undefined : config.bindings.find((item) => item.key === this.binding());
                const isLegal = Boolean(binding?.legal);

                // A legal code slot is deletable like any other (CGE-26), so its trash icon
                // stays; removing the last one is guarded by the `core:component-delete`
                // confirm, not by withholding the icon. Copy stays disabled on a legal slot.
                // GrapesJS builds the toolbar once and never rebuilds it on a flag change, so
                // refresh it by hand: unset it and let `initToolbar()` rebuild from the new
                // flags.
                if (this.get('removable') !== true || this.get('copyable') !== !isLegal) {
                    this.set({ removable: true, copyable: !isLegal });
                    this.unset('toolbar');
                    this.initToolbar();
                }

                const marking = isLegal ? '1' : null;

                if ((this.getAttributes()['data-tpl-legal'] ?? null) !== marking) {
                    this.addAttributes({ 'data-tpl-legal': marking });
                }

                /*
                 * The `documento.qr` case (spec 7.6): a legal code is locked to
                 * `dynamic` the same way a legal data field is locked to its node and
                 * field — by leaving the origin select nothing else to choose, never
                 * `disabled`.
                 */
                const sourceTrait = this.getTrait('data-tpl-code-source');

                if (sourceTrait) {
                    const options = isLegal
                        ? sourceOptions.filter((option) => option.id === 'dynamic')
                        : sourceOptions;

                    if (sourceTrait.get('options')?.length !== options.length) {
                        sourceTrait.set('options', options);
                    }
                }
            },

            /**
             * A fixed-text code whose required field is still empty composes a payload
             * that is valid and meaningless — `tel:` with no number is a URI a phone
             * reads and does nothing with. Nothing on the server can refuse it: the
             * payload is well formed, and R-05 will decode exactly what was asked for.
             * So it is marked on the block, while the user is still looking at the panel
             * that would fix it.
             */
            applyFixedContentMarking() {
                const attributes = this.getAttributes();
                const marking = missingFixedFields(attributes, config).length > 0 ? '1' : null;

                if ((attributes['data-tpl-code-incomplete'] ?? null) !== marking) {
                    this.addAttributes({ 'data-tpl-code-incomplete': marking });
                }
            },

            /**
             * An empty `img` the compositor fills in. No template expression: the code
             * is an image generated on the server, not a value substituted into text.
             */
            toHTML() {
                const attributes = { ...this.getAttrToHTML() };
                delete attributes.class;

                const pairs = Object.entries(attributes)
                    .filter(([, value]) => value !== null && value !== undefined)
                    .map(([name, value]) => ` ${name}="${escapeAttribute(String(value))}"`)
                    .join('');

                return `<img class="block block--code"${pairs} alt="">`;
            },
        },

        view: {
            /*
             * A slot saved before the placeholder became `content` is an `<img>` in the
             * stored layout, and an image with no source draws the browser's
             * broken-image icon on the sheet. A transparent pixel, set on the element
             * and never as an attribute of the component: `toHTML()` builds its own
             * attributes, so nothing of this reaches the layout or the PDF.
             */
            onRender() {
                const el = this.el;

                if (el?.tagName === 'IMG' && !el.getAttribute('src')) {
                    el.setAttribute('src', TRANSPARENT_PIXEL);
                }
            },
        },
    });

    editor.BlockManager.add('code-slot', {
        media: icons.qrcode,
        label: escapeText(config.strings['block.code_slot']),
        category: { id: 'templates', label: '' },
        content: { type: 'code-slot' },
    });
}

/**
 * The code slot's origin-dependent fields: the two chained selects of section 5 for
 * `dynamic` (spec 7.1 — the same graph, the same rules, no mechanism of its own), or
 * the content type and one field per value its payload needs for `fixed` (spec 7.2).
 *
 * A plain `select` trait cannot change what it draws when a SIBLING trait's value
 * changes, so — like `tpl-columns` above — this is a custom trait type that redraws
 * its own DOM instead of relying on GrapesJS's declarative trait options.
 */
function registerCodeContentTrait(editor, config) {
    editor.TraitManager.addType('tpl-code-content', {
        createInput({ component }) {
            const wrapper = document.createElement('div');
            wrapper.className = 'tpl-code-content';

            /*
             * `fixedContentRows()` can write back a fallback `data-tpl-code-type`
             * (below), and `addAttributes` reports every attribute as changed —
             * including the one this same redraw listens to. Without the guard, the
             * re-entrant call rebuilds and appends its own rows before this one
             * appends the rows it already computed, doubling every field.
             */
            let redrawing = false;

            const redraw = () => {
                if (redrawing) {
                    return;
                }

                redrawing = true;

                try {
                    wrapper.replaceChildren();

                    const rows = (component.getAttributes()['data-tpl-code-source'] ?? 'dynamic') === 'fixed'
                        ? fixedContentRows(component, config)
                        : dynamicContentRows(component, config);

                    rows.forEach((row) => wrapper.append(row));
                } finally {
                    redrawing = false;
                }
            };

            redraw();
            component.on('change:attributes:data-tpl-code-source', redraw);
            component.on('change:attributes:data-tpl-code-type', redraw);
            component.on('change:attributes:data-tpl-node', redraw);
            // Exactly when the completeness state flips, which is when the red marks on
            // the rows below stop being right. Set by `applyFixedContentMarking()`, never
            // inside this redraw, so it cannot re-enter.
            component.on('change:attributes:data-tpl-code-incomplete', redraw);

            return wrapper;
        },
    });
}

/**
 * The server only knows how to compose a code from a binding the catalogue typed as
 * a code: `CodeImageGenerator::inject()` renders `qr` and `barcode` and throws for
 * anything else. A dynamic code source therefore offers only fields of those two
 * types — not the whole graph a data field can reach — because offering more would
 * be a choice the server fails on at render time, on a fiscal document. (Spec 7.1
 * says a dynamic code reuses the data field's graph unfiltered; that instruction did
 * not account for the generator's own type check, so this filter is the correction.)
 */
function codeableFieldsOf(config, nodeId) {
    return fieldsOfNode(config, nodeId).filter((field) => field.type === 'qr' || field.type === 'barcode');
}

/** Only a node that has at least one codeable field belongs in this select at all. */
function codeableNodeOptions(config) {
    return nodeOptionsOf(config).filter((node) => codeableFieldsOf(config, node.id).length > 0);
}

/**
 * A legal code (spec 7.6, `documento.qr`) is locked to the node and field it already
 * carries, the same way a legal data field is (components.js) — narrowed options,
 * never `disabled`. A key nobody declared is never legal, so a field reached only
 * through this select is never mistaken for one.
 */
function dynamicContentRows(component, config) {
    const currentBinding = component.getAttributes()['data-tpl-binding'] ?? '';
    const declaredBinding = config.bindings.find((item) => item.key === currentBinding);
    const isLegal = Boolean(declaredBinding?.legal);

    const nodeOptions = codeableNodeOptions(config);
    const currentNode = component.getAttributes()['data-tpl-node'] || nodeOfBinding(currentBinding) || nodeOptions[0]?.id || '';

    const nodeRow = contentRow(config.strings['trait.node'], selectField(
        isLegal
            ? [nodeOptions.find((option) => option.id === currentNode) ?? { id: currentNode, name: currentNode }]
            : nodeOptions,
        currentNode,
        (value) => {
            const fields = codeableFieldsOf(config, value);

            component.addAttributes({ 'data-tpl-node': value, 'data-tpl-binding': fields[0]?.key ?? '' });
        },
    ));

    const fieldOptions = codeableFieldsOf(config, currentNode).map((field) => ({ id: field.key, name: field.label }));

    const fieldRow = contentRow(config.strings['trait.field_source'], selectField(
        isLegal
            ? [{ id: currentBinding, name: declaredBinding?.label ?? currentBinding }]
            : fieldOptions,
        currentBinding,
        (value) => component.addAttributes({ 'data-tpl-binding': value }),
    ));

    return [nodeRow, fieldRow];
}

/**
 * `config.codeTypes` is the catalogue's own `code_types` entry (spec 7.2): a new
 * content type is a configuration entry, and this needs no change to offer it.
 *
 * The fallback type is written back to the attribute immediately, not just shown —
 * otherwise a slot left on the default the moment the origin switches to `fixed`
 * would export with no `data-tpl-code-type` at all, and `CodePayload` has nothing to
 * compose from.
 */
function fixedContentRows(component, config) {
    const typeKeys = Object.keys(config.codeTypes ?? {});
    const currentType = component.getAttributes()['data-tpl-code-type'] || typeKeys[0] || '';

    if (component.getAttributes()['data-tpl-code-type'] !== currentType) {
        component.addAttributes({ 'data-tpl-code-type': currentType });
    }

    const typeOptions = typeKeys.map((type) => ({ id: type, name: config.strings['code_type.' + type] ?? type }));

    const typeRow = contentRow(config.strings['trait.code_type'], selectField(typeOptions, currentType, (value) => {
        component.addAttributes({ 'data-tpl-code-type': value });
    }));

    const missing = missingFixedFields(component.getAttributes(), config);

    const fieldRows = (config.codeTypes?.[currentType]?.fields ?? []).map((field) => {
        const input = textField(component.getAttributes()['data-tpl-code-' + field] ?? '', (value) => {
            component.addAttributes({ ['data-tpl-code-' + field]: value });
        });

        // A required field left empty is marked here as well as on the block: the block
        // says "this code is not finished", the row says which one of its fields.
        if (missing.includes(field)) {
            input.classList.add('tpl-code-content__field--missing');
            input.title = config.strings['check.code_incomplete'] ?? '';
        }

        return contentRow(config.strings['code_field.' + field] ?? field, input);
    });

    return [typeRow, ...fieldRows];
}

/**
 * The required fields of a fixed-text slot that are still empty.
 *
 * `required` is declared per content type in `config/templates.php` and travels in the
 * catalogue payload, so a new content type says for itself which of its fields cannot be
 * blank. A subject, an SMS body and a Wi-Fi password legitimately can be; a phone number
 * cannot, and `tel:` on its own is a code that scans to nothing.
 *
 * @param {object} attributes  the slot's own attributes
 * @param {object} config  the editor catalogue
 * @returns {Array<string>} the names of the empty required fields
 */
function missingFixedFields(attributes, config) {
    if ((attributes['data-tpl-code-source'] ?? 'dynamic') !== 'fixed') {
        return [];
    }

    const type = attributes['data-tpl-code-type'] ?? '';

    return (config.codeTypes?.[type]?.required ?? [])
        .filter((field) => String(attributes['data-tpl-code-' + field] ?? '').trim() === '');
}

function contentRow(labelText, input) {
    const row = document.createElement('div');
    row.className = 'tpl-code-content__row';

    const label = document.createElement('span');
    label.className = 'tpl-code-content__label';
    label.textContent = labelText;
    row.append(label, input);

    return row;
}

function selectField(options, value, onChange) {
    const select = document.createElement('select');
    select.className = 'gjs-field';

    options.forEach((option) => {
        const item = document.createElement('option');
        item.value = option.id;
        item.textContent = option.name;
        item.selected = option.id === value;
        select.append(item);
    });

    select.addEventListener('change', () => onChange(select.value));

    return select;
}

function textField(value, onChange) {
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'gjs-field';
    input.value = value;
    input.addEventListener('change', () => onChange(input.value));

    return input;
}

/* ---------------------------------------------------------------------------
 * Shared
 * ------------------------------------------------------------------------ */
/**
 * The header cell's inline style: text alignment, and the column width WHEN the user set
 * one. The width is emitted as CSS `width`, never the legacy `width=""` attribute — that
 * attribute only accepts pixels or a percentage, so `width="24mm"` is silently dropped by
 * the browser, and the whole layout is in millimetres. With `table-layout:fixed` the
 * header row's CSS widths drive every column, so this is where a width takes effect.
 */
function thStyle(column) {
    const align = column.align || 'left';
    const width = normalizeWidth(column.width);

    return `text-align:${align}` + (width ? `;width:${width}` : '');
}

/**
 * A width the user typed, as a safe CSS length. The layout's unit is the millimetre, so a
 * bare number is read as `mm`; a value that already carries a unit keeps it. Anything that
 * is not a length is dropped rather than written into the style, which also keeps the
 * value from breaking out of the attribute.
 */
function normalizeWidth(value) {
    const raw = String(value ?? '').trim().toLowerCase();

    if (raw === '') {
        return '';
    }

    const match = raw.match(/^(\d*\.?\d+)(mm|cm|in|px|pt|%)?$/);

    return match ? match[1] + (match[2] || 'mm') : '';
}

function attributesToHTML(component) {
    return Object.entries(component.getAttrToHTML())
        .filter(([, value]) => value !== null && value !== undefined)
        .map(([name, value]) => ` ${name}="${escapeAttribute(String(value))}"`)
        .join('');
}

function escapeText(value) {
    const element = document.createElement('div');
    element.textContent = value;

    return element.innerHTML;
}

function escapeAttribute(value) {
    return String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
}
