import assert from 'node:assert/strict';
import { stepIndex } from '../../resources/js/editor/menu.js';

/* The arrows wrap round the "⋯" menu; from the toggle (no item focused) they enter at either end. */
assert.equal(stepIndex(0, 3, 'ArrowDown'), 1);
assert.equal(stepIndex(2, 3, 'ArrowDown'), 0);
assert.equal(stepIndex(0, 3, 'ArrowUp'), 2);
assert.equal(stepIndex(-1, 3, 'ArrowDown'), 0);
assert.equal(stepIndex(-1, 3, 'ArrowUp'), 2);
assert.equal(stepIndex(1, 3, 'Home'), 0);
assert.equal(stepIndex(1, 3, 'End'), 2);
assert.equal(stepIndex(1, 3, 'Enter'), null);
