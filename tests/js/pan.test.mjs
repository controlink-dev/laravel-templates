import assert from 'node:assert/strict';
import { wheelMove, clampMove } from '../../resources/js/editor/pan.js';

/*
 * A zoomed sheet larger than the pane is panned with the wheel. These are the two
 * decisions behind that: how far a wheel tick asks the sheet to move, and how far it is
 * allowed to, so its edge stops at the air around it instead of sliding out of view.
 */

const canvas = { left: 0, top: 0, width: 800, height: 600 };
const AIR = 12;

// 1. A sheet that fits the pane does not move at all.
{
    const frame = { left: 100, top: 50, width: 600, height: 500 };

    assert.deepEqual(clampMove(canvas, frame, { x: -50, y: -50 }, AIR), { x: 0, y: 0 });
}

// 2. A sheet taller than the pane moves vertically, and stops at the air at either end.
{
    const atTop = { left: 100, top: 12, width: 600, height: 1200 };

    assert.deepEqual(clampMove(canvas, atTop, { x: 0, y: -100 }, AIR), { x: 0, y: -100 });
    assert.deepEqual(clampMove(canvas, atTop, { x: 0, y: 50 }, AIR), { x: 0, y: 0 });

    // The bottom edge may come up to 600 - 12, so the top may go down to -612.
    const nearBottom = { ...atTop, top: -580 };

    assert.deepEqual(clampMove(canvas, nearBottom, { x: 0, y: -100 }, AIR), { x: 0, y: -32 });
}

// 3. A sheet already past the limit (a pane resize, a space-drag) is pulled back inside
//    on the next tick, even one that asks for no movement on that axis.
{
    const pastTop = { left: 100, top: 100, width: 600, height: 1200 };

    assert.deepEqual(clampMove(canvas, pastTop, { x: 0, y: 0 }, AIR), { x: 0, y: -88 });
}

// 4. The wheel: down moves the sheet up; shift turns it horizontal, whichever delta the
//    browser filled; a line-mode delta (Firefox) is converted to pixels.
{
    assert.deepEqual(wheelMove({ deltaX: 0, deltaY: 100, deltaMode: 0, shiftKey: false }), { x: 0, y: -100 });
    assert.deepEqual(wheelMove({ deltaX: 0, deltaY: 100, deltaMode: 0, shiftKey: true }), { x: -100, y: 0 });
    assert.deepEqual(wheelMove({ deltaX: 40, deltaY: 0, deltaMode: 0, shiftKey: true }), { x: -40, y: 0 });
    assert.deepEqual(wheelMove({ deltaX: 0, deltaY: 3, deltaMode: 1, shiftKey: false }), { x: 0, y: -48 });
}

// 5. At a limit the transform leaves a sliver of a pixel; that is no movement, or a wheel
//    at the edge would be swallowed (and the page behind not scroll) for nothing.
{
    const almostAtTop = { left: 100, top: 11.9999, width: 600, height: 1200 };

    assert.deepEqual(clampMove(canvas, almostAtTop, { x: 0, y: 50 }, AIR), { x: 0, y: 0 });
}

console.log('pan: OK');
