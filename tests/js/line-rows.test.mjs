import assert from 'node:assert/strict';
import {
    TEXT_CLASS, colgroupHTML, grid, isMultiRow, rowsHTML, segments, spans,
} from '../../resources/js/editor/line-rows.js';

/*
 * A line table whose items span several rows (CGE-97): the columns split before each
 * `break`, into segments laid on one shared grid. The same cases, inputs and outputs as
 * `tests/Unit/LineRowsTest.php` — the server's `LineRows` reads what this side writes.
 */

const keys = (list) => list.map((segment) => segment.map((column) => column.key));

// Columns split before each break.
{
    const cols = [{ key: 'code' }, { key: 'description' }, { key: 'unit_price', break: true }, { key: 'quantity' }, { key: 'vat_rate' }, { key: 'amount' }];

    assert.deepEqual(keys(segments(cols)), [['code', 'description'], ['unit_price', 'quantity', 'vat_rate', 'amount']]);
    assert.equal(isMultiRow(cols), true);
    assert.deepEqual(spans(cols), [[1, 3], [1, 1, 1, 1]]);
    assert.deepEqual(grid(cols).map((column) => column.key), ['unit_price', 'quantity', 'vat_rate', 'amount']);
}

// A hidden column's break moves to the next visible column; a leading break is ignored.
{
    const hidden = [{ key: 'a' }, { key: 'b', break: true, hidden: true }, { key: 'c' }, { key: 'd' }];
    assert.deepEqual(keys(segments(hidden)), [['a'], ['c', 'd']]);

    const leading = [{ key: 'a', break: true }, { key: 'b' }];
    assert.deepEqual(keys(segments(leading)), [['a', 'b']]);
    assert.equal(isMultiRow(leading), false);
}

// Without a break there is one segment of the visible columns; `break` must be `true`.
{
    const cols = [{ key: 'a' }, { key: 'b', hidden: true }, { key: 'c', break: 'yes' }];

    assert.deepEqual(keys(segments(cols)), [['a', 'c']]);
    assert.equal(isMultiRow(cols), false);
    assert.deepEqual(spans(cols), [[1, 1]]);
    assert.deepEqual(segments([]), [[]]);
    assert.deepEqual(segments([null, { key: 'a' }]).map((s) => s.map((c) => c.key)), [['a']], 'a non-object entry is skipped');
}

// The first longest segment is the grid.
{
    const cols = [{ key: 'a' }, { key: 'b' }, { key: 'c', break: true }, { key: 'd' }, { key: 'e', break: true }];

    assert.deepEqual(grid(cols).map((column) => column.key), ['a', 'b']);
    assert.deepEqual(spans(cols), [[1, 1], [1, 1], [2]]);
}

// The markup: a <col> per grid column, a <tr> per segment, colspan only when > 1.
{
    assert.equal(TEXT_CLASS, 'lines__text', 'the server\'s LineRows::TEXT_CLASS');

    const cols = [
        { key: 'code', width: '20' }, { key: 'description', width: '' },
        { key: 'unit_price', width: '12ch', break: true, align: 'right' }, { key: 'quantity', width: 'x' }, { key: 'amount', width: '20mm', align: 'right' },
    ];
    const widthOf = (column) => ({ '20': '20mm', '12ch': '12ch', '20mm': '20mm' })[column.width] ?? '';

    assert.equal(colgroupHTML(cols, widthOf), '<colgroup><col style="width:12ch"><col><col style="width:20mm"></colgroup>');

    assert.equal(
        rowsHTML(cols, 'th', (column) => column.key.toUpperCase()),
        '<tr><th style="text-align:left">CODE</th><th colspan="2" style="text-align:left">DESCRIPTION</th></tr>'
        + '<tr><th style="text-align:right">UNIT_PRICE</th><th style="text-align:left">QUANTITY</th><th style="text-align:right">AMOUNT</th></tr>',
    );

    assert.equal(
        rowsHTML(cols, 'td', (column) => `{{ ${column.key} }}`, (column) => (column.key === 'description' ? TEXT_CLASS : '')),
        '<tr><td style="text-align:left">{{ code }}</td><td colspan="2" class="lines__text" style="text-align:left">{{ description }}</td></tr>'
        + '<tr><td style="text-align:right">{{ unit_price }}</td><td style="text-align:left">{{ quantity }}</td><td style="text-align:right">{{ amount }}</td></tr>',
    );
}

console.log('line-rows: ok');
