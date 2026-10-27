import assert from 'node:assert/strict';
import { normalizeWidth } from '../../resources/js/editor/table.js';

/*
 * A line table column's width as a CSS length (CGE-97): a roll's columns are measured in
 * characters (`ch`); a bare number is still millimetres; anything else is dropped.
 */

assert.equal(normalizeWidth('12ch'), '12ch');
assert.equal(normalizeWidth('12'), '12mm');
assert.equal(normalizeWidth('12em'), '');
assert.equal(normalizeWidth(' 2.5CH '), '2.5ch');
assert.equal(normalizeWidth('20mm'), '20mm');
assert.equal(normalizeWidth('2cm'), '2cm');
assert.equal(normalizeWidth(''), '');
assert.equal(normalizeWidth(undefined), '');
assert.equal(normalizeWidth('1ch;color:red'), '');

console.log('table-width: ok');
