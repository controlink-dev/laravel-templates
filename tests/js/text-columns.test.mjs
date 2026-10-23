import assert from 'node:assert/strict';
import { isTextColumn } from '../../resources/js/editor/formula-columns.js';

/*
 * Text columns of the line table (CGE-89): the ones whose cells wrap, on the canvas as in
 * the PDF. Mirrors `RowHeights::isText` on the server: visible, no format, no formula.
 */

assert.equal(isTextColumn({ key: 'description', format: '' }), true);
assert.equal(isTextColumn({ key: 'description' }), true, 'no format at all is text');
assert.equal(isTextColumn({ key: 'amount', format: 'money' }), false);
assert.equal(isTextColumn({ key: 'description', format: '', hidden: true }), false);
assert.equal(isTextColumn({ key: 'rate', format: '', formula: 'a & b', formulaKey: 'formula_ab12' }), false);
assert.equal(isTextColumn({ key: 'formula_ab12', format: '' }), false, 'a calculated column saved before formulaKey');
assert.equal(isTextColumn(null), false);

console.log('text-columns: ok');
