import assert from 'node:assert/strict';
import { pageNumberText } from '../../resources/js/editor/page-number.js';

/*
 * The page-number block's text per format: the canvas preview and the exported template
 * expression come from the same function (CGE-74 adds "1 / 3").
 */
const strings = { 'page_format.of': 'de' };

// Number only.
assert.equal(pageNumberText('x', strings, '1', '3'), '1');
assert.equal(pageNumberText(undefined, strings, '1', '3'), '1');

// Number and total, with the translated word.
assert.equal(pageNumberText('x-of-y', strings, '1', '3'), '1 de 3');

// Number and total with a slash, the way the WinMax invoice prints it.
assert.equal(pageNumberText('x-slash-y', strings, '1', '3'), '1 / 3');

// The export: template expressions, with only the translated word escaped.
assert.equal(
    pageNumberText('x-slash-y', strings, '{{ pagina.numero }}', '{{ pagina.total }}', (t) => `[${t}]`),
    '{{ pagina.numero }} / {{ pagina.total }}',
);
assert.equal(
    pageNumberText('x-of-y', { 'page_format.of': '<of>' }, '{{ pagina.numero }}', '{{ pagina.total }}', (t) => t.replace(/</g, '&lt;')),
    '{{ pagina.numero }} &lt;of> {{ pagina.total }}',
);
