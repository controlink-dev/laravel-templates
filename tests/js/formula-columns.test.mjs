import assert from 'node:assert/strict';
import {
    addFormulaColumn, columnsOverflow, freshFormulaColumns, isCalculatedColumn, isFormulaColumn, printedKeyOf,
    removeColumn, setColumnFormulaMode, defaultColumns, addLinkedColumn, relinkColumn, isRemovableColumn,
} from '../../resources/js/editor/formula-columns.js';
import { registerComponents } from '../../resources/js/editor/components.js';
import { columnQuickExamples } from '../../resources/js/editor/formula-names.js';
import { registerTableAndSlot } from '../../resources/js/editor/table.js';

/*
 * Formula columns of the line table (CGE-37): the pure helpers the columns editor and the
 * clone handler use, then the table's canvas driven through its own methods on a
 * stand-in for a GrapesJS component (see formula-field.test.mjs for the pattern).
 */

globalThis.document ??= {
    createElement: () => ({
        set textContent(value) {
            this.innerHTML = String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        },
    }),
};

const KEY = /^formula_[a-z][a-z0-9]{7}$/;
// The server's `FormulaSet::COLUMN_KEY`: what a `formulaKey` must look like.
const SERVER_KEY = /^formula_[a-z][a-z0-9]{5,11}$/;
const catalogue = [
    { key: 'quantity', label: 'Qty', format: 'number', formatArgs: {}, align: 'right', width: '' },
    { key: 'unit_price', label: 'Price', format: 'money', formatArgs: {}, align: 'right', width: '' },
];

// 1. Adding a calculated column: a fresh `formula_<id>` at the end, printed under itself
// (`key === formulaKey`), the default shape, input untouched.
{
    const before = structuredClone(catalogue);
    const columns = addFormulaColumn(catalogue, 'Fórmula');

    assert.deepEqual(catalogue, before, 'the given columns are not changed in place');
    assert.equal(columns.length, 3);
    assert.deepEqual(columns.slice(0, 2), catalogue);

    const added = columns[2];

    assert.match(added.key, KEY);
    assert.equal(added.formulaKey, added.key);
    assert.deepEqual({ ...added, key: 'x', formulaKey: 'x' }, {
        key: 'x', formulaKey: 'x', label: 'Fórmula', formula: '', format: '', formatArgs: {}, align: 'right', width: '20mm',
    });
    assert.ok(isFormulaColumn(added));
    assert.ok(isCalculatedColumn(added));
    assert.ok(!isCalculatedColumn(catalogue[0]));
    assert.ok(!isFormulaColumn(catalogue[0]));
    assert.ok(!isFormulaColumn(null));
}

// 1b. The visible columns' widths against the table's: a new formula column (20mm) added
// to columns that already fill a fixed-layout table would get no room at all, so the
// editor says so. Hidden columns do not count; a width it cannot read counts as nothing;
// a table whose width is unknown is never "too narrow".
{
    const full = [
        { key: 'a', width: '100mm' },
        { key: 'b', width: '80mm' },
        { key: 'c', width: '50mm', hidden: true },
    ];

    assert.equal(columnsOverflow(full, '180mm'), false, 'exactly the width');
    assert.equal(columnsOverflow(addFormulaColumn(full, 'F'), '180mm'), true);
    assert.equal(columnsOverflow([...full, { key: 'd', width: '' }], '180mm'), false, 'auto width: nothing to add up');
    assert.equal(columnsOverflow([{ key: 'a', width: '19' }, { key: 'b', width: '2cm' }], '38mm'), true, 'bare number is mm, cm converts');
    assert.equal(columnsOverflow(addFormulaColumn(full, 'F'), ''), false, 'unknown table width');
    assert.equal(columnsOverflow(addFormulaColumn(full, 'F'), '100%'), false, 'not a length in mm');
    assert.equal(columnsOverflow(addFormulaColumn(full, 'F'), undefined), false);
}

// 2. The key is unique among the given columns: a colliding id is drawn again.
{
    const ids = ['aaaaaaaa', 'aaaaaaaa', 'bbbbbbbb'];
    const existing = [...catalogue, { key: 'formula_aaaaaaaa', label: 'A', formula: '1' }];
    const columns = addFormulaColumn(existing, 'F', () => ids.shift());

    assert.equal(columns.at(-1).key, 'formula_bbbbbbbb');

    // A catalogue column's `formulaKey` is taken too.
    const taken = [{ key: 'quantity', formula: 'lines.quantity', formulaKey: 'formula_aaaaaaaa' }];
    const more = ['aaaaaaaa', 'cccccccc'];
    assert.equal(addFormulaColumn(taken, 'F', () => more.shift()).at(-1).key, 'formula_cccccccc');
}

// 2b. "Valor: Campo | Fórmula" on a catalogue column (spec §6.2). On: the source starts as
// the row's own value, a fresh `formulaKey` the server accepts, the rest kept. Off: the
// column is again byte for byte what it was (Review Focus 3): no `formulaKey: null`.
{
    const original = [
        { key: 'description', label: 'Descrição', width: '80mm', align: 'left', format: '', formatArgs: {} },
        { key: 'amount', label: 'Valor', width: '', align: 'right', format: 'money', formatArgs: { decimals: 2 } },
    ];
    const json = JSON.stringify(original);

    const on = setColumnFormulaMode(original, 0, true, () => 'dsc12345', 'lines');

    assert.equal(JSON.stringify(original), json, 'the given columns are not changed in place');
    assert.equal(on[0].formula, 'lines.description');
    assert.equal(on[0].formulaKey, 'formula_dsc12345');
    assert.match(on[0].formulaKey, SERVER_KEY);
    assert.equal(on[0].key, 'description', 'the column keeps its own key');
    assert.deepEqual({ ...on[0], formula: undefined, formulaKey: undefined }, { ...original[0], formula: undefined, formulaKey: undefined },
        'label, width, alignment and format stay');
    assert.equal(on[1], original[1]);
    assert.ok(isFormulaColumn(on[0]));
    assert.ok(!isCalculatedColumn(on[0]), 'a catalogue column in Formula is not removable');
    assert.equal(printedKeyOf(on[0]), 'formula_dsc12345');
    assert.equal(printedKeyOf(on[1]), 'amount');

    // Keeps its format in Formula (spec §8.1): only the two properties are added.
    const money = setColumnFormulaMode(original, 1, true, () => 'amt12345', 'lines');
    assert.equal(money[1].format, 'money');
    assert.deepEqual(money[1].formatArgs, { decimals: 2 });

    // Already on: nothing new — no fresh id, the source the user typed is kept.
    const typed = [{ ...on[0], formula: 'UPPER(lines.description)' }, on[1]];
    assert.equal(setColumnFormulaMode(typed, 0, true, () => 'zzzzzzzz', 'lines'), typed);

    // Off: exactly the original JSON, whatever the formula became.
    const off = setColumnFormulaMode(typed, 0, false);
    assert.equal(JSON.stringify(off), json);
    assert.ok(!('formulaKey' in off[0]) && !('formula' in off[0]));
    assert.equal(setColumnFormulaMode(original, 0, false), original, 'already a field: the same array');

    // The fresh key collides with nothing in the table.
    const ids = ['aaaaaaaa', 'bbbbbbbb'];
    const withCalc = [...original, { key: 'formula_aaaaaaaa', formulaKey: 'formula_aaaaaaaa', formula: '1' }];
    assert.equal(setColumnFormulaMode(withCalc, 0, true, () => ids.shift(), 'lines')[0].formulaKey, 'formula_bbbbbbbb');

    // A calculated column is always a formula: off changes nothing; so does a bad index.
    const calculated = addFormulaColumn(original, 'F', () => 'cccccccc');
    assert.equal(setColumnFormulaMode(calculated, 2, false), calculated);
    assert.equal(setColumnFormulaMode(calculated, 9, true), calculated);
    const legacy = [{ key: 'formula_dddddddd', formula: '1' }];
    assert.equal(setColumnFormulaMode(legacy, 0, false), legacy);
    assert.ok(isCalculatedColumn(legacy[0]), 'a column saved before formulaKey is calculated');
    assert.equal(printedKeyOf(legacy[0]), 'formula_dddddddd');

    // Hiding keeps the formula (spec §8.1): `hidden` is just another property.
    const hidden = [{ ...on[0], hidden: true }];
    assert.equal(hidden[0].formula, 'lines.description');
    assert.ok(isFormulaColumn(hidden[0]));
}

// 3. Removing: only a formula column goes; a catalogue column or a bad index changes nothing.
{
    const columns = [...catalogue, { key: 'formula_aaaaaaaa', label: 'A', formula: '1' }];

    assert.deepEqual(removeColumn(columns, 2), catalogue);
    assert.equal(columns.length, 3, 'the given columns are not changed in place');
    assert.deepEqual(removeColumn(columns, 0), columns, 'a catalogue column is only hidden, never removed');

    const inFormula = setColumnFormulaMode(columns, 0, true, () => 'qty12345', 'lines');
    assert.deepEqual(removeColumn(inFormula, 0), inFormula, 'not even in Formula');

    const calculated = addFormulaColumn(catalogue, 'F', () => 'eeeeeeee');
    assert.deepEqual(removeColumn(calculated, 2), catalogue);
    assert.deepEqual(removeColumn(columns, 7), columns);
    assert.deepEqual(removeColumn(columns, -1), columns);
}

// 4. A copied table: its formula keys that another table holds are renamed, the rest kept.
{
    const columns = [
        ...catalogue,
        { key: 'formula_aaaaaaaa', label: 'A', formula: '1' },
        { key: 'formula_cccccccc', label: 'C', formula: '2' },
    ];
    const ids = ['aaaaaaaa', 'dddddddd'];
    const renamed = freshFormulaColumns(columns, ['formula_aaaaaaaa', 'formula_zzzzzzzz'], () => ids.shift());

    assert.deepEqual(renamed.map((column) => column.key), ['quantity', 'unit_price', 'formula_dddddddd', 'formula_cccccccc']);
    assert.equal(renamed[2].formula, '1', 'only the key changes');
    assert.equal(columns[2].key, 'formula_aaaaaaaa', 'the given columns are not changed in place');
    assert.equal(freshFormulaColumns(columns, ['quantity']), columns, 'nothing to rename: the same array');

    // A calculated column renames key and formulaKey together; a catalogue column in
    // Formula only its formulaKey (its key is its field, shared by every copy).
    const modern = [
        { key: 'quantity', label: 'Qty', formula: 'lines.quantity', formulaKey: 'formula_aaaaaaaa' },
        { key: 'formula_bbbbbbbb', formulaKey: 'formula_bbbbbbbb', label: 'B', formula: '2' },
        { key: 'unit_price', label: 'Price' },
    ];
    const next = ['ffffffff', 'gggggggg'];
    const copy = freshFormulaColumns(modern, ['formula_aaaaaaaa', 'formula_bbbbbbbb', 'quantity'], () => next.shift());

    assert.deepEqual(copy[0], { ...modern[0], formulaKey: 'formula_ffffffff' });
    assert.deepEqual(copy[1], { ...modern[1], key: 'formula_gggggggg', formulaKey: 'formula_gggggggg' });
    assert.equal(copy[2], modern[2]);
    assert.equal(freshFormulaColumns(modern, ['quantity', 'unit_price']), modern, 'a shared field key is no collision');
}

// 4b. The quick examples of a column box (spec §6.4, controller ruling): built from the
// column itself, never from guessed roles. A text column: capitals and its first 40
// characters; a numeric one (its format, or the catalogue's): rounded, share of the
// total, blank when zero; a calculated column has no field of its own: none.
{
    const exampleConfig = {
        strings: { 'formula.quick.upper': 'Maiúsculas', 'formula.quick.left': 'Primeiros 40', 'formula.quick.round': 'Arredondar',
            'formula.quick.share': '% do total', 'formula.quick.zero_blank': 'Vazio quando é zero' },
        bindings: [{ key: 'lines', type: 'collection', columns: [
            { key: 'description', label: 'Descrição' },
            { key: 'quantity', label: 'Qty', default_format: 'number' },
            { key: 'issued', label: 'Data', default_format: 'date' },
        ] }],
    };
    const sources = (column) => columnQuickExamples(column, 'lines', exampleConfig).map((example) => [example.id, example.label, example.source]);

    assert.deepEqual(sources({ key: 'description', format: '' }), [
        ['upper', 'Maiúsculas', 'UPPER(lines.description)'],
        ['left', 'Primeiros 40', 'LEFT(lines.description, 40)'],
    ]);
    assert.deepEqual(sources({ key: 'description', format: 'text' }).map(([id]) => id), ['upper', 'left']);

    const numeric = [
        ['round', 'Arredondar', 'ROUND(lines.amount, 2)'],
        ['share', '% do total', 'IF(SUM(lines.amount) = 0, "", lines.amount / SUM(lines.amount) * 100)'],
        ['zero_blank', 'Vazio quando é zero', 'IF(lines.amount = 0, "", lines.amount)'],
    ];
    assert.deepEqual(sources({ key: 'amount', format: 'money' }), numeric);
    assert.deepEqual(sources({ key: 'amount', format: 'number' }).map(([id]) => id), ['round', 'share', 'zero_blank']);
    assert.deepEqual(sources({ key: 'quantity', format: '' }).map(([id]) => id), ['round', 'share', 'zero_blank'],
        'numeric by the catalogue even with no format chosen');

    // In Formula already: the examples still name the column's own field, not its formulaKey.
    assert.deepEqual(sources({ key: 'amount', format: 'money', formula: 'x', formulaKey: 'formula_aaaaaaaa' }), numeric);

    assert.deepEqual(sources({ key: 'issued', format: 'date' }), [], 'a date column: neither');
    assert.deepEqual(sources({ key: 'issued', format: '' }), [], 'a date by the catalogue: neither');
    assert.deepEqual(sources({ key: 'quantity', format: 'date' }), [], 'a numeric column switched to date: the chosen format wins');
    assert.deepEqual(sources({ key: 'issued', format: 'money' }).map(([id]) => id), ['round', 'share', 'zero_blank'], 'a date column switched to money gets numeric examples');
    assert.deepEqual(sources(addFormulaColumn([], 'F', () => 'aaaaaaaa')[0]), [], 'a calculated column has no field');
    assert.deepEqual(sources({ key: 'formula_bbbbbbbb', formula: '1' }), [], 'nor a column saved before formulaKey');
    assert.deepEqual(columnQuickExamples({ key: 'description' }, '', exampleConfig), [], 'no collection: nothing to name');
}

/* --------------------------------------------------------------- the line table */

const types = {};
const handlers = {};
const sheet = [];
const editor = {
    on: (event, handler) => { (handlers[event] ??= []).push(handler); },
    getWrapper: () => ({ getAttributes: () => ({}), components: () => sheet }),
    DomComponents: { addType: (name, definition) => { types[name] = definition; } },
    BlockManager: { add() {} },
    TraitManager: { addType() {} },
};

const strings = new Proxy({ 'formula.column_placeholder': 'ex.: :example', 'formula.label': 'Fórmula', 'formula.column_label': 'Fórmula', 'formula.unknown_field': 'Campo desconhecido: :name' }, {
    get: (target, key) => target[key] ?? String(key),
});

let answers = {};
let lines = [{ quantity: 2, unit_price: 5 }];
const asked = [];
const config = {
    strings,
    formats: ['money', 'number'],
    ruleWidths: {},
    bindings: [
        { key: 'lines', label: 'Lines', type: 'collection', columns: [
            { key: 'quantity', label: 'Qty', default_format: 'number' },
            { key: 'unit_price', label: 'Price', default_format: 'money' },
        ] },
        { key: 'other', label: 'Other', type: 'collection', columns: [{ key: 'name', label: 'Name' }] },
    ],
    nodes: [],
    codeTypes: {},
    fixture: { lines: [{ quantity: 1, unit_price: 9 }] },
    formatPreview: { text: (value, spec) => (spec ? `${spec.name}(${value})` : String(value ?? '')) },
    formulaPreview: {
        result: (source, collection, slot) => {
            asked.push([source, collection, slot]);

            return answers[source] ?? null;
        },
    },
};

registerComponents(editor, config);
registerTableAndSlot(editor, config);

// The canvas reads the preview rows through `previewRows`; the stand-in shows `lines`.
config.preview = { get rows() { return { lines }; } };

let cid = 0;

function make(attributes = {}) {
    const definition = types['line-table'].model;
    const listeners = {};
    const component = {
        cid: 't' + ++cid,
        props: { ...definition.defaults, attributes: undefined, type: 'line-table' },
        attrs: { ...definition.defaults.attributes, ...attributes },
        get(name) { return this.props[name]; },
        set(name, value) { this.props[name] = value; },
        on(event, handler) { (listeners[event] ??= []).push(handler.bind(this)); },
        trigger(event) { (listeners[event] ?? []).forEach((handler) => handler()); },
        getAttributes() { return { ...this.attrs }; },
        getAttrToHTML() { return { ...this.attrs }; },
        addAttributes(more) {
            const before = this.attrs;
            this.attrs = { ...this.attrs, ...more };
            Object.keys(more).forEach((key) => {
                if (before[key] !== this.attrs[key]) {
                    (listeners['change:attributes:' + key] ?? []).forEach((handler) => handler());
                }
            });
        },
        components: () => Object.assign([], { reset() {} }),
    };

    Object.entries(definition).forEach(([key, value]) => {
        if (typeof value === 'function') {
            component[key] = value;
        }
    });

    component.init();

    return component;
}

const cells = (html) => [...html.matchAll(/<td[^>]*>(.*?)<\/td>/g)].map((match) => match[1]);

// 5. A formula column on the canvas: label while there is no source or no answer yet.
{
    const table = make();
    table.setColumns(addFormulaColumn(table.columns(), 'Fórmula', () => 'aaaaaaaa'));

    assert.equal(cells(table.get('content'))[2], 'Fórmula', 'no source yet: the label');

    asked.length = 0;
    const columns = table.columns();
    columns[2].formula = 'lines.quantity * lines.unit_price';
    table.setColumns(columns);

    assert.deepEqual(asked.at(-1), ['lines.quantity * lines.unit_price', 'lines', table.cid + ':formula_aaaaaaaa'],
        'asked with the collection, one slot per column');
    assert.equal(cells(table.get('content'))[2], 'Fórmula', 'pending with nothing shown before: the label');

    // 6. The answer: the value in the column's format.
    answers = { 'lines.quantity * lines.unit_price': { value: 10, error: null } };
    columns[2].format = 'money';
    table.setColumns(columns);

    assert.equal(cells(table.get('content'))[2], 'money(10)');
    assert.deepEqual(table.formulaColumnStatus(table.columns()[2]), { state: 'result', text: 'money(10)' });

    // 7. Typing again: pending keeps the previous text, no flicker.
    columns[2].formula = 'lines.quantity * 3';
    table.setColumns(columns);

    assert.equal(cells(table.get('content'))[2], 'money(10)');
    assert.deepEqual(table.formulaColumnStatus(table.columns()[2]), { state: 'pending', text: '' });

    // 8. An error is a warning, escaped.
    answers['lines.quantity * 3'] = { value: '', error: 'bad <x>' };
    table.syncPreview();

    assert.equal(cells(table.get('content'))[2], '<span class="tpl-formula-error">bad &lt;x&gt;</span>');

    // 9. The saved text never depends on the server: the label, and nothing asked.
    asked.length = 0;
    assert.equal(cells(table.previewContent(true))[2], 'Fórmula');
    assert.equal(asked.length, 0, 'the example (save/dirty) read never asks the server');

    // 10. Real lines: the server evaluates on the first one only; the others show a dimmed
    // `…`, not the label, which would read as data.
    lines = [{ quantity: 2, unit_price: 5 }, { quantity: 3, unit_price: 1 }];
    answers['lines.quantity * 3'] = { value: 6, error: null };
    table.syncPreview();

    const all = cells(table.get('content'));
    assert.equal(all[2], 'money(6)');
    assert.equal(all[5], '<span class="tpl-formula-more">…</span>');

    // The saved example keeps the label on its single row.
    assert.equal(cells(table.previewContent(true))[2], 'Fórmula');
    lines = [{ quantity: 2, unit_price: 5 }];

    // 11. The trait hears about every redraw, so its status lines can follow the answers.
    let heard = 0;
    table.__pfRepaintFormulaStatus = () => heard++;
    table.syncPreview();
    assert.equal(heard, 1);

    // 12. Another collection: the formula columns go with the old one.
    table.addAttributes({ 'data-tpl-collection': 'other' });
    assert.deepEqual(table.columns().map((column) => column.key), ['name']);
}

// 13. An empty source asks nothing.
{
    const table = make();
    asked.length = 0;
    table.setColumns(addFormulaColumn(table.columns(), 'Fórmula'));

    assert.equal(asked.length, 0);
    assert.deepEqual(table.formulaColumnStatus(table.columns()[2]), { state: 'empty', text: '' });
}

// 14. A copied table: a formula key another table holds is renamed, in one write.
{
    const original = make();
    original.setColumns(addFormulaColumn(original.columns(), 'Fórmula', () => 'aaaaaaaa'));
    sheet.push(original);

    const copy = make({ 'data-tpl-columns': original.getAttributes()['data-tpl-columns'] });
    handlers['component:clone'].forEach((handler) => handler(copy));

    const key = copy.columns()[2].key;
    assert.match(key, KEY);
    assert.notEqual(key, 'formula_aaaaaaaa');
    assert.equal(original.columns()[2].key, 'formula_aaaaaaaa', 'the original keeps its key');

    // A table whose formula keys nobody else holds is left alone.
    const alone = make();
    alone.setColumns(addFormulaColumn(alone.columns(), 'Fórmula', () => 'bbbbbbbb'));
    const before = alone.getAttributes()['data-tpl-columns'];
    handlers['component:clone'].forEach((handler) => handler(alone));
    assert.equal(alone.getAttributes()['data-tpl-columns'], before);
    sheet.length = 0;
}

// 15. A catalogue column in Formula on the canvas: the result in place of the value on the
// first row, `…` on the others, asked under its formulaKey; the saved example never asks.
{
    const table = make();
    lines = [{ quantity: 2, unit_price: 5 }, { quantity: 3, unit_price: 1 }];
    table.setColumnFormulaMode(0, true);

    const column = table.columns()[0];
    assert.equal(column.key, 'quantity');
    assert.equal(column.formula, 'lines.quantity');
    assert.match(column.formulaKey, SERVER_KEY);

    answers['lines.quantity'] = { value: 42, error: null };
    asked.length = 0;
    table.syncPreview();

    assert.deepEqual(asked.at(-1), ['lines.quantity', 'lines', table.cid + ':' + column.formulaKey]);
    const all = cells(table.get('content'));
    assert.equal(all[0], 'number(42)', 'the result, in the column\'s own format');
    assert.equal(all[2], '<span class="tpl-formula-more">…</span>');
    assert.equal(all[1], 'money(5)', 'the other columns keep their values');

    asked.length = 0;
    assert.equal(cells(table.previewContent(true))[0], 'Qty');
    assert.equal(asked.length, 0);

    // 16. toHTML prints `linha.<formulaKey>` for the column in Formula, `linha.<key>` for
    // the others, and a hidden one not at all (its formula is kept).
    let html = table.toHTML();
    assert.ok(html.includes('{{ linha.' + column.formulaKey + '|number'), html);
    assert.ok(!html.includes('{{ linha.quantity'), html);
    assert.ok(html.includes('{{ linha.unit_price'), html);

    table.setColumns(addFormulaColumn(table.columns(), 'Fórmula', () => 'hhhhhhhh'));
    assert.ok(table.toHTML().includes('{{ linha.formula_hhhhhhhh }}'));

    const hidden = table.columns().map((entry, at) => (at === 0 ? { ...entry, hidden: true } : entry));
    table.setColumns(hidden);
    html = table.toHTML();
    assert.ok(!html.includes(column.formulaKey + '|'), 'a hidden column does not print');
    assert.equal(table.columns()[0].formula, 'lines.quantity', 'but keeps its formula');

    // 17. Back to Field: the column as it was, printed from its own key.
    table.setColumns(hidden.map((entry, at) => {
        if (at !== 0) {
            return entry;
        }

        const { hidden: _hidden, ...rest } = entry;

        return rest;
    }));
    table.setColumnFormulaMode(0, false);
    assert.equal(JSON.stringify(table.columns()[0]), JSON.stringify(table.defaultColumns()[0]));
    assert.ok(table.toHTML().includes('{{ linha.quantity|number'));
    lines = [{ quantity: 2, unit_price: 5 }];
}

// 18. The box shows readable names and stores keys; a name that matches nothing is kept as
// typed and said locally, without asking the server (spec §5).
{
    const table = make();
    table.setColumns(addFormulaColumn(table.columns(), 'Fórmula', () => 'iiiiiiii'));

    table.setColumnFormulaDisplay(2, '[Qty] * [Price] + SUM([Lines · Price])');
    assert.equal(table.columns()[2].formula, 'lines.quantity * lines.unit_price + SUM(lines.unit_price)');
    assert.equal(table.columnFormulaDisplay(table.columns()[2]), '[Qty] * [Price] + SUM([Lines · Price])');
    assert.ok(!table.getAttributes()['data-tpl-columns'].includes('[Qty]'));

    asked.length = 0;
    table.setColumnFormulaDisplay(2, '[Nada] * 2');
    assert.equal(table.columns()[2].formula, '[Nada] * 2');
    assert.deepEqual(table.formulaColumnStatus(table.columns()[2]), { state: 'error', text: 'Campo desconhecido: [Nada]' });
    assert.equal(asked.length, 0, 'never asked');

    // The same text again writes nothing.
    const before = table.getAttributes()['data-tpl-columns'];
    let writes = 0;
    table.on('change:attributes:data-tpl-columns', () => writes++);
    table.setColumnFormulaDisplay(2, '[Nada] * 2');
    assert.equal(writes, 0);
    assert.equal(table.getAttributes()['data-tpl-columns'], before);
}

// 18b. The box's placeholder is the column's own first example, by name; a table with no
// collection has no switch to use (V-19 refuses a formula column there).
{
    const table = make();
    const columns = table.columns();

    assert.equal(table.columnPlaceholder(columns[0]), 'ex.: ROUND([Qty], 2)');
    assert.equal(table.columnPlaceholder(addFormulaColumn(columns, 'F', () => 'jjjjjjjj')[2]), '');
    assert.deepEqual(table.columnQuickExamples(columns[1]).map((example) => example.id), ['round', 'share', 'zero_blank']);

    table.addAttributes({ 'data-tpl-collection': '' });
    table.setColumns([{ key: 'name', label: 'Name', format: '' }]);
    const before = table.getAttributes()['data-tpl-columns'];
    table.setColumnFormulaMode(0, true);
    assert.equal(table.getAttributes()['data-tpl-columns'], before, 'no collection: no formula');
}

// 19. Opening a saved table with formula columns rewrites nothing (Review Focus 5): a
// catalogue column in Formula and a column saved before formulaKey existed.
{
    const saved = JSON.stringify([
        { key: 'quantity', label: 'Qty', width: '', align: 'right', format: 'number', formatArgs: {}, formula: 'lines.quantity * 2', formulaKey: 'formula_qqqqqqqq' },
        { key: 'formula_llllllll', label: 'Old', formula: 'lines.unit_price', format: '', formatArgs: {}, align: 'right', width: '20mm' },
    ]);
    const table = make({ 'data-tpl-columns': saved });

    assert.equal(table.getAttributes()['data-tpl-columns'], saved);
    asked.length = 0;
    const example = table.previewContent(true);
    assert.equal(asked.length, 0);
    assert.deepEqual(cells(example), ['Qty', 'Old']);
    assert.ok(table.toHTML().includes('{{ linha.formula_qqqqqqqq|number'));
    assert.ok(table.toHTML().includes('{{ linha.formula_llllllll }}'));
}

// 20. A copied table with a catalogue column in Formula: its formulaKey is renamed, its key kept.
{
    const original = make();
    original.setColumnFormulaMode(1, true);
    sheet.push(original);

    const copy = make({ 'data-tpl-columns': original.getAttributes()['data-tpl-columns'] });
    handlers['component:clone'].forEach((handler) => handler(copy));

    assert.equal(copy.columns()[1].key, 'unit_price');
    assert.match(copy.columns()[1].formulaKey, SERVER_KEY);
    assert.notEqual(copy.columns()[1].formulaKey, original.columns()[1].formulaKey);
    sheet.length = 0;
}

// 21. CGE-85: linked columns. The catalogue offers, for a document collection, its own
// columns, the display field of each link (a default card) and the link's other fields
// (`optional`, offered by "Add column" only).
{
    const binding = {
        key: 'lines',
        columns: [
            { key: 'description', label: 'Descrição', default_format: '' },
            { key: 'amount', label: 'Valor', default_format: 'money' },
            { key: 'family.name', label: 'Família', default_format: '', linked: { alias: 'family', label: 'Família', field: 'name', field_label: 'Nome' } },
            { key: 'family.code', label: 'Família › Código', default_format: '', linked: { alias: 'family', label: 'Família', field: 'code', field_label: 'Código' }, optional: true },
            { key: 'unit.code', label: 'Unidade › Código', default_format: '', linked: { alias: 'unit', label: 'Unidade', field: 'code', field_label: 'Código' }, optional: true },
            { key: 'family.discount', label: 'Família › Desconto', default_format: 'number', linked: { alias: 'family', label: 'Família', field: 'discount', field_label: 'Desconto' }, optional: true },
        ],
    };

    // defaultColumns: every catalogue column but the optional ones, in its order, shaped as always.
    const defaults = defaultColumns(binding);
    assert.deepEqual(defaults.map((column) => column.key), ['description', 'amount', 'family.name']);
    assert.deepEqual(defaults[1], { key: 'amount', label: 'Valor', width: '', align: 'right', format: 'money', formatArgs: { decimals: null, symbol: 'after' } });
    assert.deepEqual(defaults[2], { key: 'family.name', label: 'Família', width: '', align: 'left', format: '', formatArgs: {} });
    assert.deepEqual(defaultColumns(undefined), []);
    assert.ok(defaults.every((column) => !isRemovableColumn(column)), 'catalogue columns are hidden, never removed');

    // addLinkedColumn: at the end, the default shape plus `origin: 'added'` and a width of its own.
    const before = JSON.stringify(defaults);
    const added = addLinkedColumn(defaults, binding.columns[5]);
    assert.equal(JSON.stringify(defaults), before, 'the given columns are not changed in place');
    assert.equal(added.length, 4);
    assert.deepEqual(added.slice(0, 3), defaults);
    assert.deepEqual(added[3], {
        key: 'family.discount', label: 'Família › Desconto', width: '20mm', align: 'right', format: 'number', formatArgs: { decimals: 2 }, origin: 'added',
    });
    assert.ok(isRemovableColumn(added[3]));
    assert.ok(isRemovableColumn(addFormulaColumn([], 'F').at(-1)), 'a calculated column too');

    // An added column is removed with the trash, like a calculated one.
    assert.deepEqual(removeColumn(added, 3), defaults);
    assert.deepEqual(removeColumn(added, 2), added, 'the catalogue display column stays');

    // relinkColumn: the key changes; width, alignment, format, hidden and origin stay.
    const tuned = added.map((column, at) => at === 3 ? { ...column, width: '31', align: 'center', hidden: true } : column);
    const relinked = relinkColumn(tuned, 3, binding.columns[3], binding.columns[5]);
    assert.deepEqual(relinked[3], { ...tuned[3], key: 'family.code', label: 'Família › Código' }, 'default label follows the field');
    assert.equal(tuned[3].key, 'family.discount', 'the given columns are not changed in place');

    // A label the user wrote is theirs: kept.
    const renamed = tuned.map((column, at) => at === 3 ? { ...column, label: 'Desc. fam.' } : column);
    assert.equal(relinkColumn(renamed, 3, binding.columns[3], binding.columns[5])[3].label, 'Desc. fam.');
    assert.equal(relinkColumn(renamed, 3, binding.columns[3], binding.columns[5])[3].key, 'family.code');
    // Without the old entry nothing says the label is a default: kept.
    assert.equal(relinkColumn(tuned, 3, binding.columns[3])[3].label, 'Família › Desconto');

    // The default card of a link relinks too (it is not removable, only hidden).
    const display = relinkColumn(defaults, 2, binding.columns[3], binding.columns[2]);
    assert.deepEqual(display[2], { ...defaults[2], key: 'family.code', label: 'Família › Código' });
    assert.ok(!isRemovableColumn(display[2]));

    // Nothing to do: no column there, or a column in Formula (its source names the old key).
    assert.equal(relinkColumn(defaults, 9, binding.columns[3]), defaults);
    const inFormula = setColumnFormulaMode(defaults, 2, true, () => 'fam12345', 'lines');
    assert.equal(relinkColumn(inFormula, 2, binding.columns[3], binding.columns[2]), inFormula);
}

// CGE-89. On the canvas a text column's cells wrap like in the PDF; a formatted one stays on one line.
{
    const table = make();
    table.setColumns([
        { key: 'name', label: 'Name', format: '' },
        { key: 'unit_price', label: 'Price', format: 'money' },
    ]);

    const tds = [...table.get('content').matchAll(/<td[^>]*>/g)].map((match) => match[0]);

    assert.match(tds[0], /class="tpl-wrap"/);
    assert.doesNotMatch(tds[1], /tpl-wrap/);
    assert.doesNotMatch(table.toHTML(), /tpl-wrap/, 'canvas only: the saved template is unchanged');
}

console.log('formula-columns: ok');
