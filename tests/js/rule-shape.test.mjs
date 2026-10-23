import assert from 'node:assert/strict';
import { looksFilled } from '../../resources/js/editor/rule-shape.js';

/*
 * A line/rectangle loaded without its "Filled" flag (a seeded layout rebuilt from HTML, or
 * a rectangle drawn in the style panel without ticking it) is recognised by its shape, so
 * it is not flattened back to a thin black line (CGE-76).
 */

// The rules the thickness select produces: black, at most 0.79mm.
assert.equal(looksFilled({ height: '0.26mm', 'background-color': '#000000' }), false);
assert.equal(looksFilled({ height: '0.79mm', 'background-color': '#000' }), false);
assert.equal(looksFilled({ height: '0.53mm' }), false);
assert.equal(looksFilled({}), false);

// A rectangle: tall, or not black.
assert.equal(looksFilled({ height: '12.9mm', 'background-color': '#d8d8d8' }), true);
assert.equal(looksFilled({ height: '12.9mm', 'background-color': '#000000' }), true);
assert.equal(looksFilled({ height: '0.26mm', 'background-color': '#d9d9d9' }), true);
assert.equal(looksFilled({ height: '5mm' }), true);
