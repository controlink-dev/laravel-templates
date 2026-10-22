import assert from 'node:assert/strict';
import { registerComponents } from '../../resources/js/editor/components.js';

/*
 * The data field's formula mode (CGE-37), driven through its own methods on a stand-in
 * for a GrapesJS component. The stand-in reproduces the one GrapesJS behaviour the field's
 * event wiring depends on: `setAttributes` replaces the whole object and fires
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
};

const strings = new Proxy({ 'formula.label': 'Fórmula' }, { get: (target, key) => target[key] ?? String(key) });

let answers = {};
const asked = [];
const config = {
    strings,
    formats: ['money'],
    ruleWidths: {},
    bindings: [{ key: 'doc.title', label: 'Title', legal: true }],
    nodes: [{
        id: 'doc',
        label: 'Document',
        fields: [
            { key: 'doc.title', label: 'Title', legal: true },
            { key: 'doc.total', label: 'Total', type: 'money' },
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

const FORMULA_KEY = /^formula\.[a-z0-9]{8}$/;

// 1. Switching on: a fresh key, an empty source, the selects replaced by the box.
{
    const field = make();

    assert.equal(field.binding(), 'doc.total', 'a fresh field is bound to the first non-legal field');
    assert.equal(field.formatName(), 'money');
    assert.deepEqual(field.traitNames().slice(0, 3), ['tpl-formula-mode', 'data-tpl-node', 'data-tpl-binding']);

    field.setFormulaMode(true);

    assert.match(field.binding(), FORMULA_KEY);
    assert.equal(field.getAttributes()['data-tpl-formula'], '');
    assert.equal(field.formatName(), '', 'a formula starts without a format');
    assert.deepEqual(field.traitNames().slice(0, 2), ['tpl-formula-mode', 'data-tpl-formula']);
    assert.ok(!field.traitNames().includes('data-tpl-node'));
    assert.ok(!field.traitNames().includes('data-tpl-binding'));
    assert.equal(field.get('custom-name'), 'Fórmula');
    assert.equal(field.get('content'), 'Fórmula', 'no source yet: the label');

    // 2. Typing: the attribute follows at once, the server is asked with the field as slot.
    asked.length = 0;
    field.addAttributes({ 'data-tpl-formula': 'doc.total * 2' });
    assert.ok(FORMULA_KEY.test(field.binding()), 'the source never re-binds the field');
    assert.deepEqual(asked.at(-1), ['doc.total * 2', field.cid]);
    assert.equal(field.get('content'), 'Fórmula', 'pending: the previous text stays');

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

    // 4. Switching off: the source goes, and the field is bound as a fresh drop is.
    field.setFormulaMode(false);

    assert.ok(!('data-tpl-formula' in field.getAttributes()));
    assert.equal(field.binding(), 'doc.total');
    assert.equal(field.getAttributes()['data-tpl-node'], 'doc');
    assert.equal(field.formatName(), 'money', 'the field brings its type\'s format back');
    assert.deepEqual(field.traitNames().slice(0, 3), ['tpl-formula-mode', 'data-tpl-node', 'data-tpl-binding']);
    assert.ok(!field.traitNames().includes('data-tpl-formula'));
    assert.equal(field.get('content'), 'money(10)');
}

// 5. A legal field never becomes a formula.
{
    const field = make({ 'data-tpl-node': 'doc', 'data-tpl-binding': 'doc.title', 'data-tpl-format': '' });

    assert.ok(field.isLegalBinding());
    field.setFormulaMode(true);
    assert.equal(field.binding(), 'doc.title');
    assert.ok(!('data-tpl-formula' in field.getAttributes()));
}

// 6. A saved formula field opens as it was saved: no node written, its format kept.
{
    const field = make({
        'data-tpl-binding': 'formula.abcd1234',
        'data-tpl-formula': 'doc.total * 2',
        'data-tpl-format': 'money',
        'data-tpl-format-symbol': 'after',
    });

    assert.equal(field.binding(), 'formula.abcd1234');
    assert.ok(!('data-tpl-node' in field.getAttributes()) || field.getAttributes()['data-tpl-node'] !== 'formula');
    assert.equal(field.formatName(), 'money');
    assert.ok(field.traitNames().includes('data-tpl-formula'));
    assert.ok(!field.traitNames().includes('data-tpl-binding'));

    // 7. Its copy gets a key of its own and keeps the format (Review Focus 3).
    sheet.length = 0;
    sheet.push(field);

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

console.log('formula-field: ok');
