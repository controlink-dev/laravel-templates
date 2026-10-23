import assert from 'node:assert/strict';
import { pickerTree, foldText } from '../../resources/js/editor/column-picker.js';

/*
 * "Add column" (CGE-85): the tree the picker draws, as data. The table's own columns, then
 * one group per link with its fields; the calculated column is not in it (it is the
 * picker's fixed last entry). The search ignores case and accents.
 */

const binding = {
    key: 'lines',
    label: 'Linhas',
    columns: [
        { key: 'description', label: 'Descrição' },
        { key: 'amount', label: 'Valor', default_format: 'money' },
        { key: 'family.name', label: 'Família', linked: { alias: 'family', label: 'Família', field: 'name', field_label: 'Nome' } },
        { key: 'family.code', label: 'Família › Código', linked: { alias: 'family', label: 'Família', field: 'code', field_label: 'Código' }, optional: true },
        { key: 'unit.name', label: 'Unidade', linked: { alias: 'unit', label: 'Unidade', field: 'name', field_label: 'Nome' } },
        { key: 'unit.code', label: 'Unidade › Código', linked: { alias: 'unit', label: 'Unidade', field: 'code', field_label: 'Código' }, optional: true },
        { key: 'unit.decimals', label: 'Unidade › Casas decimais', linked: { alias: 'unit', label: 'Unidade', field: 'decimals', field_label: 'Casas decimais' }, optional: true },
    ],
};

const columns = [
    { key: 'description', label: 'Descrição' },
    { key: 'amount', label: 'Valor' },
    { key: 'family.name', label: 'Família' },
    { key: 'unit.name', label: 'Unidade', hidden: true },
    { key: 'formula_abcdefgh', formulaKey: 'formula_abcdefgh', label: 'F', formula: '' },
];

// 1. No query: own group first, then each link in catalogue order, with its fields.
{
    const tree = pickerTree(binding, columns, '');

    assert.deepEqual(tree.map((group) => [group.group, group.label]), [['own', 'Linhas'], ['family', 'Família'], ['unit', 'Unidade']]);
    assert.deepEqual(tree[0].items, [
        { key: 'description', label: 'Descrição', inTable: true },
        { key: 'amount', label: 'Valor', inTable: true },
    ]);
    assert.deepEqual(tree[1].items, [
        { key: 'family.name', label: 'Nome', inTable: true },
        { key: 'family.code', label: 'Código', inTable: false },
    ]);
    // A hidden column is still in the table: showing it again is its eye's job.
    assert.deepEqual(tree[2].items.map((item) => [item.key, item.inTable]), [['unit.name', true], ['unit.code', false], ['unit.decimals', false]]);
}

// 2. An added field comes back as already in the table.
{
    const tree = pickerTree(binding, [...columns, { key: 'family.code', label: 'Família › Código', origin: 'added' }], '');

    assert.equal(tree[1].items.find((item) => item.key === 'family.code').inTable, true);
}

// 3. The search: "cód" finds "Código" of Family and of Unit, and nothing of the table's own.
{
    const tree = pickerTree(binding, columns, 'cód');

    assert.deepEqual(tree.map((group) => group.group), ['family', 'unit']);
    assert.deepEqual(tree[0].items.map((item) => item.key), ['family.code']);
    assert.deepEqual(tree[1].items.map((item) => item.key), ['unit.code']);

    // No accent, other case: the same.
    assert.deepEqual(pickerTree(binding, columns, '  COD ').map((group) => group.items.map((item) => item.key)), [['family.code'], ['unit.code']]);
}

// 4. A link's label finds all of its fields; a field's label finds own columns too.
{
    assert.deepEqual(pickerTree(binding, columns, 'unidade').map((group) => [group.group, group.items.length]), [['unit', 3]]);
    assert.deepEqual(pickerTree(binding, columns, 'descricao').map((group) => group.group), ['own']);
    assert.deepEqual(pickerTree(binding, columns, 'nothing at all'), []);
}

// 5. A collection with no links (or none at all): its own group only, or nothing.
{
    assert.deepEqual(pickerTree({ key: 'x', label: 'X', columns: [{ key: 'a', label: 'A' }] }, [], '').map((group) => group.items), [[{ key: 'a', label: 'A', inTable: false }]]);
    assert.deepEqual(pickerTree(undefined, [], ''), []);
}

// 6. The fold the search uses.
{
    assert.equal(foldText('  Código ÁGUA '), 'codigo agua');
    assert.equal(foldText(null), '');
}

console.log('column-picker: ok');
