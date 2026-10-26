import assert from 'node:assert/strict';
import { registerComponents } from '../../resources/js/editor/components.js';
import { VALUE_MODE_TRAIT, createFormulaPreview, refreshFormulaTraits } from '../../resources/js/editor/formula.js';
import { plainProjectData } from '../../resources/js/editor/preview-filter.js';

/*
 * The data field's "Valor: Campo | Fórmula" (CGE-37), driven through its own methods on a
 * stand-in for a GrapesJS component. The stand-in reproduces the one GrapesJS behaviour
 * the field's event wiring depends on: `setAttributes` replaces the whole object and fires
 * `change:attributes:<key>` for every key that differs — added, changed or removed.
 */

globalThis.document ??= {
    createElement: () => ({
        set textContent(value) {
            this.innerHTML = String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        },
    }),
};

const types = {};
const handlers = {};
const sheet = [];

const editor = {
    DomComponents: { addType: (name, definition) => { types[name] = definition; } },
    BlockManager: { add: () => {} },
    TraitManager: { addType: () => {} },
    on: (event, handler) => { (handlers[event] ??= []).push(handler); },
    getWrapper: () => ({ getAttributes: () => ({}), components: () => sheet }),
    // GrapesJS replays an undo/redo with a plain `set`, flagging only its stack.
    UndoManager: { stack: { isCurrentlyUndoRedoing: false }, getStack() { return this.stack; } },
};

/** Replays a recorded attribute state the way GrapesJS's undo/redo does. */
function replay(component, attributes) {
    editor.UndoManager.stack.isCurrentlyUndoRedoing = true;

    try {
        component.setAttributes(attributes);
    } finally {
        editor.UndoManager.stack.isCurrentlyUndoRedoing = false;
    }
}

const strings = new Proxy({
    'formula.label': 'Fórmula',
    'formula.unknown_field': 'Campo desconhecido: :name',
}, { get: (target, key) => target[key] ?? String(key) });

let answers = {};
const asked = [];
const config = {
    strings,
    formats: ['money'],
    ruleWidths: {},
    bindings: [
        { key: 'doc.title', label: 'Title', legal: true, mandatory: true },
        { key: 'doc.number', label: 'Number', mandatory: true },
        { key: 'lines', label: 'Lines', type: 'collection', columns: [{ key: 'amount', label: 'Amount', default_format: 'money' }] },
    ],
    nodes: [{
        id: 'doc',
        label: 'Document',
        fields: [
            { key: 'doc.title', label: 'Title', legal: true },
            { key: 'doc.total', label: 'Total', type: 'money' },
            { key: 'doc.number', label: 'Number' },
            { key: 'doc.due', label: 'Due', type: 'date' },
        ],
    }],
    fixture: { 'doc.total': 10 },
    formatPreview: { text: (value, spec) => (spec ? `${spec.name}(${value})` : String(value ?? '')) },
    formulaPreview: { result: (source, collection, slot) => { asked.push([source, slot]); return answers[source] ?? null; } },
};

registerComponents(editor, config);

let cid = 0;

function make(attributes = {}) {
    const definition = types['data-field'].model;
    const listeners = {};
    const component = {
        cid: 'c' + ++cid,
        props: { ...definition.defaults, attributes: undefined, type: 'data-field' },
        attrs: { ...definition.defaults.attributes, ...attributes },
        traits: definition.defaults.traits.map((trait) => ({ ...trait })),
        get(name) { return this.props[name]; },
        set(name, value) {
            if (typeof name === 'object') {
                Object.assign(this.props, name);
            } else {
                this.props[name] = value;
            }
        },
        unset(name) { delete this.props[name]; },
        initToolbar() {},
        on(event, handler) { (listeners[event] ??= []).push(handler.bind(this)); },
        getAttributes() { return { ...this.attrs }; },
        setAttributes(next) {
            const before = this.attrs;
            this.attrs = { ...next };
            const keys = new Set([...Object.keys(before), ...Object.keys(this.attrs)]);

            keys.forEach((key) => {
                if (before[key] !== this.attrs[key]) {
                    (listeners['change:attributes:' + key] ?? []).forEach((handler) => handler());
                }
            });
        },
        addAttributes(more) { this.setAttributes({ ...this.attrs, ...more }); },
        getTrait(name) {
            const trait = this.traits.find((entry) => entry.name === name);

            return trait ? { ...trait, get: (key) => trait[key], set: (key, value) => { trait[key] = value; } } : undefined;
        },
        addTrait(trait, { at } = {}) { this.traits.splice(at ?? this.traits.length, 0, { ...trait }); },
        removeTrait(name) { this.traits = this.traits.filter((entry) => entry.name !== name); },
        traitNames() { return this.traits.map((trait) => trait.name); },
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

const FORMULA_KEY = /^formula\.[a-z][a-z0-9]{7}$/;

// 1. Campo → Fórmula: a fresh key, the box starting with the field it had, the selects
//    replaced by the box. The attribute holds the key, the box shows the name (spec §6.1).
{
    sheet.length = 0;
    const field = make();

    assert.equal(field.binding(), 'doc.total', 'a fresh field is bound to the first non-legal field');
    assert.equal(field.formatName(), 'money');
    assert.deepEqual(field.traitNames().slice(0, 3), [VALUE_MODE_TRAIT, 'data-tpl-node', 'data-tpl-binding']);

    asked.length = 0;
    field.setFormulaMode(true);

    assert.match(field.binding(), FORMULA_KEY);
    assert.equal(field.getAttributes()['data-tpl-formula'], 'doc.total', 'the box starts with the field it had');
    assert.equal(field.formulaDisplay(), '[Document · Total]');
    assert.equal(field.formatName(), '', 'a formula starts without a format: a sentence under a date format would print empty');
    assert.deepEqual(field.traitNames().slice(0, 2), [VALUE_MODE_TRAIT, 'data-tpl-formula']);
    assert.ok(!field.traitNames().includes('data-tpl-node'));
    assert.ok(!field.traitNames().includes('data-tpl-binding'));
    assert.equal(field.get('custom-name'), 'Fórmula');
    assert.deepEqual(asked.at(-1), ['doc.total', field.cid], 'the server is asked with the technical key');
    assert.equal(field.get('content'), 'Fórmula', 'pending: the label');

    // 2. Typing names: the attribute gets the keys at once, never a `[`; the server is
    //    asked with the field as its slot.
    field.setFormulaDisplay('[Document · Total] * 2');
    assert.equal(field.getAttributes()['data-tpl-formula'], 'doc.total * 2');
    assert.ok(FORMULA_KEY.test(field.binding()), 'the source never re-binds the field');
    assert.deepEqual(asked.at(-1), ['doc.total * 2', field.cid]);
    assert.equal(field.formulaDisplay(), '[Document · Total] * 2');

    // A key typed by hand is kept as typed; a name in quotes is the user's text.
    field.setFormulaDisplay('doc.number & "[Document · Total]"');
    assert.equal(field.getAttributes()['data-tpl-formula'], 'doc.number & "[Document · Total]"');
    assert.equal(field.formulaDisplay(), '[Document · Number] & "[Document · Total]"');

    field.setFormulaDisplay('[Document · Total] * 2');

    // The answer arrives: the value, in the field's format.
    answers = { 'doc.total * 2': { value: 20, error: null } };
    field.applyFormatSpec({ name: 'money', decimals: null, symbol: 'after' });
    field.syncPreview();
    assert.equal(field.get('content'), 'money(20)');

    // What a save carries is the label, whatever the canvas shows.
    assert.equal(field.previewContent(true), 'Fórmula');

    // 3. An error: drawn as a warning, escaped, and still never saved.
    field.addAttributes({ 'data-tpl-formula': '1 +' });
    answers['1 +'] = { value: '', error: 'bad <syntax>' };
    field.syncPreview();
    assert.equal(field.get('content'), '<span class="tpl-formula-error">bad &lt;syntax&gt;</span>');
    assert.equal(field.previewContent(true), 'Fórmula');

    // A result that prints nothing shows the label, not an empty block.
    field.addAttributes({ 'data-tpl-formula': 'doc.missing_thing' });
    answers['doc.missing_thing'] = { value: '', error: null };
    field.applyFormatSpec(null);
    field.syncPreview();
    assert.equal(field.get('content'), 'Fórmula');

    // 4. An unknown name: stored as typed (the server refuses it at save, V-19), the error
    //    said here, and the server is not asked.
    asked.length = 0;
    field.setFormulaDisplay('[Inexistente] + [Document · Total]');
    assert.equal(field.getAttributes()['data-tpl-formula'], '[Inexistente] + doc.total');
    assert.deepEqual(field.formulaStatus(), { state: 'error', text: 'Campo desconhecido: [Inexistente]' });
    field.syncPreview();
    assert.equal(field.get('content'), '<span class="tpl-formula-error">Campo desconhecido: [Inexistente]</span>');
    assert.deepEqual(asked, [], 'an unknown name never reaches the server');
    assert.equal(field.formulaDisplay(), '[Inexistente] + [Document · Total]');

    // 5. Fórmula → Campo: the source goes, the field is bound as a fresh drop is.
    field.setFormulaMode(false);

    assert.ok(!('data-tpl-formula' in field.getAttributes()));
    assert.equal(field.binding(), 'doc.total');
    assert.equal(field.getAttributes()['data-tpl-node'], 'doc');
    assert.equal(field.formatName(), 'money', 'the field brings its type\'s format back');
    assert.deepEqual(field.traitNames().slice(0, 3), [VALUE_MODE_TRAIT, 'data-tpl-node', 'data-tpl-binding']);
    assert.ok(!field.traitNames().includes('data-tpl-formula'));
    assert.equal(field.get('content'), 'money(10)');
}

// 4b. With the real preview client: an unknown name sends no request at all.
{
    sheet.length = 0;
    const calls = [];
    const real = config.formulaPreview;

    config.formulaPreview = createFormulaPreview({
        url: '/preview',
        debounceMs: 0,
        fetchImpl: (...args) => {
            calls.push(args);

            return Promise.resolve({ ok: true, json: () => Promise.resolve({ results: [] }) });
        },
    });

    try {
        const field = make({ 'data-tpl-binding': 'formula.abcd1234', 'data-tpl-formula': '', 'data-tpl-format': '' });

        field.setFormulaDisplay('[Inexistente]');
        field.syncPreview();
        await new Promise((resolve) => setTimeout(resolve, 20));

        assert.equal(calls.length, 0);
        assert.equal(field.formulaStatus().state, 'error');
    } finally {
        config.formulaPreview = real;
    }
}

// 6. Legal: held only while this is the LAST data field carrying the field (Review
//    Focus 4). A copy in Fórmula carries nothing. Mandatory but not legal is never held
//    (CGE-69): a formula naming the field places it, and the switch starts from the field.
{
    sheet.length = 0;

    const lone = make({ 'data-tpl-node': 'doc', 'data-tpl-binding': 'doc.number', 'data-tpl-format': '' });
    sheet.push(lone);

    assert.equal(lone.formulaLock(), null, 'a mandatory field may become a formula');
    lone.setFormulaMode(true);
    assert.match(lone.binding(), FORMULA_KEY);
    assert.equal(lone.getAttributes()['data-tpl-formula'], 'doc.number', 'the formula still names it');

    const title = make({ 'data-tpl-node': 'doc', 'data-tpl-binding': 'doc.title', 'data-tpl-format': '' });
    sheet.push(title);
    assert.equal(title.formulaLock(), 'legal');
    title.setFormulaMode(true);
    assert.equal(title.binding(), 'doc.title', 'the last legal carrier never switches');
    assert.ok(!('data-tpl-formula' in title.getAttributes()));

    const titleCopy = make({ 'data-tpl-node': 'doc', 'data-tpl-binding': 'doc.title', 'data-tpl-format': '' });
    sheet.push(titleCopy);
    assert.equal(title.formulaLock(), null, 'two carriers: either may become a formula');
    assert.equal(titleCopy.formulaLock(), null);

    titleCopy.setFormulaMode(true);
    assert.match(titleCopy.binding(), FORMULA_KEY);
    assert.equal(title.formulaLock(), 'legal', 'the copy in Fórmula no longer counts');

    // The switch is painted from the lock: Fórmula disabled with the reason as tooltip.
    const switchEl = () => {
        const field = { value: 'field', checked: false, disabled: false };
        const formula = { value: 'formula', checked: false, disabled: false };
        const classes = new Set();

        return {
            field,
            formula,
            classes,
            title: '',
            querySelectorAll: () => [field, formula],
            classList: { toggle: (name, on) => (on ? classes.add(name) : classes.delete(name)) },
        };
    };
    const paint = (component) => {
        const el = switchEl();
        component.traits.find((trait) => trait.name === VALUE_MODE_TRAIT).el = el;
        refreshFormulaTraits(component, strings);

        return el;
    };

    let el = paint(title);
    assert.equal(el.field.checked, true);
    assert.equal(el.formula.disabled, true);
    assert.equal(el.field.disabled, false);
    assert.ok(el.classes.has('is-locked'));
    assert.equal(el.title, 'formula.legal_locked');

    el = paint(titleCopy);
    assert.equal(el.formula.checked, true);
    assert.equal(el.formula.disabled, false);
    assert.ok(!el.classes.has('is-locked'));
    assert.equal(el.title, 'formula.toggle');
}

// 7. A saved formula field opens as it was saved (Review Focus 5): no attribute written,
//    its format kept, and the saved project data carries the key and the label — never the
//    readable name, never the live result.
{
    sheet.length = 0;
    const saved = {
        'data-tpl-binding': 'formula.abcd1234',
        'data-tpl-formula': 'doc.total * 2',
        'data-tpl-format': 'money',
        'data-tpl-format-symbol': 'after',
    };
    const field = make(saved);

    assert.deepEqual(field.getAttributes(), { ...types['data-field'].model.defaults.attributes, ...saved }, 'opening writes nothing');
    assert.equal(field.binding(), 'formula.abcd1234');
    assert.equal(field.formatName(), 'money');
    assert.ok(field.traitNames().includes('data-tpl-formula'));
    assert.ok(!field.traitNames().includes('data-tpl-binding'));
    assert.equal(field.formulaDisplay(), '[Document · Total] * 2');

    sheet.push(field);
    const project = {
        getWrapper: () => ({ components: () => sheet, get: () => '' }),
        getProjectData: () => JSON.parse(JSON.stringify(sheet.map((block) => ({
            attributes: block.getAttributes(), content: block.get('content'),
        })))),
    };
    const before = plainProjectData(project);

    // The answer arrives and the canvas shows it; the saved data does not move.
    answers['doc.total * 2'] = { value: 20, error: null };
    field.syncPreview();
    assert.equal(field.get('content'), 'money(20)');
    assert.deepEqual(plainProjectData(project), before);
    assert.deepEqual(before[0].attributes, field.getAttributes());
    assert.equal(before[0].content, 'Fórmula');
    assert.ok(!JSON.stringify(before).includes('[Document'), 'no readable name is saved');

    // 8. Its copy gets a key of its own and keeps the format (Review Focus 3).
    const copy = make({ ...field.getAttributes() });
    handlers['component:clone'].forEach((handler) => handler(copy));

    assert.match(copy.binding(), FORMULA_KEY);
    assert.notEqual(copy.binding(), field.binding());
    assert.equal(copy.getAttributes()['data-tpl-formula'], 'doc.total * 2');
    assert.equal(copy.formatName(), 'money');
    assert.equal(field.binding(), 'formula.abcd1234', 'the original keeps its key');

    // A copy of an ordinary field is left alone.
    const plain = make();
    handlers['component:clone'].forEach((handler) => handler(plain));
    assert.equal(plain.binding(), 'doc.total');
}

// 9. Undo of Campo → Fórmula gives the field back exactly, custom date pattern included;
//    redo switches it again and a second undo restores it once more (CGE-65).
{
    sheet.length = 0;
    const field = make({ 'data-tpl-node': 'doc', 'data-tpl-binding': 'doc.due', 'data-tpl-format': 'date', 'data-tpl-format-pattern': 'yyyy/MM/dd' });
    sheet.push(field);
    const asField = field.getAttributes();

    field.setFormulaMode(true);
    const asFormula = field.getAttributes();
    assert.equal(field.formatName(), '');

    replay(field, asField);
    assert.equal(field.binding(), 'doc.due');
    assert.equal(field.formatName(), 'date');
    assert.equal(field.getAttributes()['data-tpl-format-pattern'], 'yyyy/MM/dd', 'undo keeps the custom date pattern');
    assert.deepEqual(field.getAttributes(), asField);

    replay(field, asFormula);
    assert.ok(field.isFormula(), 'redo is a formula again');
    assert.equal(field.formatName(), '');
    assert.deepEqual(field.getAttributes(), asFormula);

    replay(field, asField);
    assert.equal(field.getAttributes()['data-tpl-format-pattern'], 'yyyy/MM/dd', 'a second undo keeps it too');

    // A real pick after the undo still brings the new field's default format.
    field.addAttributes({ 'data-tpl-binding': 'doc.total' });
    assert.equal(field.formatName(), 'money');
}

console.log('formula-field: ok');
