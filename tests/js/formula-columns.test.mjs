import assert from 'node:assert/strict';
import {
    addFormulaColumn, freshFormulaColumns, isFormulaColumn, removeColumn,
} from '../../resources/js/editor/formula-columns.js';
import { registerComponents } from '../../resources/js/editor/components.js';
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

const KEY = /^formula_[a-z0-9]{8}$/;
const catalogue = [
    { key: 'quantity', label: 'Qty', format: 'number', formatArgs: {}, align: 'right', width: '' },
    { key: 'unit_price', label: 'Price', format: 'money', formatArgs: {}, align: 'right', width: '' },
];

// 1. Adding: a fresh `formula_<id>` column at the end, the default shape, input untouched.
{
    const before = structuredClone(catalogue);
    const columns = addFormulaColumn(catalogue, 'Fórmula');

    assert.deepEqual(catalogue, before, 'the given columns are not changed in place');
    assert.equal(columns.length, 3);
    assert.deepEqual(columns.slice(0, 2), catalogue);

    const added = columns[2];

    assert.match(added.key, KEY);
    assert.deepEqual({ ...added, key: 'x' }, {
        key: 'x', label: 'Fórmula', formula: '', format: '', formatArgs: {}, align: 'right', width: '',
    });
    assert.ok(isFormulaColumn(added));
    assert.ok(!isFormulaColumn(catalogue[0]));
    assert.ok(!isFormulaColumn(null));
}

// 2. The key is unique among the given columns: a colliding id is drawn again.
{
    const ids = ['aaaaaaaa', 'aaaaaaaa', 'bbbbbbbb'];
    const existing = [...catalogue, { key: 'formula_aaaaaaaa', label: 'A', formula: '1' }];
    const columns = addFormulaColumn(existing, 'F', () => ids.shift());

    assert.equal(columns.at(-1).key, 'formula_bbbbbbbb');
}

// 3. Removing: only a formula column goes; a catalogue column or a bad index changes nothing.
{
    const columns = [...catalogue, { key: 'formula_aaaaaaaa', label: 'A', formula: '1' }];

    assert.deepEqual(removeColumn(columns, 2), catalogue);
    assert.equal(columns.length, 3, 'the given columns are not changed in place');
    assert.deepEqual(removeColumn(columns, 0), columns, 'a catalogue column is only hidden, never removed');
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

const strings = new Proxy({ 'formula.label': 'Fórmula', 'formula.column_label': 'Fórmula' }, {
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

console.log('formula-columns: ok');
