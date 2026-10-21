import assert from 'node:assert/strict';
import { registerComponents } from '../../resources/js/editor/components.js';
import { registerTableAndSlot } from '../../resources/js/editor/table.js';

/*
 * A block is never a container (CGE-48).
 *
 * GrapesJS makes every component droppable unless its type says otherwise, so the layer
 * tree's sorter (nested mode) put a block dragged below the last row of a band INSIDE
 * that row's block. The data field then re-rendered its preview text, which empties its
 * children for good: the moved block lost its `#id` rule (size, colour, font) and its
 * parent, and was put back in the band with only a top and a left. Measured on the
 * invoice: "Total taxes" dropped under "Total due" came back as `top:171mm; left:180mm`.
 *
 * The sheet is not a drop target either: it is the root of the layer tree, so a block
 * let go between two band rows would land on the sheet, outside every band.
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

const droppable = (name) => types[name]?.model?.defaults?.droppable;

// 1. No block accepts a child, whatever its kind.
{
    const blocks = ['static-text', 'data-field', 'print-image', 'print-rule', 'page-number', 'line-table', 'code-slot'];

    for (const name of blocks) {
        assert.ok(types[name], `type ${name} is registered`);
        assert.equal(droppable(name), false, `${name} must not be droppable`);
    }
}

// 2. The sheet only holds its bands.
{
    assert.equal(droppable('page'), false);
}

// 3. The bands are where blocks go.
{
    for (const zone of ['zone-header', 'zone-body', 'zone-footer']) {
        assert.notEqual(droppable(zone), false, `${zone} must stay droppable`);
    }
}

console.log('nesting: OK');
