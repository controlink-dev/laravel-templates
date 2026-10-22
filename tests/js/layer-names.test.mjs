import assert from 'node:assert/strict';
import { staticTextName } from '../../resources/js/editor/layer-names.js';

/*
 * A fixed text is named after what it says (CGE-53): seventeen layers called "Text" tell
 * the user nothing about which one is which.
 */
assert.equal(staticTextName('  DOCUMENTO ', 'Text (empty)'), 'DOCUMENTO');
assert.equal(staticTextName('BANKINTER   BIC\nPT50', 'Text (empty)'), 'BANKINTER BIC PT50');
assert.equal(staticTextName('x'.repeat(60), 'Text (empty)'), 'x'.repeat(40) + '…');
assert.equal(staticTextName('x'.repeat(40), 'Text (empty)'), 'x'.repeat(40));
assert.equal(staticTextName('', 'Text (empty)'), 'Text (empty)');
assert.equal(staticTextName('   ', 'Text (empty)'), 'Text (empty)');
assert.equal(staticTextName(undefined, 'Text (empty)'), 'Text (empty)');

console.log('layer-names: ok');
