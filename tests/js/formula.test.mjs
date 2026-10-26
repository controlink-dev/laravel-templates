import assert from 'node:assert/strict';
import {
    applySuggestion, createFormulaPreview, formulaCatalogue, formulaFunctionsOf, formulaLock, freshFormulaKey, isFormulaKey,
    keepsKeyInFormulaBox, newFormulaId, selectionHeading, suggestionsAt,
} from '../../resources/js/editor/formula.js';
import { scopedCatalogue } from '../../resources/js/editor/formula-names.js';
import { initPreviewState, selectionOf } from '../../resources/js/editor/preview-filter.js';

/*
 * A formula field's result on the canvas comes from the server (CGE-37), which checks the
 * formula as the save does and evaluates it on the data the canvas shows. The ask waits
 * for the user to stop typing (debounce), goes out as one batch, and is cached — every
 * answer included, so the redraw an answer triggers never asks again.
 */

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const fakeFetch = (calls, answer = (item) => ({ value: `R(${item.source})`, error: null })) => async (url, init) => {
    const body = JSON.parse(init.body);
    calls.push({ url, body });

    return { ok: true, json: async () => ({ results: body.items.map(answer) }) };
};

/* ---------------------------------------------------------------- ids and keys */

{
    // Led by a letter: `{{ formula.3b… }}` is read by Twig as the number 3 and a name,
    // and the PDF fails for a layout that saved (the server refuses that key now too).
    for (let i = 0; i < 500; i++) {
        assert.match(newFormulaId(), /^[a-z][a-z0-9]{7}$/);
    }

    // Even when every random byte would pick a digit.
    const original = globalThis.crypto.getRandomValues.bind(globalThis.crypto);
    globalThis.crypto.getRandomValues = (bytes) => bytes.fill(26);
    try {
        assert.match(newFormulaId(), /^[a-z][a-z0-9]{7}$/);
    } finally {
        globalThis.crypto.getRandomValues = original;
    }

    assert.ok(isFormulaKey('formula.ab12cd34'));
    assert.ok(!isFormulaKey('totals.total_due'));
    assert.ok(!isFormulaKey(''));
    assert.ok(!isFormulaKey(undefined));
}

{
    // A copy of a formula field must not keep the original's key (Review Focus 3): two
    // formulas sharing one key are refused on save (V-19).
    let n = 0;
    const make = () => ['zzzzzzz1', 'aaaaaaaa', 'bbbbbbbb'][n++];

    assert.equal(freshFormulaKey(['formula.aaaaaaaa'], 'formula.cccccccc', make), 'formula.cccccccc');
    assert.equal(n, 0);

    // Collides: a new id, and never one that collides again.
    n = 1;
    assert.equal(freshFormulaKey(['formula.aaaaaaaa', 'formula.zzzzzzz1'], 'formula.aaaaaaaa', make), 'formula.bbbbbbbb');

    // Not a formula key: left alone.
    assert.equal(freshFormulaKey(['totals.total'], 'totals.total', make), 'totals.total');
}

/* ------------------------------------------------------------- live preview */

{
    // Asks within the debounce window go out as ONE request; null until it answers.
    const calls = [];
    let ready = 0;
    const preview = createFormulaPreview({ url: '/f', headers: { 'X-T': '1' }, fetchImpl: fakeFetch(calls), debounceMs: 30, onReady: () => ready++ });

    assert.equal(preview.result('1 + 1'), null);
    await wait(10);
    assert.equal(preview.result('SUM(lines.total)', 'lines'), null);
    await wait(10);

    // Still inside the window measured from the LAST new ask: nothing sent yet.
    assert.equal(calls.length, 0);

    await wait(60);

    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, '/f');
    assert.deepEqual(calls[0].body, { items: [{ source: '1 + 1' }, { source: 'SUM(lines.total)', collection: 'lines' }] });
    assert.equal(ready, 1);

    assert.deepEqual(preview.result('1 + 1'), { value: 'R(1 + 1)', error: null });
    assert.deepEqual(preview.result('SUM(lines.total)', 'lines'), { value: 'R(SUM(lines.total))', error: null });

    await wait(60);
    assert.equal(calls.length, 1);
}

{
    // A server-side error is a result like any other, and cached: no second request.
    const calls = [];
    const preview = createFormulaPreview({
        url: '/f', fetchImpl: fakeFetch(calls, () => ({ value: '', error: 'not written correctly' })), debounceMs: 5,
    });

    assert.equal(preview.result('1 +'), null);
    await wait(30);
    assert.deepEqual(preview.result('1 +'), { value: '', error: 'not written correctly' });
    await wait(30);
    assert.equal(calls.length, 1);
}

{
    // A network failure shows nothing rather than a false error, and is not retried in a loop.
    let calls = 0;
    const preview = createFormulaPreview({
        url: '/f', fetchImpl: async () => { calls++; throw new Error('offline'); }, debounceMs: 5,
    });

    assert.equal(preview.result('2 * 3'), null);
    await wait(30);
    assert.deepEqual(preview.result('2 * 3'), { value: '', error: null });
    await wait(30);
    assert.equal(calls, 1);

    // A non-OK answer is treated the same.
    const failing = createFormulaPreview({ url: '/f', fetchImpl: async () => ({ ok: false, json: async () => ({}) }), debounceMs: 5 });
    failing.result('2 * 3');
    await wait(30);
    assert.deepEqual(failing.result('2 * 3'), { value: '', error: null });
}

{
    // Asked again while in flight: not queued a second time.
    const calls = [];
    let release;
    const gate = new Promise((resolve) => { release = resolve; });
    const preview = createFormulaPreview({
        url: '/f',
        fetchImpl: async (url, init) => { calls.push(JSON.parse(init.body)); await gate; return { ok: true, json: async () => ({ results: [{ value: 6, error: null }] }) }; },
        debounceMs: 5,
    });

    preview.result('2 * 3');
    await wait(20);
    assert.equal(preview.result('2 * 3'), null);
    await wait(20);
    release();
    await wait(5);

    assert.equal(calls.length, 1);
    assert.deepEqual(preview.result('2 * 3'), { value: 6, error: null });
}

{
    // Typing: a newer ask from the same slot replaces the older one still waiting, so the
    // intermediate texts of one textarea are never sent.
    const calls = [];
    const preview = createFormulaPreview({ url: '/f', fetchImpl: fakeFetch(calls), debounceMs: 20 });

    preview.result('1', null, 'c1');
    preview.result('1 +', null, 'c1');
    preview.result('1 + 2', null, 'c1');
    preview.result('9', null, 'c2');
    await wait(60);

    assert.equal(calls.length, 1);
    assert.deepEqual(calls[0].body.items, [{ source: '1 + 2' }, { source: '9' }]);
}

{
    // Empty source and no endpoint: nothing asked, null.
    const calls = [];
    const preview = createFormulaPreview({ url: '/f', fetchImpl: fakeFetch(calls), debounceMs: 5 });
    assert.equal(preview.result('   '), null);

    const offline = createFormulaPreview({ url: '', fetchImpl: fakeFetch(calls), debounceMs: 5 });
    assert.equal(offline.result('1 + 1'), null);

    await wait(30);
    assert.equal(calls.length, 0);
}

{
    // More than the endpoint's 50 items: several requests, never a refused batch.
    const calls = [];
    const preview = createFormulaPreview({ url: '/f', fetchImpl: fakeFetch(calls), debounceMs: 5 });

    for (let i = 0; i < 51; i++) {
        preview.result(String(i));
    }

    await wait(40);
    assert.deepEqual(calls.map((call) => call.body.items.length), [50, 1]);
    assert.deepEqual(preview.result('50'), { value: 'R(50)', error: null });
}

{
    // A real record picked in the sample panel: sent as `selection`, and part of the key —
    // picking another record asks again.
    const calls = [];
    let selection = null;
    const preview = createFormulaPreview({ url: '/f', fetchImpl: fakeFetch(calls), debounceMs: 5, selection: () => selection });

    preview.result('customer.name & "!"');
    await wait(30);
    assert.equal(calls[0].body.selection, undefined);
    assert.notEqual(preview.result('customer.name & "!"'), null);

    selection = { nodes: { customer: '7' }, collections: {} };
    assert.equal(preview.result('customer.name & "!"'), null);
    await wait(30);
    assert.equal(calls.length, 2);
    assert.deepEqual(calls[1].body.selection, { nodes: { customer: '7' }, collections: {} });
}

/* --------------------------------------------------------- the sample selection */

{
    // The ids the PDF preview's `selection` takes (VersionController::preview): the chosen
    // record per node, and the ids of the rows shown per collection.
    const config = {};
    const state = initPreviewState({}, config);

    assert.equal(selectionOf(state), null);

    state.setNode('customer', { 'customer.name': 'Ana' }, '42');
    state.setRows('lines', [{ id: '1', 'lines.qty': 2 }, { id: '3', 'lines.qty': 1 }]);

    assert.deepEqual(selectionOf(state), { nodes: { customer: '42' }, collections: { lines: ['1', '3'] } });

    state.setNode('customer', null);
    state.setRows('lines', []);
    assert.equal(selectionOf(state), null);
    assert.equal(selectionOf(undefined), null);
}

/* --------------------------------------------------------- the panel header */

{
    // A formula field is named "Fórmula" with its own key, never after the binding it had.
    const strings = { 'formula.label': 'Fórmula' };

    assert.deepEqual(selectionHeading('formula.ab12cd34', undefined, 'Data de fornecimento', strings),
        { name: 'Fórmula', meta: ['formula.ab12cd34'] });

    // Any other binding: its catalogue label and key · type, as before.
    assert.deepEqual(selectionHeading('document.supply_date', { label: 'Supply date', type: 'date' }, 'x', strings),
        { name: 'Supply date', meta: ['document.supply_date', 'date'] });
    assert.deepEqual(selectionHeading('', undefined, 'Texto', strings), { name: 'Texto', meta: [] });
}

/* ------------------------------------------------------------- autocomplete */

/*
 * The formula box suggests what can be typed at the caret. With the per-place catalogue
 * (`scopedCatalogue`), letters offer the fields by their readable name and the functions;
 * a dotted token is a technical key typed by hand and completes to keys. Names after a
 * `[` are covered in formula-names.test.mjs.
 */

const catalogue = scopedCatalogue({
    nodes: [
        { id: 'customer', label: 'Cliente', fields: [
            { key: 'customer.name', label: 'Nome' },
            { key: 'customer.nif', label: 'NIF' },
        ] },
        { id: 'document', label: 'Documento', fields: [{ key: 'document.number', label: 'Número' }] },
    ],
    bindings: [
        { key: 'lines', label: 'Linhas', type: 'collection', columns: [
            { key: 'quantity', label: 'Quantidade' },
            { key: 'unit_price', label: 'Preço unitário' },
        ] },
    ],
    formulaFunctions: ['IF', 'SUM', 'UPPER', 'LOWER', 'LEN', 'LEFT'],
});

const inserts = (list) => list.map((item) => item.insert);

{
    // "UP": the functions whose name starts with it, case-insensitively, inserted with `(`.
    assert.ok(inserts(suggestionsAt('UP', 2, catalogue)).includes('UPPER('));
    assert.ok(inserts(suggestionsAt('up', 2, catalogue)).includes('UPPER('));
    assert.ok(!inserts(suggestionsAt('UP', 2, catalogue)).includes('LOWER('));

    // A function name typed in full, in either case, still completes to its bracket.
    assert.deepEqual(inserts(suggestionsAt('SUM', 3, catalogue)), ['SUM(']);
    assert.deepEqual(inserts(suggestionsAt('sum', 3, catalogue)), ['SUM(']);

    // A field is offered by its label and inserted by its name; the group is its model.
    const nom = suggestionsAt('nom', 3, catalogue);
    assert.deepEqual(inserts(nom), ['[Cliente · Nome]']);
    assert.equal(nom[0].group, 'Cliente');
    assert.equal(nom[0].label, 'Nome');
    assert.deepEqual(inserts(suggestionsAt('L', 1, catalogue)), ['LOWER(', 'LEN(', 'LEFT(']);

    // A column of the table is a total here: offered whole, names first, then functions.
    assert.deepEqual(inserts(suggestionsAt('Q', 1, catalogue)), ['SUM([Linhas · Quantidade])', 'COUNT([Linhas · Quantidade])']);
    assert.deepEqual(inserts(suggestionsAt('Pre', 3, catalogue)), ['SUM([Linhas · Preço unitário])']);
}

{
    // "customer.": that model's fields, by their full key, grouped under the model.
    const list = suggestionsAt('customer.', 9, catalogue);
    assert.deepEqual(inserts(list), ['customer.name', 'customer.nif']);
    assert.deepEqual(list.map((item) => item.group), ['Cliente', 'Cliente']);
    assert.deepEqual(list.map((item) => item.label), ['Nome', 'NIF']);
    assert.deepEqual(inserts(suggestionsAt('customer.n', 10, catalogue)), ['customer.name', 'customer.nif']);
    assert.deepEqual(inserts(suggestionsAt('customer.na', 11, catalogue)), ['customer.name']);
}

{
    // "SUM(lines.": the token starts after the bracket — the collection's columns.
    const source = 'SUM(lines.';
    const list = suggestionsAt(source, source.length, catalogue);
    assert.deepEqual(inserts(list), ['lines.quantity', 'lines.unit_price']);
    assert.ok(list.every((item) => item.group === 'Linhas'));
}

{
    // A caret in the middle of the text reads only the token under it.
    const source = 'customer.name & UP & document.number';
    const caret = source.indexOf('UP') + 2;
    assert.deepEqual(inserts(suggestionsAt(source, caret, catalogue)), ['UPPER(']);

    // A caret inside a word offers nothing: applying there would keep the rest of the word
    // after the insert (`customer.na|me` ⇒ `customer.nameme`), a label's letters included.
    const mid = source.indexOf('document') + 3;
    assert.deepEqual(suggestionsAt(source, mid, catalogue), []);
    assert.deepEqual(suggestionsAt('customer.name', 11, catalogue), []);
    assert.deepEqual(suggestionsAt('cu.', 2, catalogue), []);
    assert.deepEqual(suggestionsAt('Núm', 1, catalogue), []);

    // Followed by anything else (a bracket, a space, an operator), it completes as usual;
    // right after `SUM(` a total is only its column's name.
    assert.deepEqual(inserts(suggestionsAt('SUM(Qu)', 6, catalogue)), ['[Linhas · Quantidade]']);
    assert.deepEqual(inserts(suggestionsAt('Nom + 1', 3, catalogue)), ['[Cliente · Nome]']);
}

{
    // Nothing to complete: no token, an operator or a space before the caret, a number,
    // an unknown model, or a token that already is the whole suggestion.
    assert.deepEqual(suggestionsAt('', 0, catalogue), []);
    assert.deepEqual(suggestionsAt('1 + ', 4, catalogue), []);
    assert.deepEqual(suggestionsAt('0.2', 3, catalogue), []);
    assert.deepEqual(suggestionsAt('nothing.', 8, catalogue), []);
    assert.deepEqual(suggestionsAt('customer.name', 13, catalogue), []);
    assert.deepEqual(suggestionsAt('xyz', 3, catalogue), []);
}

{
    // Inside a text literal the words are the user's own: nothing is suggested. `""` is an
    // escaped quote, so an odd run of quotes before the caret leaves it inside.
    assert.deepEqual(suggestionsAt('"UP', 3, catalogue), []);
    assert.deepEqual(suggestionsAt('"a""UP', 6, catalogue), []);
    assert.deepEqual(suggestionsAt('"Nom', 4, catalogue), []);
    assert.ok(inserts(suggestionsAt('"a" & UP', 8, catalogue)).includes('UPPER('));
}

{
    // At most 20 suggestions, by key or by name.
    const many = scopedCatalogue({
        nodes: [{ id: 'big', label: 'Big', fields: Array.from({ length: 30 }, (_, i) => ({ key: `big.f${i}`, label: `F${i}` })) }],
    });
    assert.equal(suggestionsAt('big.', 4, many).length, 20);
    assert.equal(suggestionsAt('F', 1, many).length, 20);
}

{
    // The flat catalogue (what the formula box passes until it shows names) still offers
    // the models and collections with their dot, then the functions: no name is inserted
    // into a box that stores the source as it is typed.
    const flat = {
        nodes: catalogue.nodes,
        collections: catalogue.collections,
        functions: ['IF', 'SUM', 'UPPER', 'LOWER', 'LEN', 'LEFT'],
    };
    const cu = suggestionsAt('cu', 2, flat);
    assert.deepEqual(inserts(cu), ['customer.']);
    assert.equal(cu[0].group, 'Cliente');
    assert.deepEqual(inserts(suggestionsAt('li', 2, flat)), ['lines.']);
    assert.deepEqual(inserts(suggestionsAt('L', 1, flat)), ['lines.', 'LOWER(', 'LEN(', 'LEFT(']);
    assert.deepEqual(inserts(suggestionsAt('SUM(li)', 6, flat)), ['lines.']);
    assert.deepEqual(suggestionsAt('Nom', 3, flat), []);
    assert.deepEqual(suggestionsAt('[Cli', 4, flat), []);
}

{
    // Applying a suggestion replaces only the token before the caret and puts the caret
    // after what was inserted.
    const source = 'customer.name & UP & 1';
    const caret = source.indexOf('UP') + 2;
    const applied = applySuggestion(source, caret, { insert: 'UPPER(' });
    assert.deepEqual(applied, { source: 'customer.name & UPPER( & 1', caret: source.indexOf('UP') + 6 });

    const sum = applySuggestion('SUM(lines.qu)', 12, { insert: 'lines.quantity' });
    assert.deepEqual(sum, { source: 'SUM(lines.quantity)', caret: 18 });

    // A caret mid-word keeps what is after it.
    assert.deepEqual(applySuggestion('cusX', 3, { insert: 'customer.' }), { source: 'customer.X', caret: 9 });

    // A label's letters are one word with the rest: `Preç` is replaced whole.
    assert.deepEqual(applySuggestion('1 + Preç', 8, { insert: '[Linhas · Preço unitário]' }), {
        source: '1 + [Linhas · Preço unitário]', caret: 29,
    });
}

/* ------------------------------------------------------- the catalogue for it */

{
    // Built from the editor config: reachable models only, collections from the bindings
    // (not twice when the graph also lists them as nodes), and never `formula.*` — a
    // formula cannot read another formula.
    const config = {
        nodes: [
            { id: 'customer', label: 'Cliente', collection: false, reachable: true, fields: [
                { key: 'customer.name', label: 'Nome' },
                { key: 'formula.ab12cd34', label: 'Fórmula' },
            ] },
            { id: 'lines', label: 'Linhas', collection: true, fields: [] },
            { id: 'orphan', label: 'Solto', collection: false, reachable: false, fields: [{ key: 'orphan.x', label: 'X' }] },
            { id: 'formula', label: 'Fórmulas', fields: [{ key: 'formula.zz', label: 'Z' }] },
        ],
        bindings: [
            { key: 'lines', label: 'Linhas', type: 'collection', columns: [{ key: 'quantity', label: 'Quantidade' }] },
            { key: 'customer.name', label: 'Nome', type: 'text' },
        ],
        formulaFunctions: ['SUM', 'IF'],
    };

    assert.deepEqual(formulaCatalogue(config), {
        nodes: [{ id: 'customer', label: 'Cliente', fields: [{ key: 'customer.name', label: 'Nome' }] }],
        collections: [{ key: 'lines', label: 'Linhas', columns: [{ key: 'quantity', label: 'Quantidade' }] }],
        functions: ['SUM', 'IF'],
    });
    assert.deepEqual(formulaCatalogue({}), { nodes: [], collections: [], functions: [] });
}

{
    // The function list comes from the server's strings (Formula::functions() on the PHP
    // side), one signature per function, in its order: PHP holds the only list.
    const strings = {
        'formula.label': 'Fórmula',
        'formula.signature.IF': 'IF(condição, se_verdadeiro, se_falso)',
        'formula.description.IF': 'Escolhe.',
        'formula.signature.SUM': 'SUM(coluna)',
        'formula.description.SUM': 'Soma.',
    };

    assert.deepEqual(formulaFunctionsOf(strings), ['IF', 'SUM']);
    assert.deepEqual(formulaFunctionsOf({}), []);
    assert.deepEqual(formulaFunctionsOf(undefined), []);
}


{
    // Which bindings may never become a formula (spec §2/§6): a legal one. A mandatory one
    // may (CGE-69): the formula naming it places it.
    assert.equal(formulaLock({ key: 'a.b', legal: true, mandatory: true }), 'legal');
    assert.equal(formulaLock({ key: 'a.b', mandatory: true }), null);
    assert.equal(formulaLock({ key: 'a.b', legal: false, mandatory: false }), null);
    assert.equal(formulaLock({ key: 'a.b' }), null);
    assert.equal(formulaLock(undefined), null);

    // Only the LAST block carrying it is held (spec §6.1): with another data field on the
    // same binding, a redundant copy may become a formula.
    assert.equal(formulaLock({ key: 'a.b', legal: true, mandatory: true }, 0), 'legal');
    assert.equal(formulaLock({ key: 'a.b', legal: true, mandatory: true }, 1), null);
    assert.equal(formulaLock({ key: 'a.b', mandatory: true }, 2), null);
    assert.equal(formulaLock({ key: 'a.b' }, 0), null);
}

{
    // The formula box keeps plain keys (GrapesJS's Delete, Backspace, arrows) and the
    // text-editing chords to itself; every other Ctrl/Meta chord — Ctrl+S above all —
    // reaches the host's shortcuts.
    const key = (k, mods = {}) => ({ key: k, ctrlKey: false, metaKey: false, altKey: false, shiftKey: false, ...mods });

    for (const event of [
        key('a'), key('Delete'), key('Backspace'), key('ArrowLeft'), key('Enter'), key('S', { shiftKey: true }),
        key('z', { ctrlKey: true }), key('Z', { ctrlKey: true, shiftKey: true }), key('y', { ctrlKey: true }),
        key('a', { metaKey: true }), key('c', { ctrlKey: true }), key('v', { ctrlKey: true }), key('x', { metaKey: true }),
        key('Backspace', { ctrlKey: true }), key('ArrowLeft', { ctrlKey: true }), key('Delete', { metaKey: true }),
        // AltGr arrives as Ctrl+Alt on Windows: `€`, `@`, `{` are typing, not shortcuts.
        key('€', { ctrlKey: true, altKey: true }), key('@', { ctrlKey: true, altKey: true }),
    ]) {
        assert.equal(keepsKeyInFormulaBox(event), true, JSON.stringify(event));
    }

    for (const event of [
        key('s', { ctrlKey: true }), key('S', { metaKey: true }), key('s', { ctrlKey: true, shiftKey: true }),
        key('p', { ctrlKey: true }), key('k', { metaKey: true }), key('1', { ctrlKey: true }),
    ]) {
        assert.equal(keepsKeyInFormulaBox(event), false, JSON.stringify(event));
    }
}

console.log('formula: ok');
