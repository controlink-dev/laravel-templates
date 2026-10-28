import assert from 'node:assert/strict';
import { columnWidthField, normalizeWidth } from '../../resources/js/editor/table.js';

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

/*
 * The column card's width field: the number, the unit shown beside it and what a typed
 * number is saved as. On a roll the unit is the character, so a width typed into a new
 * column is `10ch` (V-21 forbids `10mm` there); on a sheet a bare number stays millimetres.
 */

// A sheet: unchanged.
let field = columnWidthField('', false);
assert.equal(field.value, '');
assert.equal(field.unit, 'mm');
assert.equal(field.write('10'), '10');
assert.equal(normalizeWidth(field.write('10')), '10mm');
assert.equal(columnWidthField('20', false).value, '20');
assert.equal(columnWidthField('20', false).unit, 'mm');

// A roll: a new column is measured in characters.
field = columnWidthField('', true);
assert.equal(field.unit, 'ch');
assert.equal(field.write('10'), '10ch');
assert.equal(normalizeWidth(field.write('10')), '10ch');
assert.equal(field.write(' '), '', 'cleared is auto');

// A saved width keeps its own unit.
field = columnWidthField('12ch', true);
assert.equal(field.value, '12');
assert.equal(field.unit, 'ch');
assert.equal(field.write('14'), '14ch');
assert.equal(columnWidthField('12ch', false).unit, 'ch');
assert.equal(columnWidthField('30mm', true).unit, 'mm');

console.log('table-width: ok');
