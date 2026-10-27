import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    cellClasses,
    cellHtml,
    classesFor,
    normalizeCols,
    rollMessage,
    rollSnapStyle,
    rollStyleTraits,
    setRollState,
    stateOf,
    syncRollTraits,
    textRowBlockHtml,
    textRowHtml,
    withRollState,
} from '../../resources/js/editor/roll-style.js';
import { ruleStyle, ruleTagName } from '../../resources/js/editor/rule-shape.js';
import { rollProblems } from '../../resources/js/editor/roll-vocabulary.js';

/*
 * A receipt's fixed styling (CGE-97, Task 7): on a roll the editor writes only the
 * receipt's `esc-*` classes, and a «Linha com colunas» row in the markup the ESC/POS
 * translator reads. Whatever the editor itself writes on a roll is clean for L-ESC/V-21.
 */

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/roll-vocabulary-cases.json', import.meta.url), 'utf8'));

/* ---------------------------------------------------------------- classes */

assert.deepEqual(classesFor({ size: 'double', bold: true, align: 'right', gap: 2 }), ['esc-double', 'esc-bold', 'esc-right', 'esc-gap-2']);
assert.deepEqual(stateOf(['flow', 'esc-center']), { size: 'normal', bold: false, align: 'center', gap: 0 });
assert.deepEqual(classesFor({}), []);
assert.deepEqual(stateOf('block block--text'), { size: 'normal', bold: false, align: 'left', gap: 0 });

// The exact inverse, for the 2×2×3×4 states.
let states = 0;

for (const size of ['normal', 'double']) {
    for (const bold of [false, true]) {
        for (const align of ['left', 'center', 'right']) {
            for (const gap of [0, 1, 2, 3]) {
                assert.deepEqual(stateOf(classesFor({ size, bold, align, gap })), { size, bold, align, gap });
                states++;
            }
        }
    }
}

assert.equal(states, 48);

// A trait replaces only its own classes: flow, esc-cell, esc-nowrap stay where they were.
assert.deepEqual(
    withRollState(['flow', 'esc-cell', 'esc-right', 'esc-nowrap', 'esc-gap-1'], { align: 'center', bold: true }),
    ['flow', 'esc-cell', 'esc-nowrap', 'esc-bold', 'esc-center', 'esc-gap-1'],
);
assert.deepEqual(withRollState(['flow', 'esc-double'], { size: 'normal' }), ['flow']);

/* ---------------------------------------------------------------- the row */

// What text-row exports (its cells' own HTML, wrapped) is the vocabulary's row, byte for byte.
const label = cellHtml({ type: 'static-text', cols: 10, id: 'tpl-total-label', html: 'Total' });
const value = cellHtml({
    type: 'data-field',
    classes: ['esc-right', 'esc-nowrap'],
    attributes: { 'data-tpl-binding': 'totals.total_due' },
    cols: '0',
    id: 'tpl-total',
    html: '{{ totals.total_due }}',
});
const row = textRowHtml([label, value], { class: 'flow esc-row', 'data-tpl-type': 'text-row', id: 'tpl-total-row' });

assert.equal(row, fixture.text_row);
assert.ok(fixture.cases[0].html.includes(row), 'case 1 carries the same row');
assert.deepEqual(rollProblems(`<div class="page">${row}</div>`, '', 40), []);

// The block drops an empty label and an unset value, half and half, also clean.
const dropped = textRowBlockHtml();

assert.equal(
    dropped,
    '<div class="flow esc-row" data-tpl-type="text-row">'
        + '<div class="flow esc-cell" data-tpl-type="static-text" data-tpl-cols="0"></div>'
        + '<div class="flow esc-cell esc-right esc-nowrap" data-tpl-type="data-field" data-tpl-cols="0"></div>'
        + '</div>',
);
assert.deepEqual(rollProblems(`<div class="page">${dropped}</div>`, '', 32), []);

// On a roll a text is a flow block (it wraps on words, as ESC/POS prints it), never an
// absolutely placed `block` (nowrap, which made a long text E-04): in a row a flow cell,
// out of one a flow block that is no longer a cell.
assert.deepEqual(cellClasses(['block', 'block--text', 'esc-bold'], true), ['flow', 'esc-cell', 'esc-bold']);
assert.deepEqual(cellClasses(['flow', 'esc-cell', 'esc-right'], false), ['flow', 'esc-right']);
assert.deepEqual(cellClasses(['flow', 'esc-cell'], true), ['flow', 'esc-cell']);
assert.deepEqual(cellClasses(['block', 'block--text'], false), ['flow']);
assert.deepEqual(cellClasses(['block', 'block--field', 'esc-center'], false), ['flow', 'esc-center']);
assert.deepEqual(cellClasses(['block', 'block--page-number'], false), ['flow']);
assert.deepEqual(cellClasses(['flow', 'esc-bold'], false), ['flow', 'esc-bold']);

// A cell's width: a whole number of characters from 0 to the grid's.
assert.equal(normalizeCols('10', 40), '10');
assert.equal(normalizeCols('', 40), '0');
assert.equal(normalizeCols('1.5', 40), '0');
assert.equal(normalizeCols('-3', 40), '0');
assert.equal(normalizeCols('50', 32), '32');

/* ---------------------------------------------------------------- traits */

/** Just enough of a GrapesJS component for the traits. */
function stubComponent(type, classes) {
    let list = [...classes];
    let traits = [];

    return {
        getAttributes: () => ({ 'data-tpl-type': type }),
        getClasses: () => [...list],
        setClass: (next) => { list = [...next]; },
        getTrait: (name) => traits.find((trait) => trait.name === name) ?? null,
        addTrait: (trait) => { traits.push(trait); },
        removeTrait: (name) => { traits = traits.filter((trait) => trait.name !== name); },
        traitNames: () => traits.map((trait) => trait.name),
    };
}

const strings = new Proxy({}, { get: (_, key) => String(key) });
const traits = rollStyleTraits(strings);

assert.deepEqual(traits.map((trait) => trait.name), ['tpl-esc-size', 'tpl-esc-bold', 'tpl-esc-align', 'tpl-esc-gap']);
assert.ok(traits.every((trait) => typeof trait.label === 'string' && trait.label !== ''), 'every trait is labelled');

const text = stubComponent('static-text', ['flow', 'esc-cell', 'esc-right']);
const byName = Object.fromEntries(traits.map((trait) => [trait.name, trait]));

assert.equal(byName['tpl-esc-align'].getValue({ component: text }), 'right');
assert.equal(byName['tpl-esc-bold'].getValue({ component: text }), false);
assert.equal(byName['tpl-esc-gap'].getValue({ component: text }), '0');

byName['tpl-esc-size'].setValue({ component: text, value: 'double' });
byName['tpl-esc-bold'].setValue({ component: text, value: true });
byName['tpl-esc-gap'].setValue({ component: text, value: '3' });
byName['tpl-esc-align'].setValue({ component: text, value: 'center' });

assert.deepEqual(text.getClasses(), ['flow', 'esc-cell', 'esc-double', 'esc-bold', 'esc-center', 'esc-gap-3']);

byName['tpl-esc-bold'].setValue({ component: text, value: false });
assert.deepEqual(stateOf(text.getClasses()), { size: 'double', bold: false, align: 'center', gap: 3 });

// Unchanged state writes nothing.
let writes = 0;
const quiet = { getClasses: () => ['flow', 'esc-bold'], setClass: () => { writes++; } };

setRollState(quiet, { bold: true });
assert.equal(writes, 0);

// On a roll a styled block has the four, a cell also its width; on a sheet none.
const cell = stubComponent('data-field', ['flow', 'esc-cell']);

// A cell gets no «Letra»: the receipt prints a row's cells in the normal letter (a double
// cell is E-04), so the editor offers only what the printer prints.
syncRollTraits(cell, true, strings, 40);
assert.deepEqual(cell.traitNames(), ['tpl-esc-bold', 'tpl-esc-align', 'tpl-esc-gap', 'data-tpl-cols']);
assert.equal(cell.getTrait('data-tpl-cols').max, 40);
syncRollTraits(cell, true, strings, 40);
assert.equal(cell.traitNames().length, 4, 'syncing twice adds nothing');

// A block that becomes a cell loses its «Letra»; one that leaves the row gets it back, in order.
const moved = stubComponent('static-text', ['flow']);

syncRollTraits(moved, true, strings, 40);
assert.deepEqual(moved.traitNames(), ['tpl-esc-size', 'tpl-esc-bold', 'tpl-esc-align', 'tpl-esc-gap']);
moved.setClass(['flow', 'esc-cell']);
syncRollTraits(moved, true, strings, 40);
assert.deepEqual(moved.traitNames(), ['tpl-esc-bold', 'tpl-esc-align', 'tpl-esc-gap', 'data-tpl-cols']);
moved.setClass(['flow']);
syncRollTraits(moved, true, strings, 40);
assert.deepEqual(moved.traitNames(), ['tpl-esc-size', 'tpl-esc-bold', 'tpl-esc-align', 'tpl-esc-gap']);
syncRollTraits(cell, false, strings, 40);
assert.deepEqual(cell.traitNames(), []);

const pageNumber = stubComponent('page-number', ['block', 'block--page-number']);

syncRollTraits(pageNumber, true, strings, 32);
assert.deepEqual(pageNumber.traitNames(), ['tpl-esc-size', 'tpl-esc-bold', 'tpl-esc-align', 'tpl-esc-gap']);

const image = stubComponent('image', ['block', 'block--image']);

syncRollTraits(image, true, strings, 32);
assert.deepEqual(image.traitNames(), []);

// The classes the traits write are in the vocabulary.
assert.deepEqual(
    rollProblems(`<div class="page"><div class="${text.getClasses().join(' ')}" data-tpl-type="static-text" id="t">x</div></div>`, '', 40),
    [],
);

/* ---------------------------------------------------------------- the rule */

// On a roll the «Linha» is a plain rule: an `hr`, no size, no colour (V-21 forbids them).
assert.equal(ruleTagName(true), 'hr');
assert.equal(ruleTagName(false), 'div');
assert.deepEqual(ruleStyle({ roll: true, thickness: '0.26', filled: false, style: { width: '40mm' } }), {});
assert.deepEqual(rollProblems('<div class="page"><hr class="block block--rule" data-tpl-type="rule" id="r1"></div>', '', 40), []);

// On a sheet it is what it always was.
assert.deepEqual(ruleStyle({ roll: false, thickness: '0.26', filled: false, style: {} }), { height: '0.26mm', 'background-color': '#000000' });
assert.deepEqual(ruleStyle({ roll: false, thickness: '0.26', filled: true, style: { 'background-color': '#d8d8d8' } }), { 'background-color': '#d8d8d8' });
assert.deepEqual(ruleStyle({ roll: false, thickness: '0.26', filled: true, style: {} }), { 'background-color': '#000000' });

/* ---------------------------------------------------------------- «Repor na folha» */

// On a roll only an image or the code slot is narrowed, and only to a size in mm.
assert.deepEqual(rollSnapStyle({ type: 'static-text', widthMm: 90, printableWidthMm: 74 }), {});
assert.deepEqual(rollSnapStyle({ type: 'image', widthMm: 60, printableWidthMm: 74 }), {});

const narrowed = rollSnapStyle({ type: 'image', widthMm: 90, printableWidthMm: 74.04 });

assert.deepEqual(narrowed, { width: '74mm' });
assert.deepEqual(
    rollProblems(
        '<div class="page"><img data-tpl-type="image" class="block block--image" id="i1" src="data:image/png;base64,iVBORw0KGgo="></div>',
        `#i1{width:${narrowed.width}}`,
        40,
    ),
    [],
);
assert.deepEqual(rollSnapStyle({ type: 'code-slot', tagName: 'img', widthMm: 80, printableWidthMm: 52 }), { width: '52mm' });

/* ---------------------------------------------------------------- messages */

const messages = {
    'check.roll_cols': 'A largura «:subject» não serve: de 0 até :columns.',
    'check.roll_vocabulary': 'O talão usa «:subject».',
};

assert.equal(rollMessage({ key: 'roll_cols', subject: '50' }, messages, 40), 'A largura «50» não serve: de 0 até 40.');
assert.equal(rollMessage({ key: 'roll_table', subject: 'table' }, messages, 40), 'O talão usa «table».');

console.log('roll-style: ok');
