import assert from 'node:assert/strict';
import { createFormatPreview } from '../../resources/js/editor/format-preview.js';

/*
 * The canvas shows a sample value exactly as the PDF prints it, so it asks the server,
 * which formats it through the same filters the renderer uses (CGE-49). The ask is
 * batched per tick and cached; until it answers, the raw value is shown.
 */

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

const fakeFetch = (calls) => async (url, init) => {
    const body = JSON.parse(init.body);
    calls.push({ url, body });

    return {
        ok: true,
        json: async () => ({ texts: body.items.map((item) => `F(${item.value}|${item.format}|${item.arguments.join(',')})`) }),
    };
};

{
    const calls = [];
    let ready = 0;
    const preview = createFormatPreview({ url: '/fmt', headers: {}, fetchImpl: fakeFetch(calls), onReady: () => ready++ });
    const date = { name: 'date', pattern: 'dd/MM/yyyy' };
    const money = { name: 'money', decimals: 2, symbol: 'code' };

    // Raw until the server answers, and the two asks of one tick go in one request.
    assert.equal(preview.text('2026-12-31', date), '2026-12-31');
    assert.equal(preview.text(10, money), '10');

    await tick();
    await tick();

    assert.equal(calls.length, 1);
    assert.deepEqual(calls[0].body.items, [
        { value: '2026-12-31', format: 'date', arguments: ['dd/MM/yyyy'] },
        { value: 10, format: 'money', arguments: [2, 'code'] },
    ]);
    assert.equal(ready, 1);

    // Answered: the server's text, from the cache, with no new request.
    assert.equal(preview.text('2026-12-31', date), 'F(2026-12-31|date|dd/MM/yyyy)');
    assert.equal(preview.text(10, money), 'F(10|money|2,code)');

    await tick();
    assert.equal(calls.length, 1);
}

{
    // No format, no request: the value is shown as it is.
    const calls = [];
    const preview = createFormatPreview({ url: '/fmt', headers: {}, fetchImpl: fakeFetch(calls), onReady: () => {} });

    assert.equal(preview.text('abc', null), 'abc');
    await tick();
    assert.equal(calls.length, 0);
}

{
    // No endpoint configured: always the raw value.
    const calls = [];
    const preview = createFormatPreview({ url: '', headers: {}, fetchImpl: fakeFetch(calls), onReady: () => {} });

    assert.equal(preview.text('2026-12-31', { name: 'date', pattern: 'dd/MM/yyyy' }), '2026-12-31');
    await tick();
    assert.equal(calls.length, 0);
}
