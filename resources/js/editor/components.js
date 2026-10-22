/* ============================================================================
 * Component types for the template editor (spec 6.4).
 *
 * The user never writes template syntax. A data field carries its binding and its
 * format as traits, and only `toHTML()` turns them into `{{ binding|format }}` on
 * export — so `project_data` holds the choices, never the expression, and there is no
 * path from the interface to a template.
 *
 * Table and QR slot are phase F4 and are not here.
 * ========================================================================= */

import { icons } from './icons.js';
import { staticTextName } from './layer-names.js';
import { measuredBox } from './position-panel.js';
import { PX_PER_MM, roundTo } from './anchors.js';
import { alertDialog } from './dialog.js';
import { previewValue } from './preview-filter.js';
import {
    FORMULA_SOURCE_TRAIT, FORMULA_TOGGLE_TRAIT, freshFormulaKey, isFormulaKey, newFormulaId, refreshFormulaTraits,
    registerFormula,
} from './formula.js';
import { freshFormulaColumns, isFormulaColumn } from './formula-columns.js';
import {
    DATE_PATTERNS, DECIMALS, SYMBOLS, defaultSpecFor, expression, specFromAttributes, specToAttributes,
} from './formats.js';

const ARGUMENT_ATTRIBUTES = ['data-tpl-format-pattern', 'data-tpl-format-decimals', 'data-tpl-format-symbol'];

/**
 * The argument selects of each format (CGE-49), keyed by format name. Shared by the data
 * field (as traits) and the line-table column card (as plain selects).
 */
export function formatArgumentTraitsFor(config) {
    const strings = config.strings;
    const pattern = {
        type: 'select',
        name: 'data-tpl-format-pattern',
        label: strings['format_arg.pattern'],
        options: DATE_PATTERNS.map((id) => ({ id, name: strings['format_pattern.' + id] ?? id })),
        changeProp: false,
    };
    const decimals = (defaultLabel) => ({
        type: 'select',
        name: 'data-tpl-format-decimals',
        label: strings['format_arg.decimals'],
        options: DECIMALS.map((value) => ({
            id: value === null ? '' : String(value),
            name: value === null ? defaultLabel : String(value),
        })),
        changeProp: false,
    });
    const symbol = {
        type: 'select',
        name: 'data-tpl-format-symbol',
        label: strings['format_arg.symbol'],
        options: SYMBOLS.map((id) => ({ id, name: strings['format_symbol.' + id] ?? id })),
        changeProp: false,
    };

    return {
        date: [pattern],
        number: [decimals(strings['format_arg.decimals_number'])],
        money: [decimals(strings['format_arg.decimals_money']), symbol],
    };
}

/** Only these families exist; the catalogue is closed on the server. */
/*
 * One category, and it stays unlabelled: GrapesJS prints the id as a heading when
 * there is no label, and "templates" is a slug. The rail is already headed
 * with its own label, so the chrome hides the category bar altogether.
 */
const BLOCK_CATEGORY = { id: 'templates', label: '' };

/**
 * Register every component type and its palette block.
 *
 * @param {object} editor  GrapesJS instance
 * @param {object} config  bindings, nodes, formats, fixture, ruleWidths and strings
 */
export function registerComponents(editor, config) {
    registerTextareaTrait(editor);
    registerFormula(editor, config);
    registerZones(editor, config);
    registerStaticText(editor, config);
    registerDataField(editor, config);
    registerImage(editor, config);
    registerRule(editor, config);
    registerPageNumber(editor, config);
}

/* ---------------------------------------------------------------------------
 * Page number (spec 6.4)
 *
 * It exists as a block at all only because the compositor paginates on the server: the
 * CSS page counter does not work in this engine — measured, it prints zero on every
 * page — and the engine's own footer would put the number where the engine chooses
 * rather than where the user does.
 *
 * Because the server decides the sheets, the page numbers arrive as ordinary bindings
 * (`pagina.numero`, `pagina.total`), so this block needs no special handling anywhere
 * downstream. It renders like any other field.
 * ------------------------------------------------------------------------ */
function registerPageNumber(editor, config) {
    editor.DomComponents.addType('page-number', {
        isComponent: (el) => el?.dataset?.tplType === 'page-number',
        model: {
            defaults: {
                name: config.strings['block.page_number'],
                tagName: 'div',
                // A block holds no other block; see the data field (CGE-48).
                droppable: false,
                attributes: {
                    class: 'block block--page-number',
                    'data-tpl-type': 'page-number',
                    'data-tpl-format': 'x',
                },
                editable: false,
                traits: [
                    {
                        type: 'select',
                        name: 'data-tpl-format',
                        label: config.strings['trait.page_format'],
                        options: [
                            { id: 'x', name: config.strings['page_format.x'] },
                            { id: 'x-of-y', name: config.strings['page_format.x_of_y'] },
                        ],
                    },
                ],
                stylable: TEXT_STYLES,
            },

            init() {
                this.on('change:attributes:data-tpl-format', this.renderPreviewText);
                this.renderPreviewText();
            },

            /** The canvas shows a plausible number, not the expression. */
            renderPreviewText() {
                const text = this.getAttributes()['data-tpl-format'] === 'x-of-y'
                    ? `1 ${config.strings['page_format.of']} 3`
                    : '1';

                if (this.components().length) {
                    this.components().reset();
                }

                if (this.get('content') !== text) {
                    this.set('content', text);
                }
            },

            toHTML() {
                const expression = this.getAttributes()['data-tpl-format'] === 'x-of-y'
                    ? `{{ pagina.numero }} ${escapeText(config.strings['page_format.of'])} {{ pagina.total }}`
                    : '{{ pagina.numero }}';

                return `<div${attributesToHTML(this)}>${expression}</div>`;
            },

            /** GrapesJS gives no helper for this, and hand-built strings must be escaped. */
            attributesToHTML() {
                return attributesToHTML(this);
            },
        },
    });

    addBlock(editor, 'page-number', config.strings['block.page_number'], icons.hashtag);
}

/** Shared by the types that build their own markup on export. */
function attributesToHTML(component) {
    return Object.entries(component.getAttrToHTML())
        .filter(([, value]) => value !== null && value !== undefined)
        .map(([name, value]) => ` ${name}="${escapeAttribute(String(value))}"`)
        .join('');
}

/* ---------------------------------------------------------------------------
 * Zones (spec 6.3)
 *
 * Header and footer repeat on every page and are laid out absolutely; the body
 * flows, which is what lets the engine break pages by itself. All three are fixed
 * furniture: they cannot be removed, copied or dragged, because a document without
 * them has nowhere to put anything.
 * ------------------------------------------------------------------------ */
function registerZones(editor, config) {
    /*
     * `isComponent` is what makes any of this apply.
     *
     * These three types carried their names, their locks and their reduced style list
     * from the first phase and none of it ever took effect: without `isComponent`,
     * GrapesJS has no way to recognise a parsed `<div class="zone zone--header">` as
     * this type, so every zone became a generic component. Measured in the layer tree,
     * which listed "Div, Div, Div" where the document's three bands should be — and
     * with them went `removable: false`, so the bands were deletable too.
     */
    const zone = (name, flow) => ({
        isComponent: (el) => el?.dataset?.tplZone === name,
        model: {
            defaults: {
                name: config.strings['zone.' + name],
                removable: false,
                copyable: false,
                draggable: false,
                selectable: true,
                highlightable: true,
                attributes: { class: 'zone zone--' + name },
                // The body is the only zone whose children flow; in the other two the
                // user places blocks where they want them (RF-C03).
                'custom-name': config.strings['zone.' + name],
                layerable: true,
                // No 'height' (CGE-50): a band's height is the version's geometry, edited in
                // the page panel (geometry-panel.js). As a style it only stretched the element.
                stylable: ['background-color'],
            },
        },
    });

    editor.DomComponents.addType('zone-header', zone('header', false));
    editor.DomComponents.addType('zone-body', zone('body', true));
    editor.DomComponents.addType('zone-footer', zone('footer', false));

    editor.DomComponents.addType('page', {
        isComponent: (el) => el?.classList?.contains('page'),
        model: {
            defaults: {
                name: config.strings['zone.page'],
                // The sheet is the container, not a layer: leaving it in the tree puts
                // an extra level above the three bands and nothing can be done to it.
                layerable: false,
                // It holds its bands and nothing else. It is also the root of the layer
                // tree, so a block let go between two band rows landed on the sheet,
                // outside every band (CGE-48).
                droppable: false,
                removable: false,
                copyable: false,
                draggable: false,
                // Selectable (CGE-50): a click on the sheet's margin opens its page panel.
                selectable: true,
                attributes: { class: 'page' },
                stylable: false,
            },
        },
    });
}

/* ---------------------------------------------------------------------------
 * Static text (spec 6.4)
 * ------------------------------------------------------------------------ */
function registerStaticText(editor, config) {
    editor.DomComponents.addType('static-text', {
        isComponent: (el) => el?.dataset?.tplType === 'static-text',
        model: {
            defaults: {
                name: config.strings['block.static_text'],
                tagName: 'div',
                // A block holds no other block; see the data field (CGE-48).
                droppable: false,
                attributes: { class: 'block block--text', 'data-tpl-type': 'static-text' },
                // Editable as text, but never as HTML: the content is written as a
                // single textnode, so no markup can be pasted in.
                editable: true,
                components: [{ type: 'textnode', content: config.strings['block.static_text_default'] }],
                traits: [
                    {
                        // A textarea: with "Várias linhas" on, typed line breaks print.
                        type: 'tpl-textarea',
                        // NOT `content`: that is a reserved GrapesJS component property, and
                        // a changeProp trait sharing the name fought GrapesJS's own content
                        // handling and left the block empty when edited.
                        name: 'text',
                        label: config.strings['trait.content'],
                        changeProp: true,
                    },
                    wrapTrait(config),
                ],
                stylable: TEXT_STYLES,
            },

            init() {
                this.on('change:attributes:data-tpl-wrap', () => sizeForWrap(this, config));

                // Seed the trait from the block's current text so the Field panel shows it,
                // silently so merely opening a saved version is not marked as a change.
                this.set(
                    'text',
                    this.components().map((child) => child.get('content') || '').join(''),
                    { silent: true },
                );

                this.on('change:text', this.syncContent);
                // Layers and the selection header follow the text as it is edited.
                this.on('change:text', () => this.trigger('change:name'));
            },

            /** Named after what it says (CGE-53), computed and never stored. */
            getName() {
                const text = this.get('text')
                    ?? this.components().map((child) => child.get('content') || '').join('');

                return staticTextName(text, config.strings['block.static_text_empty']);
            },

            /*
             * Write the typed text as ONE textnode.
             *
             * Not `components(string)`: GrapesJS parses that string as HTML, and a plain,
             * tagless value parsed to nothing — so editing the content emptied the block,
             * which then collapsed to 0×0 and looked like it had vanished. A textnode's
             * content is rendered as text, so nothing typed can become markup either.
             */
            syncContent() {
                const value = this.get('text');

                if (typeof value === 'string') {
                    this.components([{ type: 'textnode', content: value }]);
                }
            },
        },
    });

    addBlock(editor, 'static-text', config.strings['block.static_text'], icons.font);
}

/* ---------------------------------------------------------------------------
 * Data field (spec 6.4)
 * ------------------------------------------------------------------------ */
function registerDataField(editor, config) {
    /*
     * Two chained selects (spec 5.1): the node, and then the fields of that node. The
     * flattened list of every key is gone — it was eighteen entries and it will be
     * hundreds once the graph reaches the whole customer table.
     *
     * What is STORED is unchanged: `data-tpl-binding="adquirente.vat_number"`, node and
     * field joined by a dot, exactly the shape the declared keys already had. The
     * validator, the compositor and the Twig template therefore need no change at all.
     */
    const nodeOptions = nodeOptionsOf(config);
    const fieldOptionsOf = (nodeId) => fieldsOfNode(config, nodeId).map((field) => ({ id: field.key, name: field.label }));

    /*
     * A freshly dropped data field must NOT be born legal.
     *
     * `applyLegalMarking()` keys off the block's CURRENT binding, so a block that lands on
     * a legal key is undeletable and its binding unreassignable from the moment it hits
     * the canvas — and nothing forbids a second data field on a key the initial layout
     * already places, so what the user actually got was an undeletable duplicate of a
     * legal field. The default node is `documento` and its first field is the declared
     * legal `documento.designacao`, which is precisely that case.
     *
     * So the default is the first NON-LEGAL field of the default node. The fallback to
     * `[0]` covers a node whose every field is legal: there is nothing better to offer
     * then, and an empty binding would be worse.
     */
    const firstNode = nodeOptions[0]?.id ?? '';
    const firstFieldOptions = fieldOptionsOf(firstNode);
    const firstField = (
        firstFieldOptions.find((option) => !findBinding(config, option.id)?.legal) ?? firstFieldOptions[0]
    )?.id ?? '';

    const formatOptions = [{ id: '', name: config.strings['format.none'] }].concat(
        config.formats.map((format) => ({ id: format, name: config.strings['format.' + format] })),
    );

    const formatArgumentTraits = formatArgumentTraitsFor(config);

    /*
     * The node and field selects are kept as definitions because formula mode (CGE-37)
     * takes them off the panel and puts them back: a formula has no node and no field,
     * and two selects showing a binding the field no longer has would be a lie.
     */
    const nodeTrait = {
        type: 'select',
        name: 'data-tpl-node',
        label: config.strings['trait.node'],
        options: nodeOptions,
        changeProp: false,
    };
    const bindingTrait = (nodeId) => ({
        type: 'select',
        name: 'data-tpl-binding',
        label: config.strings['trait.field_source'],
        options: fieldOptionsOf(nodeId),
        changeProp: false,
    });
    const formulaToggleTrait = {
        type: 'tpl-formula-toggle',
        name: FORMULA_TOGGLE_TRAIT,
        label: config.strings['formula.label'],
    };
    const formulaSourceTrait = {
        type: 'tpl-formula',
        name: FORMULA_SOURCE_TRAIT,
        label: config.strings['formula.source'],
    };
    const formulaLabel = config.strings['formula.label'] ?? 'Formula';

    /** Every `formula.*` key on the sheet but `except`'s own. */
    const formulaKeysOnSheet = (except) => {
        const keys = [];
        const walk = (component) => {
            if (!component) {
                return;
            }

            const key = component !== except ? component.getAttributes?.()['data-tpl-binding'] : null;

            if (isFormulaKey(key)) {
                keys.push(key);
            }

            component.components?.().forEach(walk);
        };

        walk(editor.getWrapper());

        return keys;
    };

    /**
     * Every formula column key (`formula_<id>`) of every line table on the sheet but
     * `except`'s. V-19 refuses a column key shared across tables, so a copy checks them all.
     */
    const formulaColumnKeysOnSheet = (except) => {
        const keys = [];
        const walk = (component) => {
            if (!component) {
                return;
            }

            if (component !== except && component.get?.('type') === 'line-table' && typeof component.columns === 'function') {
                component.columns().filter(isFormulaColumn).forEach((column) => keys.push(column.key));
            }

            component.components?.().forEach(walk);
        };

        walk(editor.getWrapper());

        return keys;
    };

    /*
     * A copied formula field gets its own key (Review Focus 3). Copy, paste and the
     * toolbar's duplicate all go through `Component.clone()`, which announces the copy
     * BEFORE it is put on the sheet — so the original is still the only holder of the
     * key, and the copy is renamed whenever it would share it.
     *
     * Done on the clone event and not in `init()`: `init()` also runs for every block of
     * a layout being loaded, including the other format's layout on an in-place switch,
     * while the old page may still be in the tree — renaming there would rewrite a saved
     * key on opening and mark the version dirty for nothing.
     */
    editor.on('component:clone', (cloned) => {
        const rename = (component) => {
            const key = component.getAttributes?.()['data-tpl-binding'];

            if (component.get?.('type') === 'data-field' && isFormulaKey(key)) {
                const fresh = freshFormulaKey(formulaKeysOnSheet(component), key);

                if (fresh !== key) {
                    component.addAttributes({ 'data-tpl-binding': fresh });
                }
            }

            // A copied line table: its formula columns get their own keys too, one write.
            if (component.get?.('type') === 'line-table' && typeof component.columns === 'function') {
                const columns = component.columns();
                const fresh = freshFormulaColumns(columns, formulaColumnKeysOnSheet(component));

                if (fresh !== columns) {
                    component.setColumns(fresh);
                }
            }

            component.components?.().forEach(rename);
        };

        rename(cloned);
    });

    editor.DomComponents.addType('data-field', {
        isComponent: (el) => el?.dataset?.tplType === 'data-field',
        model: {
            defaults: {
                name: config.strings['block.data_field'],
                tagName: 'div',
                attributes: {
                    class: 'block block--field',
                    'data-tpl-type': 'data-field',
                    'data-tpl-node': firstNode,
                    'data-tpl-binding': firstField,
                    // No `data-tpl-format` here on purpose (CGE-49): a block that arrives
                    // without one was just dropped from the palette and is given its
                    // binding type's default format in `init()`. A saved layout always
                    // carries the attribute, even empty, and keeps what it says.
                },
                editable: false,
                /*
                 * Not a container, like every block (CGE-48). GrapesJS makes any
                 * component droppable unless its type says otherwise, and the layer
                 * tree sorts in nested mode: a block let go under the last row of a band
                 * went INSIDE that row's field. `renderPreviewText` then emptied the
                 * field's children for good, so the moved block lost its `#id` rule
                 * (size, colour, font) and its parent, and came back with only a top and
                 * a left. Before CGE-47 nothing put it back, and it vanished on save.
                 */
                droppable: false,
                /*
                 * The node, the binding and the format are ATTRIBUTES, not model
                 * properties. Traits with `changeProp` would live only in the model,
                 * and a layout initialised from HTML — which is how `layout_inicial`
                 * reaches the editor — would arrive with the choices lost. As
                 * attributes they round trip through HTML for free.
                 */
                traits: [
                    formulaToggleTrait,
                    nodeTrait,
                    bindingTrait(firstNode),
                    {
                        type: 'select',
                        name: 'data-tpl-format',
                        label: config.strings['trait.format'],
                        options: formatOptions,
                    },
                    wrapTrait(config),
                ],
                stylable: TEXT_STYLES,
            },

            init() {
                this.on('change:attributes:data-tpl-wrap', () => sizeForWrap(this, config));

                /*
                 * A layout saved before the node select existed carries only
                 * `data-tpl-binding` — the node is recovered from it so the select
                 * opens on the right value. Set through the same re-entry guard
                 * `onNodeChange` uses, so recovering it here does not also clear the
                 * field it belongs to.
                 */
                const node = this.getAttributes()['data-tpl-node'] || nodeOfBinding(this.binding());

                this.on('change:attributes:data-tpl-node', this.onNodeChange);
                this.on('change:attributes:data-tpl-binding', this.onBindingChange);
                this.on('change:attributes:data-tpl-format', this.onBindingChange);
                this.on('change:attributes:data-tpl-binding', this.onBindingPicked);
                this.on('change:attributes:data-tpl-format', this.onFormatPicked);
                // Formula mode (CGE-37) follows the binding, so undo turns it back too.
                this.on('change:attributes:data-tpl-binding', this.syncFormulaTraits);
                this.on('change:attributes:' + FORMULA_SOURCE_TRAIT, this.onBindingChange);

                ARGUMENT_ATTRIBUTES.forEach((name) => this.on('change:attributes:' + name, this.onBindingChange));

                if (!('data-tpl-format' in this.getAttributes())) {
                    this.applyFormatSpec(defaultSpecFor(findBinding(config, this.binding())?.type));
                }

                this.__pfBinding = this.binding();
                this.__pfFormat = this.formatName();
                this.syncFormatTraits();
                this.syncFormulaTraits();

                // A formula has no node: recovering one from `formula.<id>` would write a
                // `data-tpl-node="formula"` the saved layout never had.
                if (!this.isFormula() && this.getAttributes()['data-tpl-node'] !== node) {
                    this.__pfSyncingNode = true;

                    try {
                        this.addAttributes({ 'data-tpl-node': node });
                    } finally {
                        this.__pfSyncingNode = false;
                    }
                }

                this.getTrait('data-tpl-binding')?.set('options', fieldOptionsOf(node));
                this.onBindingChange();
            },

            binding() {
                return this.getAttributes()['data-tpl-binding'] ?? '';
            },

            formatName() {
                return this.getAttributes()['data-tpl-format'] ?? '';
            },

            /** Bound to `formula.<id>`: the value is computed from a formula (CGE-37). */
            isFormula() {
                return isFormulaKey(this.binding());
            },

            isLegalBinding() {
                return Boolean(findBinding(config, this.binding())?.legal);
            },

            /**
             * The "Fórmula" switch (CGE-37).
             *
             * On: a fresh `formula.<id>` key and an empty source. Off: the source goes and
             * the field is bound again exactly as a freshly dropped one is — the first
             * non-legal field of the default node (see `firstField` above) — because the
             * field it had before is long gone and guessing it back would be a surprise.
             * One attribute write each way, so a single undo reverses it.
             *
             * A legal field never switches on: the document would lose the field the law
             * asks for. The switch is disabled for it too; this is the guard behind it.
             */
            setFormulaMode(on) {
                if (Boolean(on) === this.isFormula() || (on && this.isLegalBinding())) {
                    return;
                }

                const attributes = { ...this.getAttributes({ noClass: true, noStyle: true }) };

                if (on) {
                    attributes['data-tpl-binding'] = freshFormulaKey(formulaKeysOnSheet(this), 'formula.' + newFormulaId());
                    attributes[FORMULA_SOURCE_TRAIT] = '';
                } else {
                    delete attributes[FORMULA_SOURCE_TRAIT];
                    attributes['data-tpl-node'] = firstNode;
                    attributes['data-tpl-binding'] = firstField;
                }

                // The node select is put back with the right options by `syncFormulaTraits`;
                // its change event must not also reset the field just chosen.
                this.__pfSyncingNode = true;

                try {
                    this.setAttributes(attributes);
                } finally {
                    this.__pfSyncingNode = false;
                }
            },

            /**
             * Formula mode shows the source box in place of the node and field selects;
             * otherwise the selects, as ever. Mirrors `syncFormatTraits`: traits are taken
             * off and put back rather than hidden, so the panel never holds a select whose
             * value the field no longer has.
             */
            syncFormulaTraits() {
                if (this.isFormula()) {
                    ['data-tpl-node', 'data-tpl-binding'].forEach((name) => this.getTrait(name) && this.removeTrait(name));

                    if (!this.getTrait(FORMULA_SOURCE_TRAIT)) {
                        this.addTrait(formulaSourceTrait, { at: 1 });
                    }
                } else {
                    if (this.getTrait(FORMULA_SOURCE_TRAIT)) {
                        this.removeTrait(FORMULA_SOURCE_TRAIT);
                    }

                    if (!this.getTrait('data-tpl-node')) {
                        this.addTrait(nodeTrait, { at: 1 });
                    }

                    if (!this.getTrait('data-tpl-binding')) {
                        this.addTrait(bindingTrait(this.getAttributes()['data-tpl-node'] || nodeOfBinding(this.binding())), { at: 2 });
                    }
                }

                refreshFormulaTraits(this, config.strings);
            },

            /**
             * The formula's state for the canvas and the line under the box: `empty` (no
             * source yet), `pending` (asked, not answered), `error` (what the server says
             * is wrong) or `result` (the value in this field's format; `blank` when it
             * prints nothing). The server is asked here, so drawing is asking.
             */
            formulaStatus() {
                const source = this.getAttributes()[FORMULA_SOURCE_TRAIT] ?? '';

                if (String(source).trim() === '') {
                    return { state: 'empty', text: '' };
                }

                const result = config.formulaPreview?.result(source, null, this.cid) ?? null;

                if (result === null) {
                    return { state: 'pending', text: '' };
                }

                if (result.error) {
                    return { state: 'error', text: String(result.error) };
                }

                const text = config.formatPreview
                    ? config.formatPreview.text(result.value, this.formatSpec())
                    : String(result.value ?? '');

                return text === '' ? { state: 'blank', text: '' } : { state: 'result', text };
            },

            /**
             * The canvas markup of a formula field. An error is drawn as a warning, in the
             * canvas's own `.tpl-formula-error` style (see `canvasDecorations`); a value
             * with nothing to print, or no source yet, shows the label — an empty block
             * collapses and could no longer be picked. While an answer is on its way the
             * previous text stays, so the canvas does not flicker on every keystroke.
             *
             * Only ever the live `content`: the saved one is the label (`previewContent`).
             */
            formulaContent() {
                const status = this.formulaStatus();

                if (status.state === 'pending' && this.__pfFormulaHtml !== undefined) {
                    return this.__pfFormulaHtml;
                }

                const html = status.state === 'error'
                    ? `<span class="tpl-formula-error">${escapeText(status.text)}</span>`
                    : status.state === 'result'
                        ? escapeText(status.text)
                        : escapeText(formulaLabel);

                if (status.state !== 'pending') {
                    this.__pfFormulaHtml = html;
                }

                return html;
            },

            formatSpec() {
                return specFromAttributes(this.getAttributes());
            },

            /** Writes a spec (or none) as this field's format attributes, in one change. */
            applyFormatSpec(spec) {
                // Recorded first: the attribute event below re-enters `onFormatPicked`,
                // which must see this as the format already in place.
                this.__pfFormat = spec?.name ?? '';
                this.addAttributes(specToAttributes(spec));
                this.syncFormatTraits();
            },

            /**
             * A new binding brings its type's default format (CGE-49): a date field
             * shows a date, a total shows money. Only a real change of binding — the
             * attribute event fires for every key `addAttributes` touches.
             */
            onBindingPicked() {
                if (this.binding() === this.__pfBinding) {
                    return;
                }

                const wasFormula = isFormulaKey(this.__pfBinding);

                this.__pfBinding = this.binding();

                /*
                 * A formula has no declared type, so no default format (CGE-37). Turning
                 * the switch on starts from none, as a field of unknown type would; a
                 * copy renamed to its own key (one formula key for another) keeps the
                 * format it was copied with.
                 */
                if (this.isFormula()) {
                    if (!wasFormula) {
                        this.applyFormatSpec(null);
                    }

                    return;
                }

                this.applyFormatSpec(defaultSpecFor(findBinding(config, this.binding())?.type));
            },

            /**
             * Choosing a format fills in its default arguments and empties those it does
             * not take, so the attributes never describe two formats at once.
             */
            onFormatPicked() {
                if (this.formatName() === this.__pfFormat) {
                    return;
                }

                const name = this.formatName();
                const fallback = ['date', 'money', 'number'].includes(name) ? defaultSpecFor(name) : null;

                this.applyFormatSpec(name ? { ...(fallback ?? {}), name } : null);
            },

            /** Only the argument selects the chosen format takes are shown. */
            syncFormatTraits() {
                const wanted = formatArgumentTraits[this.formatName()] ?? [];

                ARGUMENT_ATTRIBUTES.forEach((name) => {
                    if (this.getTrait(name) && !wanted.some((trait) => trait.name === name)) {
                        this.removeTrait(name);
                    }
                });

                wanted.forEach((trait) => {
                    if (!this.getTrait(trait.name)) {
                        this.addTrait(trait);
                    }
                });
            },

            /*
             * Changing the node clears the field: the fields of one node mean nothing
             * in another, and leaving the old key would keep a binding the new node
             * cannot satisfy. It is the rule the line table already applies when its
             * collection changes.
             *
             * Guarded like `onBindingChange`: `addAttributes` replaces the whole
             * attribute object, so GrapesJS reports every key as changed, including
             * `data-tpl-node` itself — without the guard, setting the cleared binding
             * below re-enters this handler and recurses.
             */
            onNodeChange() {
                // A formula has no node select (CGE-37); nothing may re-bind it to a field.
                if (this.__pfSyncingNode || this.isFormula()) {
                    return;
                }

                this.__pfSyncingNode = true;

                try {
                    const options = fieldOptionsOf(this.getAttributes()['data-tpl-node']);

                    // Default to the first NON-legal field, exactly as a freshly dropped
                    // field does (see `firstField` above). Snapping to a legal field here
                    // would mark the whole block legal and lock BOTH selects to a single
                    // option — trapping the user on the model they just picked, who would
                    // then have to delete the field and drop a new one to choose another.
                    // A legal field stays reachable: the user selects it in the field
                    // select, and the lock is intended only once they deliberately do so.
                    const firstField = options.find((option) => !findBinding(config, option.id)?.legal)
                        ?? options[0];

                    this.getTrait('data-tpl-binding')?.set('options', options);
                    this.addAttributes({ 'data-tpl-binding': firstField?.id ?? '' });
                } finally {
                    this.__pfSyncingNode = false;
                }
            },

            /**
             * Guarded against re-entry.
             *
             * `addAttributes` replaces the attribute object, and GrapesJS then reports
             * every key as changed — including the binding this method listens to. The
             * first version without this flag recursed until the stack blew, and the
             * only visible symptom was that the validation panel and the repair button
             * silently stopped working.
             */
            onBindingChange() {
                if (this.__pfSyncing) {
                    return;
                }

                this.__pfSyncing = true;

                try {
                    this.applyLegalMarking(config);
                    this.renderPreviewText(config);
                    nameAfterBinding(this, config, this.isFormula() ? formulaLabel : config.strings['block.data_field']);
                } finally {
                    this.__pfSyncing = false;
                }
            },

            /**
             * A block bound to a legal field is marked, cannot be removed or copied,
             * and its binding cannot be reassigned (RF-L02, RF-C06). This is UX
             * convenience: the server is what actually refuses (spec 3.2).
             */
            applyLegalMarking(config) {
                const binding = findBinding(config, this.binding());
                const isLegal = Boolean(binding?.legal);

                // A legal field is deletable like any other block, so the component toolbar
                // shows its trash icon (CGE-26). Removing the last block that keeps the
                // document compliant still asks for a deliberate confirm, but that guard now
                // lives on the delete command (see the `core:component-delete` override in
                // editor.js), so it covers the toolbar icon and the Delete key alike — it is
                // no longer enforced by withholding the icon. Copy stays disabled on a legal
                // field, so the toolbar never makes a fresh duplicate.
                //
                // GrapesJS builds a component's toolbar once, in `initToolbar()`, reading
                // `removable`/`copyable` at that instant, and never rebuilds it when they
                // change. Setting them here is not enough — the restored trash icon would
                // never appear (and a stale one would linger) — so the toolbar is refreshed
                // by hand: unset it and let `initToolbar()` rebuild from the new flags
                // (move + delete on a legal field, no duplicate).
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
                 * Soft lock: a legal field's node AND field selects stay fully editable, so
                 * it can be reassigned in place without deleting it first. Compliance is not
                 * weakened — the problems panel flags a missing legal field live, and the
                 * server refuses to save a layout without it (spec 8.1). The previous hard
                 * lock narrowed each select to a single option, which trapped the user on the
                 * model they had just picked: to choose another they had to delete the field
                 * and drop a new one. Reassigning a legal field away simply makes it a problem
                 * to resolve, exactly as moving or removing it does.
                 */
                const currentNode = nodeOfBinding(this.binding());
                const nodeTrait = this.getTrait('data-tpl-node');

                if (nodeTrait && nodeTrait.get('options')?.length !== nodeOptions.length) {
                    nodeTrait.set('options', nodeOptions);
                }

                const bindingTrait = this.getTrait('data-tpl-binding');
                const fieldOptions = fieldOptionsOf(currentNode);

                if (bindingTrait && bindingTrait.get('options')?.length !== fieldOptions.length) {
                    bindingTrait.set('options', fieldOptions);
                }
            },

            /**
             * The sample text of this field. `example` forces the catalogue's fixture and
             * ignores any real record being previewed — what a saved version must carry.
             */
            previewText(example = false) {
                /*
                 * A formula's saved text is its label, always (CGE-37). It must not depend
                 * on the server's answer: `plainProjectData` swaps this in for the dirty
                 * check and the save, and an answer arriving would otherwise change the
                 * saved layout — a version marked unsaved by nothing the user did.
                 */
                if (this.isFormula()) {
                    return formulaLabel;
                }

                const key = this.binding();
                const sample = example ? config.fixture?.[key] : previewValue(config, key);

                if (sample === null || sample === undefined) {
                    return String(findBinding(config, key)?.label ?? key);
                }

                // The canvas shows the value in this field's format, exactly as the PDF
                // prints it (CGE-49). The example a saved version carries stays raw.
                return example || !config.formatPreview
                    ? String(sample)
                    : config.formatPreview.text(sample, this.formatSpec());
            },

            /** The `content` this block shows (escaped), for the save/dirty read-back. */
            previewContent(example = false) {
                return this.isFormula() && !example ? this.formulaContent() : escapeText(this.previewText(example));
            },

            /** Redraw from the current preview state (a record was picked or cleared). */
            syncPreview() {
                this.renderPreviewText(config);
            },

            /**
             * What the canvas shows: the fixture value when there is one (or the value of
             * the real record being previewed), otherwise the field's label. Never the
             * template expression — the user is placing a field, not writing syntax.
             */
            renderPreviewText(config) {
                const html = this.previewContent();

                /*
                 * `content` and not `components()`: the preview text is furniture, and
                 * a child component would be a row in the layer tree. Measured on the
                 * invoice, those rows turned 19 blocks into 76 layers, with the blocks
                 * buried among `Box`. `content` renders as inner HTML and creates no
                 * component at all — which is also why `layerable: false` could never
                 * reach them.
                 *
                 * Rebuilding on every event would also mean a change event on every
                 * event, and the editor would never settle.
                 */
                /*
                 * A version saved before this change carries the old child components
                 * in its `project_data`, and `loadProjectData` restores them — so the
                 * children are emptied here as well as replaced by content. Done before
                 * the load cycle takes its snapshot, so it does not mark the version
                 * dirty on opening.
                 */
                if (this.components().length) {
                    this.components().reset();
                }

                if (this.get('content') !== html) {
                    this.set('content', html);
                }

                // The line under the formula box and the switch follow what was drawn.
                refreshFormulaTraits(this, config.strings);
            },

            /**
             * Export is the only place the template expression exists (spec 6.4).
             * Nothing the user can type reaches it: the binding comes from a closed
             * select and the format from another, both validated again on the server.
             */
            toHTML() {
                return `<div${this.attributesToHTML()}>${expression(this.binding(), this.formatSpec())}</div>`;
            },

            /** GrapesJS gives no helper for this, and hand-built strings must be escaped. */
            attributesToHTML() {
                return attributesToHTML(this);
            },
        },
    });

    addBlock(editor, 'data-field', config.strings['block.data_field'], icons.bracketsCurly);
}

/* ---------------------------------------------------------------------------
 * Image (spec 6.4)
 *
 * Read in the browser and stored as a data URI, not uploaded. The engine has no
 * network, so a referenced image would never reach the PDF; embedding is the only
 * shape that works, and it removes the upload endpoint entirely.
 * ------------------------------------------------------------------------ */
function registerImage(editor, config) {
    editor.DomComponents.addType('print-image', {
        isComponent: (el) => el?.dataset?.tplType === 'image',
        model: {
            defaults: {
                name: config.strings['block.image'],
                tagName: 'img',
                void: true,
                // A block holds no other block; see the data field (CGE-48).
                droppable: false,
                attributes: {
                    class: 'block block--image',
                    'data-tpl-type': 'image',
                    src: TRANSPARENT_PIXEL,
                    alt: '',
                },
                traits: [
                    {
                        type: 'tpl-file',
                        name: 'src',
                        label: config.strings['trait.file'],
                    },
                    {
                        type: 'select',
                        name: 'object-fit',
                        label: config.strings['trait.fit'],
                        options: [
                            { id: 'contain', name: config.strings['fit.contain'] },
                            { id: 'cover', name: config.strings['fit.cover'] },
                            { id: 'fill', name: config.strings['fit.fill'] },
                        ],
                    },
                ],
                // A visible default size: an image dropped empty is a 1px transparent
                // pixel otherwise, invisible on the sheet — and on a small label at a high
                // zoom that reads as "nothing happened". The placeholder style (canvasCss)
                // outlines the empty box so there is something to see and resize.
                style: { width: '25mm', height: '25mm' },
                stylable: ['left', 'top', 'right', 'bottom', 'width', 'height', 'opacity'],
            },
        },
    });

    registerFileTrait(editor, config);
    addBlock(editor, 'print-image', config.strings['block.image'], icons.image);
}

/**
 * The file trait: reads the chosen file in the browser and writes a data URI.
 *
 * The size limit is enforced here and again on the server. A large image is refused
 * rather than silently shrunk: an illegible logo in a certified document is worse
 * than a rejected upload.
 */
function registerFileTrait(editor, config) {
    editor.TraitManager.addType('tpl-file', {
        // The trait is bound to the image's `src`, so GrapesJS reflects every `src` change
        // back into the input by `input.value = src`. On a file input the browser forbids
        // that (only '' is allowed), and it threw `InvalidStateError`, which rolled the
        // whole update back — the embedded data URI never stuck. A file input shows no value
        // anyway, so reflecting into it is pointless: make it a no-op.
        setInputValue() {},

        // `component`, not `this.model`: inside a trait's `createInput` the view's `this.model`
        // is the TRAIT, so `this.model.setAttributes` wrote the data URI onto the trait and
        // never reached the image. The file input's native change, meanwhile, set the image's
        // `src` to the browser's fake path (`C:\fakepath\…`), which is not a `data:` URI — so a
        // perfectly good upload failed validation V-05 ("the image has no file"). Writing the
        // data URI to the component, after the reader loads, is what actually embeds the image.
        createInput({ trait, component }) {
            const input = document.createElement('input');
            input.type = 'file';
            input.accept = 'image/png,image/jpeg,image/svg+xml';
            input.className = 'gjs-field';

            input.addEventListener('change', (event) => {
                // Stop GrapesJS's own trait `change` handler (delegated on the view) from
                // reading the file input's value — which the browser reports as the fake path
                // `C:\fakepath\…`, not a data URI — and writing that to the image's `src`. We
                // own `src` entirely here, embedding the file as a data URI below.
                event.stopPropagation();

                const file = input.files?.[0];

                if (!file) {
                    return;
                }

                if (file.size > config.imageMaxBytes) {
                    input.value = '';
                    alertDialog(config.strings['error.image_too_large'], {
                        root: config.root,
                        closeLabel: config.strings['dialog.close'],
                    });

                    return;
                }

                const reader = new FileReader();
                reader.addEventListener('load', () => {
                    component.addAttributes({ src: String(reader.result) });
                });
                reader.readAsDataURL(file);
            });

            return input;
        },
    });
}

/* ---------------------------------------------------------------------------
 * Line / rectangle (spec 6.4)
 *
 * Thickness is a closed set, not free millimetres. The engine snaps fills to whole
 * CSS pixels (0.264583mm), so 0.1mm and 0.4mm print identically at 0.25mm — a free
 * field would promise a thickness that never appears.
 * See docs/impressos/NOTAS-FIDELIDADE.md.
 * ------------------------------------------------------------------------ */
function registerRule(editor, config) {
    const widthOptions = Object.entries(config.ruleWidths).map(([name, millimetres]) => ({
        id: String(millimetres),
        name: config.strings['rule_width.' + name],
    }));

    editor.DomComponents.addType('print-rule', {
        isComponent: (el) => el?.dataset?.tplType === 'rule',
        model: {
            defaults: {
                name: config.strings['block.rule'],
                tagName: 'div',
                void: true,
                // A block holds no other block; see the data field (CGE-48).
                droppable: false,
                attributes: { class: 'block block--rule', 'data-tpl-type': 'rule' },
                traits: [
                    {
                        type: 'select',
                        name: 'tpl-width',
                        label: config.strings['trait.thickness'],
                        options: widthOptions,
                        changeProp: true,
                    },
                    {
                        type: 'checkbox',
                        name: 'tpl-filled',
                        label: config.strings['trait.filled'],
                        changeProp: true,
                    },
                ],
                'tpl-width': widthOptions[0]?.id,
                'tpl-filled': false,
                // A default width so the rule is a visible line the moment it is dropped:
                // its thickness is its height, but with no width a bare `div` is 0 wide and
                // nothing shows — which read as "the line cannot be placed", worst of all on
                // a small label.
                style: { width: '40mm' },
                stylable: ['left', 'top', 'right', 'bottom', 'width', 'height', 'background-color', 'border-color'],
            },

            init() {
                this.on('change:tpl-width change:tpl-filled', this.applyGeometry);
                this.applyGeometry();
            },

            /**
             * A filled shape uses its own height; an unfilled one is a rule, and its
             * thickness IS its height. Writing both as styles keeps the export free of
             * anything the compositor has to interpret.
             */
            applyGeometry() {
                const thickness = this.get('tpl-width');
                const filled = Boolean(this.get('tpl-filled'));

                this.addStyle(
                    filled
                        ? { 'background-color': this.getStyle()['background-color'] || '#000000' }
                        : { height: thickness + 'mm', 'background-color': '#000000' },
                );
            },
        },
    });

    addBlock(editor, 'print-rule', config.strings['block.rule'], icons.minus);
}

/* ---------------------------------------------------------------------------
 * Shared
 * ------------------------------------------------------------------------ */

/** Style properties a text block may change. Deliberately short (spec 6.6). */
const TEXT_STYLES = [
    // Position first: an absolute block is placed by dragging, but a drag over a zoomed
    // canvas is imprecise, so `left`/`top` are also editable as exact millimetres in the
    // Position sector of the style panel (the sector's strings already existed). Right
    // and bottom too (CGE-51): the starting layouts anchor totals there.
    'left',
    'top',
    'right',
    'bottom',
    'font-size',
    'font-weight',
    'font-style',
    'text-align',
    'color',
    'line-height',
    'letter-spacing',
    'width',
    'height',
];

export const TRANSPARENT_PIXEL =
    'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

/*
 * The icon goes in `media` and the text in `label`, not both inside `label`.
 * GrapesJS renders `media` into `.gjs-block__media`, which is the element the chrome
 * styles as an icon pill; an `<i>` smuggled into the label is just inline text with
 * nowhere to be styled.
 */
/**
 * Name a block after the field it carries.
 *
 * Seventeen rows called "Campo de dados" tell the user nothing about which is which.
 * The binding's own label does.
 */
export function nameAfterBinding(component, config, fallback) {
    const key = component.getAttributes?.()['data-tpl-binding'] ?? '';
    const binding = findBinding(config, key);
    const name = binding?.label ?? fallback;

    if (name && component.get('custom-name') !== name) {
        component.set({ name, 'custom-name': name });
    }
}

function addBlock(editor, type, label, icon) {
    editor.BlockManager.add(type, {
        media: icon,
        label: escapeText(label),
        category: BLOCK_CATEGORY,
        content: { type },
    });
}

/**
 * A binding by key — a declared one first, and a calculated field of the graph
 * otherwise. Falling through to the graph is what lets a block on the canvas name and
 * preview a field nobody hand-declared; a calculated field carries no `legal` key at
 * all, so it is never mistaken for one.
 */
function findBinding(config, key) {
    return config.bindings.find((binding) => binding.key === key)
        ?? (config.nodes ?? []).flatMap((node) => node.fields ?? []).find((field) => field.key === key);
}

/**
 * The nodes offered as "Model" (spec 4.1).
 *
 * Two exclusions, both of them honesty rather than taste. A COLLECTION's node has no
 * meaning outside a row. And a node the graph reports UNREACHABLE has no path from the
 * document to its values — `linha_artigo` is the standing case, `article` being a relation
 * of a line and not of the document — so every one of its fields would print blank for
 * ever while looking perfectly functional in the preview and in validation.
 *
 * `reachable !== false` and not `reachable`: a payload from before the flag existed must
 * keep offering everything it used to.
 */
export function nodeOptionsOf(config) {
    return (config.nodes ?? [])
        .filter((node) => !node.collection && node.reachable !== false)
        .map((node) => ({ id: node.id, name: node.label }));
}

/** The fields of one node, in the shape the catalogue returns them. */
export function fieldsOfNode(config, nodeId) {
    return (config.nodes ?? []).find((node) => node.id === nodeId)?.fields ?? [];
}

/** Everything before the last dot: `adquirente.nif` belongs to `adquirente`. */
export function nodeOfBinding(key) {
    const lastDot = key.lastIndexOf('.');

    return lastDot === -1 ? key : key.slice(0, lastDot);
}

function escapeText(value) {
    const element = document.createElement('div');
    element.textContent = value;

    return element.innerHTML;
}

function escapeAttribute(value) {
    return value.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
}

/**
 * The "Várias linhas" switch of a text or a data field (CGE-52). An attribute, so it round
 * trips through the HTML; `print.css` turns it into wrapping inside the block's own box.
 */
function wrapTrait(config) {
    return {
        type: 'checkbox',
        name: 'data-tpl-wrap',
        label: config.strings['trait.wrap'],
        valueTrue: '1',
        valueFalse: '',
    };
}

/**
 * A block switched to several lines needs a box to break in: one with no width or height
 * gets the room to its band's right edge (at most 80mm — a one-word text's own width
 * would break after every word) and three lines of its own font, which the user adjusts.
 */
function sizeForWrap(component, config) {
    // A talão flows: its blocks already wrap in the roll's width and take the height
    // they need, so a fixed box would only make a long address run over the next block.
    if (component.getAttributes()['data-tpl-wrap'] !== '1' || config.geometry?.medium === 'roll') {
        return;
    }

    const style = component.getStyle();
    const el = component.getEl?.();
    const box = measuredBox(el);

    if (!box || (style.width && style.height)) {
        return;
    }

    const fontPx = parseFloat(el.ownerDocument.defaultView.getComputedStyle(el).fontSize) || 16;

    component.addStyle({
        ...(style.width ? {} : { width: roundTo(Math.max(box.width, Math.min(80, box.width + box.right)), 0.5) + 'mm' }),
        ...(style.height ? {} : { height: roundTo((3 * fontPx * 1.2) / PX_PER_MM, 0.5) + 'mm' }),
    });
}

/** A multi-line text box for a trait (the fixed text's content). */
function registerTextareaTrait(editor) {
    editor.TraitManager.addType('tpl-textarea', {
        createInput({ trait }) {
            const input = document.createElement('textarea');
            input.rows = 3;
            input.className = 'tpl-textarea';
            input.value = trait.getValue() ?? '';

            return input;
        },
    });
}
