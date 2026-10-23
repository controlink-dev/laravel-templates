import assert from 'node:assert/strict';
import { registerComponents } from '../../resources/js/editor/components.js';
import { registerTableAndSlot } from '../../resources/js/editor/table.js';

/*
 * Every trait of every block says its own label, or none (CGE-87).
 *
 * A trait without `label` is not unlabelled: GrapesJS derives one from its `name`, so the
 * code slot's content showed "Data tpl code content" in the panel. A trait either carries
 * a translated string or opts out with `label: false`.
 */

// `escapeText` (block labels) goes through a DOM element; a stand-in is enough here.
globalThis.document ??= {
    createElement: () => ({ set textContent(value) { this.innerHTML = String(value); } }),
};

const types = {};

// Only what the register functions reach for while declaring types and blocks.
const editor = {
    DomComponents: { addType: (name, definition) => { types[name] = definition; } },
    BlockManager: { add: () => {} },
    TraitManager: { addType: () => {} },
    on: () => {},
};

const strings = new Proxy({}, { get: (_, key) => String(key) });
const config = { strings, formats: [], ruleWidths: {}, bindings: [], nodes: [], fixture: {} };

registerComponents(editor, config);
registerTableAndSlot(editor, config);

const blocks = ['static-text', 'data-field', 'print-image', 'print-rule', 'page-number', 'line-table', 'code-slot'];

for (const name of blocks) {
    assert.ok(types[name], `type ${name} is registered`);
}

/*
 * A type that declares no traits is not traitless either: GrapesJS gives it `id` and
 * `title`, untranslated, and the sheet and its bands showed "Id" and "Title" in the panel —
 * an `id` the layout's CSS rules hang on, open to editing.
 */
for (const [name, definition] of Object.entries(types)) {
    const traits = definition.model?.defaults?.traits;

    assert.ok(Array.isArray(traits), `${name} declares its traits`);

    for (const trait of traits) {
        const id = typeof trait === 'string' ? trait : trait.name;
        const label = typeof trait === 'string' ? undefined : trait.label;

        assert.ok(
            label === false || (typeof label === 'string' && label !== ''),
            `${name}.${id} has no label`,
        );
    }
}
