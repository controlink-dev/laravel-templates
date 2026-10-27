import assert from 'node:assert/strict';
import { rollColumns, rollGridCss } from '../../resources/js/editor/roll-grid.js';

/*
 * A roll is a monospace character grid (CGE-97). The canvas sets the same letter as
 * DocumentComposer::geometryStyles, byte for byte, so the receipt breaks its lines in the
 * editor where it breaks them in the PDF and on the printer.
 */

const roll80 = { medium: 'roll', width_mm: 80, margins: { top: 3, right: 3, bottom: 3, left: 3 } };

assert.equal(rollColumns(roll80), 40);
assert.equal(rollColumns({ ...roll80, width_mm: 58 }), 32);
assert.equal(rollColumns({ ...roll80, columns: 42 }), 42);

assert.ok(rollGridCss(roll80).includes(
    'html .page{font-family:"Liberation Mono","Courier New",monospace;font-size:3.083mm;line-height:1.25}',
));
assert.ok(rollGridCss({ ...roll80, width_mm: 58 }).includes('font-size:2.708mm'));
assert.ok(rollGridCss(roll80).includes(
    '.page hr,.page [data-tpl-type="rule"]{border:0;border-top:1px dashed #000;margin:0.5em 0}',
));

// A wrapped line-table text cell keeps the grid's line height on the canvas, as the PDF
// gives it (PageStructure::rollWrapCss, 1.25), beating the canvas-wide `td.tpl-wrap` 1.2.
assert.ok(rollGridCss(roll80).includes('html .page table.lines td.tpl-wrap{line-height:1.25}'));

// A sheet has no grid: nothing is emitted, so its canvas keeps the family it had.
assert.equal(rollGridCss({ medium: 'sheet', paper_format: 'A4' }), '');
assert.equal(rollGridCss(undefined), '');

console.log('roll-grid: ok');
