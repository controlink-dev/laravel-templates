import assert from 'node:assert/strict';
import {
    createFormulaPreview, freshFormulaKey, isFormulaKey, newFormulaId,
} from '../../resources/js/editor/formula.js';
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
    for (let i = 0; i < 200; i++) {
        assert.match(newFormulaId(), /^[a-z0-9]{8}$/);
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

console.log('formula: ok');
