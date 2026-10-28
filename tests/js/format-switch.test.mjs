import assert from 'node:assert/strict';
import { registerComponents } from '../../resources/js/editor/components.js';
import { applyFormat, replaceLayout } from '../../resources/js/editor/format-switch.js';

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

/*
 * The format tabs (CGE-97, Task 7b): a format never saved from the editor comes back as
 * its seeded HTML, where rules, images and slots carry their position as INLINE styles
 * and the data fields theirs as `#id` rules in the layout's css. The stub keeps the three
 * GrapesJS behaviours measured on the live editor: an inline style is merged into the
 * component's `#id` rule when it is added, `setStyle` replaces the whole sheet, and a
 * removed component takes its `#id` rule with it.
 */
function canvas() {
    const sheet = new Map();
    let components = [];
    const parseCss = (css) => new Map([...css.matchAll(/#([\w-]+)\{([^}]*)\}/g)].map(([, id, body]) => [id, parseStyle(body)]));
    const parseStyle = (body) => Object.fromEntries(body.split(';').filter(Boolean).map((pair) => pair.split(':').map((part) => part.trim())));
    const backed = (component, id) => Object.assign(component, {
        getId: () => id,
        getStyle: () => ({ ...(sheet.get(id) ?? {}) }),
        setStyle: (next) => sheet.set(id, { ...next }),
        addStyle: (next) => sheet.set(id, { ...(sheet.get(id) ?? {}), ...next }),
    });

    return {
        sheet,
        find: (id) => components.find((component) => component.getId() === id),
        setComponents(html) {
            components.forEach((component) => sheet.delete(component.getId()));
            components = [...html.matchAll(/<(\w+)([^>]*)>/g)].map(([, , attrs]) => {
                const attr = (name) => attrs.match(new RegExp(`${name}="([^"]*)"`))?.[1];
                const id = attr('id');
                const component = backed(attr('data-tpl-type') === 'rule' ? rule({}) : {}, id);

                if (attr('style')) {
                    component.addStyle(parseStyle(attr('style')));
                }

                component.init?.();

                return component;
            });
        },
        setStyle(css) {
            sheet.clear();
            parseCss(css).forEach((style, id) => sheet.set(id, style));
        },
    };
}

const seededSheet = {
    html: '<div data-tpl-type="rule" id="i11hv1" class="block block--rule" style="top:6.2mm;left:105.3mm;width:93.4mm;height:0.26mm;position:absolute;background-color:#000000"></div>'
        + '<img data-tpl-type="image" id="i1ik6z" style="top:0.3mm;left:0mm;width:103.3mm;height:24mm;position:absolute">'
        + '<div data-tpl-type="code-slot" id="tpl-qr" style="width:30mm;height:30mm;top:7mm;left:3mm;position:absolute"></div>'
        + '<div data-tpl-type="data-field" id="tpl-designation"></div>',
    css: '#tpl-designation{top:7.9mm;left:105.6mm;width:32mm;position:absolute}',
};
const seededRoll = {
    html: '<div data-tpl-type="data-field" id="tpl-designation"></div><hr data-tpl-type="rule" id="iroll1" class="block block--rule">',
    css: '#tpl-designation{font-size:9pt}',
};

// The talão on screen, then its A4 tab: every block keeps the style its layout gave it.
const tabs = canvas();
config.geometry = roll;
replaceLayout(tabs, seededRoll.html, seededRoll.css);
applyFormat(config, sheet, {
    layout: seededSheet,
    loadLayout: (layout) => replaceLayout(tabs, layout.html, layout.css),
    applyPaper: () => tabs.find('i11hv1').syncRollMode(),
});

assert.deepEqual(tabs.find('i11hv1').getStyle(), {
    top: '6.2mm', left: '105.3mm', width: '93.4mm', height: '0.26mm', position: 'absolute', 'background-color': '#000000',
}, 'a sheet rule opened from a roll tab keeps its position and size');
assert.equal(tabs.find('i11hv1').get('tagName'), 'div');
assert.deepEqual(tabs.find('i1ik6z').getStyle(), { top: '0.3mm', left: '0mm', width: '103.3mm', height: '24mm', position: 'absolute' });
assert.deepEqual(tabs.find('tpl-qr').getStyle(), { width: '30mm', height: '30mm', top: '7mm', left: '3mm', position: 'absolute' });
// An id both formats share: the roll's block leaving does not take the sheet's rule with it.
assert.deepEqual(tabs.find('tpl-designation').getStyle(), { top: '7.9mm', left: '105.6mm', width: '32mm', position: 'absolute' });

// And back to the talão: its rule is the receipt's bare `hr`.
applyFormat(config, roll, {
    layout: { html: seededRoll.html.replace('<hr', '<div') + '', css: '#iroll1{width:40mm;height:0.26mm}' + seededRoll.css },
    loadLayout: (layout) => replaceLayout(tabs, layout.html, layout.css),
    applyPaper: () => tabs.find('iroll1').syncRollMode(),
});

assert.equal(tabs.find('iroll1').get('tagName'), 'hr');
assert.deepEqual(tabs.find('iroll1').getStyle(), {}, 'a roll rule is vocabulary-clean');

console.log('format-switch: ok');
