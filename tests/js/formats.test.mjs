import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    defaultSpecFor, expression, previewArgs, specFromAttributes, specFromColumn, specToAttributes,
} from '../../resources/js/editor/formats.js';

/*
 * The format a field prints with is written as a Twig expression by the editor and read
 * back by the server (FormatSpec). One golden file holds the canonical form both sides
 * have to produce, so they cannot drift apart (CGE-49).
 */

const cases = JSON.parse(readFileSync(new URL('../Fixtures/format-expressions.json', import.meta.url), 'utf8'));

for (const { attributes, expression: expected } of cases) {
    const spec = specFromAttributes(attributes);

    assert.equal(spec ? expression('x', spec) : '{{ x }}', expected, JSON.stringify(attributes));
}

// The attributes a spec writes read back as the same spec.
for (const { attributes } of cases) {
    const spec = specFromAttributes(attributes);

    if (spec) {
        assert.deepEqual(specFromAttributes(specToAttributes(spec)), spec);
    }
}

// A column carries the same choices as `format` + `formatArgs`.
assert.equal(
    expression('linha.total', specFromColumn({ format: 'money', formatArgs: { decimals: 2, symbol: 'code' } })),
    "{{ linha.total|money(2, 'code') }}",
);
assert.equal(specFromColumn({ format: '' }), null);

// What a freshly placed field gets, by the type of what it is bound to.
assert.deepEqual(defaultSpecFor('date'), { name: 'date', pattern: 'dd/MM/yyyy' });
assert.deepEqual(defaultSpecFor('money'), { name: 'money', decimals: null, symbol: 'after' });
assert.deepEqual(defaultSpecFor('number'), { name: 'number', decimals: 2 });
assert.equal(defaultSpecFor('text'), null);

// The positional arguments the preview endpoint receives.
assert.deepEqual(previewArgs({ name: 'money', decimals: null, symbol: 'after' }), [null, 'after']);
assert.deepEqual(previewArgs({ name: 'date', pattern: 'dd/MM/yyyy' }), ['dd/MM/yyyy']);
assert.deepEqual(previewArgs({ name: 'upper' }), []);
