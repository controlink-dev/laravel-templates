import assert from 'node:assert/strict';
import { registerComponents } from '../../resources/js/editor/components.js';
import { applyFormat } from '../../resources/js/editor/format-switch.js';

/*
 * The format select switching a roll to a sheet (CGE-97, Task 7 fix 1): the sheet's
 * starting layout is built in the sheet's medium. Its rules keep their position and
 * size; built while the geometry was still the roll, they became bare `hr`s.
 */

globalThis.document ??= {
    createElement: () => ({ set textContent(value) { this.innerHTML = String(value); } }),
};

const types = {};
const editor = {
    DomComponents: { addType: (name, definition) => { types[name] = definition; } },
    BlockManager: { add: () => {} },
    TraitManager: { addType: () => {} },
    on: () => {},
};
const strings = new Proxy({}, { get: (_, key) => String(key) });
const roll = { medium: 'roll', width_mm: 80, margins: { top: 3, right: 3, bottom: 3, left: 3 } };
const sheet = { medium: 'sheet', paper_format: 'A4' };
const config = { strings, formats: [], ruleWidths: { thin: 0.26 }, bindings: [], nodes: [], fixture: {}, geometry: roll };

registerComponents(editor, config);

/** The print-rule type's own model methods over just enough of a GrapesJS component. */
function rule(style) {
    const definition = types['print-rule'].model;
    const component = {
        props: { tagName: 'div', 'tpl-width': '0.26', 'tpl-filled': false },
        style: { ...style },
        attributes: { class: 'block block--rule', 'data-tpl-type': 'rule' },
        traits: definition.defaults.traits.map((trait) => trait.name),
        get(name) { return this.props[name]; },
        set(name, value) { this.props[name] = value; },
        getStyle() { return { ...this.style }; },
        addStyle(next) { this.style = { ...this.style, ...next }; },
        setStyle(next) { this.style = { ...next }; },
        getAttributes() { return { ...this.attributes }; },
        addAttributes(next) { this.attributes = { ...this.attributes, ...next }; },
        removeAttributes(name) { delete this.attributes[name]; },
        getTrait(name) { return this.traits.includes(name) ? { name } : null; },
        addTrait(trait) { this.traits.push(trait.name); },
        removeTrait(name) { this.traits = this.traits.filter((other) => other !== name); },
        on() {},
    };

    for (const [name, method] of Object.entries(definition)) {
        if (typeof method === 'function') {
            component[name] = method;
        }
    }

    return component;
}

const positioned = { position: 'absolute', left: '15mm', top: '40mm', width: '180mm', height: '0.26mm', 'background-color': '#000000' };
let loaded = null;

// From a roll to a sheet: the sheet's rule is built — and then the paper applied — as a sheet.
applyFormat(config, sheet, {
    layout: { html: '<div></div>' },
    loadLayout: () => {
        loaded = rule(positioned);
        loaded.init();
    },
    applyPaper: () => loaded.syncRollMode(),
});

assert.equal(config.geometry, sheet);
assert.equal(loaded.get('tagName'), 'div');
assert.deepEqual(loaded.getStyle(), positioned, 'a sheet rule keeps its position and size');
assert.deepEqual(loaded.traits, ['tpl-width', 'tpl-filled']);

// And from a sheet to a roll, the roll's rule is the receipt's plain `hr`.
applyFormat(config, roll, {
    layout: { html: '<div></div>' },
    loadLayout: () => {
        loaded = rule({ width: '40mm' });
        loaded.init();
    },
    applyPaper: () => loaded.syncRollMode(),
});

assert.equal(loaded.get('tagName'), 'hr');
assert.deepEqual(loaded.getStyle(), {});

// Declined (no layout): only the geometry and the paper change.
let applied = null;

applyFormat(config, sheet, { layout: null, loadLayout: () => assert.fail('nothing to load'), applyPaper: (geometry) => { applied = geometry; } });
assert.equal(applied, sheet);

console.log('format-switch: ok');
