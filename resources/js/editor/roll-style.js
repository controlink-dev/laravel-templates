/*
 * A receipt's styling (CGE-97): on a roll the editor offers only what a thermal printer
 * can do — the letter (normal or double), bold, the alignment and blank lines before —
 * and writes it only as the receipt's `esc-*` classes (print.css, Task 3), never as a
 * style. And the «Linha com colunas» row: a label and a value side by side, each cell
 * `data-tpl-cols` characters wide (0 = the rest of the line).
 *
 * Everything here is free of GrapesJS so it can be tested in plain node; the editor
 * (components.js, editor.js, panels.js) wires it in. On a sheet none of it applies.
 */

const SIZES = ['normal', 'double'];
const ALIGNMENTS = ['left', 'center', 'right'];
const GAPS = [0, 1, 2, 3];

/** The classes the four traits own; every other class (`flow`, `esc-cell`, `esc-nowrap`) is left alone. */
export const ROLL_STYLE_CLASSES = Object.freeze([
    'esc-double', 'esc-bold', 'esc-left', 'esc-center', 'esc-right', 'esc-gap-1', 'esc-gap-2', 'esc-gap-3',
]);

/** The block types that show the four traits on a roll. */
export const ROLL_STYLED_TYPES = Object.freeze(['static-text', 'data-field', 'page-number']);

/** The types a «Linha com colunas» holds. */
export const ROW_CELL_TYPES = Object.freeze(['static-text', 'data-field']);

/** The names of the traits this file adds, in the order they are shown. */
export const ROLL_TRAIT_NAMES = Object.freeze(['tpl-esc-size', 'tpl-esc-bold', 'tpl-esc-align', 'tpl-esc-gap']);

export const COLS_TRAIT = 'data-tpl-cols';

/**
 * The `esc-*` classes of a state. Left is the printer's default and is written as nothing,
 * as are a normal letter and no gap.
 *
 * @param {{size?: string, bold?: boolean, align?: string, gap?: number}} state
 * @returns {string[]}
 */
export function classesFor({ size = 'normal', bold = false, align = 'left', gap = 0 } = {}) {
    const classes = [];

    if (size === 'double') {
        classes.push('esc-double');
    }

    if (bold) {
        classes.push('esc-bold');
    }

    if (align === 'center' || align === 'right') {
        classes.push('esc-' + align);
    }

    const blank = Number(gap);

    if (GAPS.includes(blank) && blank > 0) {
        classes.push('esc-gap-' + blank);
    }

    return classes;
}

/**
 * The state a list of classes describes: the exact inverse of `classesFor`.
 *
 * @param {Iterable<string>|string} classes
 * @returns {{size: 'normal'|'double', bold: boolean, align: 'left'|'center'|'right', gap: number}}
 */
export function stateOf(classes) {
    const names = new Set(typeof classes === 'string' ? classes.split(/\s+/) : [...(classes ?? [])]);
    const align = ALIGNMENTS.find((side) => side !== 'left' && names.has('esc-' + side)) ?? 'left';
    const gap = [...GAPS].reverse().find((blank) => blank > 0 && names.has('esc-gap-' + blank)) ?? 0;

    return { size: names.has('esc-double') ? 'double' : 'normal', bold: names.has('esc-bold'), align, gap };
}

/**
 * The classes with the four traits' part replaced by `patch` applied to the current state:
 * the classes not owned by the traits stay, in their order, and the new ones follow.
 */
export function withRollState(classes, patch) {
    const current = [...(classes ?? [])];
    const state = { ...stateOf(current), ...patch };

    return [...current.filter((name) => !ROLL_STYLE_CLASSES.includes(name)), ...classesFor(state)];
}

/**
 * A cell's classes inside a «Linha com colunas» (`inRow`) or out of one. In a row it is a
 * flow cell (`flow esc-cell`, not an absolutely placed `block`); out of one it loses
 * `esc-cell`. Nothing else changes.
 */
export function cellClasses(classes, inRow) {
    const current = [...(classes ?? [])];

    if (!inRow) {
        return current.filter((name) => name !== 'esc-cell');
    }

    const kept = current.filter((name) => name !== 'block' && !name.startsWith('block--') && name !== 'flow' && name !== 'esc-cell');

    return ['flow', 'esc-cell', ...kept];
}

/** A cell's width in characters: a whole number from 0 (the rest of the line) to the grid's. */
export function normalizeCols(value, columns) {
    const text = String(value ?? '').trim();
    const count = /^\d+$/.test(text) ? Number(text) : 0;
    const most = Number(columns) > 0 ? Math.floor(Number(columns)) : count;

    return String(Math.min(count, most));
}

/* ---------------------------------------------------------------------------
 * Markup
 * ------------------------------------------------------------------------ */

const ROW_ATTRIBUTES = { class: 'flow esc-row', 'data-tpl-type': 'text-row' };

/**
 * A «Linha com colunas»: the row wrapping its cells' own HTML. What the `text-row`
 * component exports, and (with `cellHtml`) what its block drops.
 *
 * @param {string[]} cells       each cell's HTML, as the cell exports it
 * @param {object}   attributes  the row's attributes, in order (default: the row's own)
 */
export function textRowHtml(cells, attributes = ROW_ATTRIBUTES) {
    return `<div${attributesHtml(attributes)}>${(cells ?? []).join('')}</div>`;
}

/**
 * One cell of a row: `class`, `data-tpl-type`, the cell's other attributes, its width and
 * its id, in that order.
 *
 * @param {{type: string, classes?: string[], attributes?: object, cols?: string|number, id?: string, html?: string}} cell
 */
export function cellHtml({ type, classes = [], attributes = {}, cols = 0, id, html = '' }) {
    const ordered = {
        class: ['flow', 'esc-cell', ...classes.filter((name) => name !== 'flow' && name !== 'esc-cell')].join(' '),
        'data-tpl-type': type,
        ...attributes,
        [COLS_TRAIT]: String(cols),
        ...(id ? { id } : {}),
    };

    return `<div${attributesHtml(ordered)}>${html}</div>`;
}

/** What the «Linha com colunas» block drops: an empty label and an unset value, half and half. */
export function textRowBlockHtml() {
    return textRowHtml([
        cellHtml({ type: 'static-text', cols: 0 }),
        cellHtml({ type: 'data-field', classes: ['esc-right', 'esc-nowrap'], cols: 0 }),
    ]);
}

function attributesHtml(attributes) {
    return Object.entries(attributes ?? {})
        .filter(([, value]) => value !== null && value !== undefined)
        .map(([name, value]) => ` ${name}="${String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;')}"`)
        .join('');
}

/* ---------------------------------------------------------------------------
 * Traits
 * ------------------------------------------------------------------------ */

/**
 * The four traits, reading and writing only classes. `getValue`/`setValue` are GrapesJS
 * (0.23) trait callbacks: no attribute and no model property is written, so nothing but
 * the classes reaches the saved layout. Bold is `tpl-esc-check` (components.js), a
 * checkbox drawn from the classes; the built-in checkbox reads its first state from an
 * attribute this trait does not have.
 */
export function rollStyleTraits(strings) {
    const read = (key) => ({ component }) => {
        const value = stateOf(component.getClasses())[key];

        return typeof value === 'boolean' ? value : String(value);
    };
    const write = (key, cast) => ({ component, value }) => setRollState(component, { [key]: cast(value) });

    return [
        {
            type: 'select',
            name: 'tpl-esc-size',
            label: strings['trait.esc_size'],
            options: [
                { id: 'normal', name: strings['esc.size_normal'] },
                { id: 'double', name: strings['esc.size_double'] },
            ],
            getValue: read('size'),
            setValue: write('size', (value) => (SIZES.includes(value) ? value : 'normal')),
        },
        {
            type: 'tpl-esc-check',
            name: 'tpl-esc-bold',
            label: strings['trait.esc_bold'],
            getValue: read('bold'),
            setValue: write('bold', (value) => value === true || value === 'true' || value === '1'),
        },
        {
            type: 'select',
            name: 'tpl-esc-align',
            label: strings['trait.esc_align'],
            options: ALIGNMENTS.map((side) => ({ id: side, name: strings['align.' + side] })),
            getValue: read('align'),
            setValue: write('align', (value) => (ALIGNMENTS.includes(value) ? value : 'left')),
        },
        {
            type: 'select',
            name: 'tpl-esc-gap',
            label: strings['trait.esc_gap'],
            options: GAPS.map((blank) => ({ id: String(blank), name: String(blank) })),
            getValue: read('gap'),
            setValue: write('gap', (value) => (GAPS.includes(Number(value)) ? Number(value) : 0)),
        },
    ];
}

/** «Largura (caracteres)» of a row's cell: an attribute, so it round-trips through the HTML. */
export function colsTrait(strings, columns) {
    return {
        type: 'number',
        name: COLS_TRAIT,
        label: strings['trait.esc_cols'],
        min: 0,
        max: columns,
        step: 1,
    };
}

/** Write a state patch as classes, touching the component only when they change. */
export function setRollState(component, patch) {
    const current = component.getClasses();
    const next = withRollState(current, patch);

    if (next.join(' ') !== current.join(' ')) {
        component.setClass(next);
    }
}

/**
 * Put a component's roll traits in step with the medium: on a roll a styled block has the
 * four, a row's cell also its width; on a sheet none of them. Safe to call again.
 *
 * @param {object} component a GrapesJS component (getTrait/addTrait/removeTrait/getClasses)
 * @param {boolean} roll
 * @param {object} strings
 * @param {number} columns the roll's grid width
 */
export function syncRollTraits(component, roll, strings, columns) {
    const type = component.getAttributes?.()['data-tpl-type'] ?? component.get?.('type');
    const styled = roll && ROLL_STYLED_TYPES.includes(type);
    const cell = roll && ROW_CELL_TYPES.includes(type) && component.getClasses().includes('esc-cell');

    if (styled) {
        rollStyleTraits(strings)
            .filter((trait) => !component.getTrait(trait.name))
            .forEach((trait) => component.addTrait(trait));
    } else {
        ROLL_TRAIT_NAMES.filter((name) => component.getTrait(name)).forEach((name) => component.removeTrait(name));
    }

    if (cell && !component.getTrait(COLS_TRAIT)) {
        component.addTrait(colsTrait(strings, columns));
    } else if (!cell && component.getTrait(COLS_TRAIT)) {
        component.removeTrait(COLS_TRAIT);
    }
}

/* ---------------------------------------------------------------------------
 * The rest of a roll's editing
 * ------------------------------------------------------------------------ */

/** The types whose size a receipt may set (V-21 / L-ESC: an image or the code slot, in mm). */
const SIZED_TYPES = ['code-slot', 'image'];

/**
 * What «Repor na folha» writes on a roll: a block flows, so it is never moved; an image or
 * the code slot wider than the printable width is narrowed to it, the one size a receipt
 * may set. Any other block gets nothing — a width on it would be a style the printer
 * cannot print (V-21).
 *
 * @returns {object} the style to add, possibly empty
 */
export function rollSnapStyle({ type, tagName, widthMm, printableWidthMm, toleranceMm = 0 }) {
    const sized = tagName === 'img' || SIZED_TYPES.includes(type);

    if (!sized || !(widthMm > printableWidthMm + toleranceMm)) {
        return {};
    }

    return { width: Math.round(printableWidthMm * 10) / 10 + 'mm' };
}

/**
 * An L-ESC problem as the problems panel lists it: the server's own V-21 message for its
 * key (`validation.roll_*`, passed in as `check.roll_*`), else the generic one.
 */
export function rollMessage(problem, strings, columns) {
    const template = strings['check.' + problem.key] || strings['check.roll_vocabulary'] || '';

    return String(template)
        .replace(/:subject/g, String(problem.subject ?? ''))
        .replace(/:columns/g, String(columns));
}
