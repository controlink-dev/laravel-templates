import assert from 'node:assert/strict';
import {
    FUNCTION_GROUPS, functionGroupOf, quickExamples, scopedCatalogue, toDisplay, toSource,
} from '../../resources/js/editor/formula-names.js';
import { applySuggestion, suggestionsAt } from '../../resources/js/editor/formula.js';

/*
 * Readable field names in formulas (CGE-37, spec §5): the editor shows `[Cliente · Nome]`
 * where the source has `customer.name`, and turns it back before anything is stored or
 * sent. Text between quotes is never touched, and what has no name stays technical.
 */

const config = {
    nodes: [
        { id: 'customer', label: 'Cliente', collection: false, reachable: true, fields: [
            { key: 'customer.name', label: 'Nome' },
            { key: 'customer.nif', label: 'NIF' },
        ] },
        { id: 'document', label: 'Documento', collection: false, reachable: true, fields: [
            { key: 'document.number', label: 'Número' },
            { key: 'document.notes', label: 'Notas' },
            { key: 'document.notes_extra', label: 'Notas' },
            { key: 'document.ref', label: 'Ref [interna]' },
        ] },
        { id: 'lines', label: 'Linhas', collection: true, fields: [] },
        { id: 'formula', label: 'Fórmulas', fields: [{ key: 'formula.ab12cd34', label: 'Z' }] },
    ],
    bindings: [
        { key: 'lines', label: 'Linhas', type: 'collection', columns: [
            { key: 'code', label: 'Código' },
            { key: 'quantity', label: 'Quantidade', default_format: 'number' },
            { key: 'value', label: 'Valor', default_format: 'money' },
        ] },
    ],
    formulaFunctions: ['IF', 'SUM', 'COUNT', 'UPPER', 'YEAR', 'NEWFN'],
    strings: {
        'formula.signature.SUM': 'SUM(coleção.coluna)',
        'formula.description.SUM': 'Soma a coluna.',
        'formula.example.SUM': 'SUM(lines.value)',
        'formula.library.this_row': 'Esta linha',
        'formula.group.totals': 'Totais da tabela',
    },
};

const single = scopedCatalogue(config);
const column = scopedCatalogue(config, 'lines');

/* ------------------------------------------------------------ round trip */

{
    // In a column of `lines`, its own columns are short; everything else names its model.
    const source = 'customer.name & " " & lines.quantity';
    const display = toDisplay(source, column);
    assert.equal(display, '[Cliente · Nome] & " " & [Quantidade]');
    assert.deepEqual(toSource(display, column), { source, unknown: [] });
}

{
    // In a single field a column of the table names its collection.
    assert.equal(toDisplay('SUM(lines.quantity)', single), 'SUM([Linhas · Quantidade])');
    assert.deepEqual(toSource('SUM([Linhas · Quantidade])', single), { source: 'SUM(lines.quantity)', unknown: [] });

    // A short name is only this line's: outside a column of that table it is unknown.
    assert.deepEqual(toSource('[Quantidade]', single), { source: '[Quantidade]', unknown: ['[Quantidade]'] });
    assert.deepEqual(toSource('[Quantidade]', scopedCatalogue(config, 'other')).unknown, ['[Quantidade]']);

    // In a column the long name is accepted too (the totals insert it), and so is the short.
    assert.deepEqual(toSource('SUM([Linhas · Valor])', column), { source: 'SUM(lines.value)', unknown: [] });
    assert.deepEqual(toSource('SUM([Valor])', column), { source: 'SUM(lines.value)', unknown: [] });

    // The whole argument of SUM/COUNT is the table's column: shown long, as inserted.
    // Anywhere else — this line's value, or inside a calculation under SUM — short.
    assert.equal(toDisplay('SUM(lines.value)', column), 'SUM([Linhas · Valor])');
    assert.equal(toDisplay('COUNT( lines.code ) + lines.value', column), 'COUNT( [Linhas · Código] ) + [Valor]');
    assert.equal(toDisplay('SUM(lines.value * 2)', column), 'SUM([Valor] * 2)');
    assert.equal(toDisplay('ROUND(lines.value)', column), 'ROUND([Valor])');
}

{
    // Many sources survive the trip in both scopes.
    [
        'customer.name & " " & document.number',
        'IF(lines.quantity > 0, lines.value * 1.23, 0)',
        'UPPER(customer.name) & "customer.name"',
        'SUM(lines.value) / COUNT(lines.code)',
        'document.notes & document.notes_extra & document.ref',
        '0.23 * 2',
        'customer.name_full',
        '"a ""quoted"" customer.name" & customer.nif',
        '',
    ].forEach((source) => {
        [single, column].forEach((catalogue) => {
            assert.deepEqual(toSource(toDisplay(source, catalogue), catalogue), { source, unknown: [] }, source);
        });
    });
}

{
    // Review Focus 2: a name between quotes is the user's text, both ways.
    assert.equal(toDisplay('"[Cliente · Nome]" & customer.name', single), '"[Cliente · Nome]" & [Cliente · Nome]');
    assert.deepEqual(toSource('"[Cliente · Nome]"', single), { source: '"[Cliente · Nome]"', unknown: [] });
    assert.equal(toDisplay('"customer.name"', single), '"customer.name"');
    // An escaped quote does not end the literal; an unterminated one runs to the end.
    assert.equal(toDisplay('"a"" customer.name"', single), '"a"" customer.name"');
    assert.equal(toDisplay('customer.nif & "customer.name', single), '[Cliente · NIF] & "customer.name');
    assert.deepEqual(toSource('"[Nada', single), { source: '"[Nada', unknown: [] });
}

{
    // Review Focus 1: a key with no name in the catalogue (field renamed or removed) stays
    // technical, and the formula is kept as it was.
    assert.equal(toDisplay('customer.old_field & customer.name', single), 'customer.old_field & [Cliente · Nome]');
    assert.deepEqual(toSource('customer.old_field', single), { source: 'customer.old_field', unknown: [] });

    // Whole tokens only: a longer key is not the shorter one with a tail.
    assert.equal(toDisplay('customer.name_full', single), 'customer.name_full');

    // A number is not a key; a name already shown is left as it is.
    assert.equal(toDisplay('2.5 * [Cliente · NIF]', single), '2.5 * [Cliente · NIF]');
}

{
    // An unknown name is reported and left intact; so is one not yet closed.
    assert.deepEqual(toSource('[Inexistente] & customer.name', single), {
        source: '[Inexistente] & customer.name', unknown: ['[Inexistente]'],
    });
    assert.deepEqual(toSource('[Cliente · Nom', single), { source: '[Cliente · Nom', unknown: ['[Cliente · Nom'] });

    // Matching is exact after trimming spaces inside the brackets.
    assert.deepEqual(toSource('[ Cliente · Nome ]', single), { source: 'customer.name', unknown: [] });
    assert.deepEqual(toSource('[cliente · nome]', single).unknown, ['[cliente · nome]']);
    assert.deepEqual(toSource('[Cliente·Nome]', single).unknown, ['[Cliente·Nome]']);

    // A technical key typed by hand passes through.
    assert.deepEqual(toSource('customer.name & [Cliente · NIF]', single), { source: 'customer.name & customer.nif', unknown: [] });
}

{
    // Equal labels in one model, or a label that cannot stand in brackets: technical keys.
    assert.equal(toDisplay('document.notes', single), 'document.notes');
    assert.equal(toDisplay('document.notes_extra', single), 'document.notes_extra');
    assert.equal(toDisplay('document.ref', single), 'document.ref');
    assert.deepEqual(toSource('[Documento · Notas]', single).unknown, ['[Documento · Notas]']);

    const items = single.groups.find((group) => group.id === 'document').items;
    assert.deepEqual(items.find((item) => item.key === 'document.notes'), {
        key: 'document.notes', label: 'Notas', display: 'document.notes', insert: 'document.notes',
    });

    // Two models with the same label collide too.
    const twins = scopedCatalogue({ nodes: [
        { id: 'a', label: 'X', fields: [{ key: 'a.n', label: 'N' }] },
        { id: 'b', label: 'X', fields: [{ key: 'b.n', label: 'N' }] },
    ] });
    assert.equal(toDisplay('a.n & b.n', twins), 'a.n & b.n');
}

{
    // A label holding the separator can collide with a model + field name: every key
    // claiming that name falls back to its key, in both directions.
    const dotted = scopedCatalogue({ nodes: [
        { id: 'a', label: 'A', fields: [{ key: 'a.bc', label: 'B · C' }] },
        { id: 'ab', label: 'A · B', fields: [{ key: 'ab.c', label: 'C' }, { key: 'ab.d', label: 'D' }] },
    ] });
    assert.equal(toDisplay('a.bc & ab.c & ab.d', dotted), 'a.bc & ab.c & [A · B · D]');
    assert.deepEqual(toSource('[A · B · C]', dotted).unknown, ['[A · B · C]']);
    assert.deepEqual(toSource('[A · B · D]', dotted), { source: 'ab.d', unknown: [] });

    // A column whose label is a model + field name: short, it collides, so the row item
    // and the formula both show it long — the same text in the list and in the box.
    const clash = {
        nodes: [{ id: 'customer', label: 'Cliente', fields: [{ key: 'customer.name', label: 'Nome' }] }],
        bindings: [{ key: 'lines', label: 'Linhas', type: 'collection', columns: [{ key: 'client', label: 'Cliente · Nome' }] }],
    };
    const clashColumn = scopedCatalogue(clash, 'lines');
    assert.equal(toDisplay('lines.client & customer.name', clashColumn), '[Linhas · Cliente · Nome] & customer.name');
    assert.deepEqual(clashColumn.groups[0].items.map((item) => item.display), ['[Linhas · Cliente · Nome]']);
    assert.deepEqual(toSource('[Linhas · Cliente · Nome] & customer.name', clashColumn), {
        source: 'lines.client & customer.name', unknown: [],
    });
}

{
    // A label that is a function's name is still a name: brackets keep it apart.
    const named = scopedCatalogue({
        nodes: [{ id: 'totals', label: 'Totais', fields: [{ key: 'totals.sum', label: 'SUM' }] }],
        bindings: [{ key: 'lines', label: 'Linhas', type: 'collection', columns: [{ key: 'count', label: 'COUNT' }] }],
        formulaFunctions: ['SUM', 'COUNT'],
    }, 'lines');
    assert.equal(toDisplay('lines.count + totals.sum', named), '[COUNT] + [Totais · SUM]');
    assert.deepEqual(toSource('[COUNT] + [Totais · SUM]', named), { source: 'lines.count + totals.sum', unknown: [] });
    assert.deepEqual(toSource('SUM([COUNT]) + COUNT([Linhas · COUNT])', named), {
        source: 'SUM(lines.count) + COUNT(lines.count)', unknown: [],
    });
    assert.equal(toDisplay('SUM(lines.count)', named), 'SUM([Linhas · COUNT])');
}

/* ------------------------------------------------------------ catalogue */

{
    // A single field: one group per model, then the table totals; no "this line".
    assert.deepEqual(single.groups.map((group) => [group.id, group.kind]), [
        ['customer', 'singular'], ['document', 'singular'], ['totals', 'totals'],
    ]);
    assert.deepEqual(single.groups[0], {
        id: 'customer', label: 'Cliente', kind: 'singular', items: [
            { key: 'customer.name', label: 'Nome', display: '[Cliente · Nome]', insert: '[Cliente · Nome]' },
            { key: 'customer.nif', label: 'NIF', display: '[Cliente · NIF]', insert: '[Cliente · NIF]' },
        ],
    });

    // SUM on the numeric columns, one COUNT per collection; never `formula.*`.
    const totals = single.groups.find((group) => group.id === 'totals');
    assert.equal(totals.label, 'Totais da tabela');
    assert.deepEqual(totals.items.map((item) => item.insert), [
        'SUM([Linhas · Quantidade])', 'SUM([Linhas · Valor])', 'COUNT([Linhas · Código])',
    ]);
    assert.deepEqual(totals.items[1], {
        key: 'lines.value', label: 'Valor', display: '[Linhas · Valor]', insert: 'SUM([Linhas · Valor])',
    });
    assert.ok(!JSON.stringify(single.groups).includes('formula.'));
}

{
    // A column: "this line" first, with short names, then the models, then the totals.
    assert.deepEqual(column.groups.map((group) => group.kind), ['row', 'singular', 'singular', 'totals']);
    assert.deepEqual(column.groups[0], {
        id: 'row', label: 'Esta linha', kind: 'row', items: [
            { key: 'lines.code', label: 'Código', display: '[Código]', insert: '[Código]' },
            { key: 'lines.quantity', label: 'Quantidade', display: '[Quantidade]', insert: '[Quantidade]' },
            { key: 'lines.value', label: 'Valor', display: '[Valor]', insert: '[Valor]' },
        ],
    });

    // A collection with no column declaring a format: SUM is offered on every column.
    const plain = scopedCatalogue({ bindings: [
        { key: 'items', label: 'Itens', type: 'collection', columns: [{ key: 'a', label: 'A' }, { key: 'b', label: 'B' }] },
    ] });
    assert.deepEqual(plain.groups[0].items.map((item) => item.insert), ['SUM([Itens · A])', 'SUM([Itens · B])', 'COUNT([Itens · A])']);
    assert.deepEqual(scopedCatalogue({}).groups, []);
}

{
    // The functions, with what the reference shows and the group they are listed under.
    const sum = single.functions.find((fn) => fn.name === 'SUM');
    assert.deepEqual(sum, {
        name: 'SUM', signature: 'SUM(coleção.coluna)', description: 'Soma a coluna.', example: 'SUM(lines.value)', group: 'totals',
    });
    assert.deepEqual(single.functions.map((fn) => fn.group), ['conditions', 'totals', 'totals', 'text', 'dates', 'other']);
    assert.equal(single.functions.find((fn) => fn.name === 'UPPER').signature, 'UPPER()');
    assert.equal(functionGroupOf('ROUND'), 'math');
    assert.deepEqual(Object.keys(FUNCTION_GROUPS), ['math', 'totals', 'conditions', 'text', 'dates']);
}

/* --------------------------------------------------- quick examples (§6.4) */

{
    // The data field's quick examples, built on the type's own fields: a text field in the
    // middle of a sentence, that field in capitals, and a total of the table.
    const typed = {
        ...config,
        nodes: config.nodes.map((node) => (node.id === 'customer'
            ? { ...node, fields: [{ key: 'customer.nif', label: 'NIF', type: 'number' }, { key: 'customer.name', label: 'Nome', type: 'text' }] }
            : node)),
        strings: {
            ...config.strings,
            'formula.quick.text': 'Texto com campo no meio',
            'formula.quick.text_source': '"Antes " & :field & " depois $&"',
            'formula.quick.upper': 'Maiúsculas',
            'formula.quick.total': 'Total da tabela',
        },
    };
    const catalogue = scopedCatalogue(typed);

    assert.deepEqual(quickExamples(typed, catalogue), [
        { id: 'text', label: 'Texto com campo no meio', source: '"Antes " & customer.name & " depois $&"' },
        { id: 'upper', label: 'Maiúsculas', source: 'UPPER(customer.name)' },
        { id: 'total', label: 'Total da tabela', source: 'SUM(lines.quantity)' },
    ]);
    assert.equal(toDisplay(quickExamples(typed, catalogue)[2].source, catalogue), 'SUM([Linhas · Quantidade])');

    // A type with no text field, or no table: those examples are not offered.
    assert.deepEqual(quickExamples(config, single).map((example) => example.id), ['total']);
    assert.deepEqual(quickExamples({}, scopedCatalogue({})), []);

    // The field's type may come from the bindings when the node does not carry it.
    const fromBindings = { nodes: [{ id: 'd', label: 'D', fields: [{ key: 'd.t', label: 'T' }] }], bindings: [{ key: 'd.t', type: 'text' }] };
    assert.deepEqual(quickExamples(fromBindings, scopedCatalogue(fromBindings)).map((example) => example.source), [
        '"Text before " & d.t & " text after"', 'UPPER(d.t)',
    ]);
}

/* ---------------------------------------------- suggestions with names */

const inserts = (list) => list.map((item) => item.insert);

{
    // `[`: the names whose text, or whose field label, starts with what follows it.
    assert.deepEqual(inserts(suggestionsAt('[Cli', 4, single)), ['[Cliente · Nome]', '[Cliente · NIF]']);
    assert.deepEqual(inserts(suggestionsAt('[N', 2, single)), ['[Cliente · Nome]', '[Cliente · NIF]', '[Documento · Número]']);
    assert.equal(suggestionsAt('[Cli', 4, single)[0].group, 'Cliente');

    // Behind `[`, a total is its column's name: the user is writing the name, not the SUM.
    assert.deepEqual(inserts(suggestionsAt('SUM([Val', 8, single)), ['[Linhas · Valor]']);

    // In a column the row's own short names come first.
    assert.deepEqual(inserts(suggestionsAt('[Q', 2, column)), ['[Quantidade]', '[Linhas · Quantidade]']);

    // Inside quotes, or with a name already closed before the caret: nothing.
    assert.deepEqual(suggestionsAt('"[Cli', 5, single), []);
    assert.deepEqual(suggestionsAt('[Cliente · Nome]', 16, single), []);
}

{
    // Plain letters: the names whose field label starts with them, then the functions.
    assert.deepEqual(inserts(suggestionsAt('Nom', 3, single)), ['[Cliente · Nome]']);
    assert.deepEqual(inserts(suggestionsAt('U', 1, single)), ['UPPER(']);
    assert.deepEqual(inserts(suggestionsAt('Núm', 3, single)), ['[Documento · Número]']);
    // A total is offered whole; right after `SUM(` only its name.
    assert.deepEqual(inserts(suggestionsAt('Val', 3, single)), ['SUM([Linhas · Valor])']);
    assert.deepEqual(inserts(suggestionsAt('SUM(Val', 7, single)), ['[Linhas · Valor]']);
    // Names that fell back to their key are found by it.
    assert.deepEqual(inserts(suggestionsAt('document', 8, single)), ['document.notes', 'document.notes_extra', 'document.ref']);

    // A typed dotted key still completes to keys.
    assert.deepEqual(inserts(suggestionsAt('customer.n', 10, single)), ['customer.name', 'customer.nif']);
}

{
    // Applying a name replaces from the `[`, through its `]` when the caret is inside one.
    assert.deepEqual(applySuggestion('1 + [Cli', 8, { insert: '[Cliente · Nome]' }), { source: '1 + [Cliente · Nome]', caret: 20 });
    assert.deepEqual(applySuggestion('[Cli] & 1', 4, { insert: '[Cliente · Nome]' }), { source: '[Cliente · Nome] & 1', caret: 16 });
    assert.deepEqual(applySuggestion('Nom & 1', 3, { insert: '[Cliente · Nome]' }), { source: '[Cliente · Nome] & 1', caret: 16 });
}
