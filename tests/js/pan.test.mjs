import assert from 'node:assert/strict';
import { wheelMove, clampMove, wheelZoomFactor, anchorShift } from '../../resources/js/editor/pan.js';

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

// 6. A sheet that fits but was left partly outside (panned at the bottom, then the pane
//    grew — maximised or resized) is brought back in by the next tick, not stranded;
//    one already inside the air stays put whatever the wheel asks.
{
    const clippedAbove = { left: 100, top: -500, width: 600, height: 500 };

    assert.deepEqual(clampMove(canvas, clippedAbove, { x: 0, y: -100 }, AIR), { x: 0, y: 512 });

    const clippedBelow = { left: 100, top: 300, width: 600, height: 500 };

    assert.deepEqual(clampMove(canvas, clippedBelow, { x: 0, y: 100 }, AIR), { x: 0, y: -212 });

    const inside = { left: 100, top: 50, width: 600, height: 500 };

    assert.deepEqual(clampMove(canvas, inside, { x: 30, y: 30 }, AIR), { x: 0, y: 0 });
}

/*
 * Ctrl + wheel (and a trackpad pinch, which Chrome sends as a wheel with ctrlKey) zooms
 * the sheet about the mouse (CGE-82).
 */

// Wheel up zooms in, wheel down zooms out, and nothing is no zoom.
{
    assert.ok(wheelZoomFactor({ deltaY: -100 }) > 1);
    assert.ok(wheelZoomFactor({ deltaY: 100 }) < 1);
    assert.equal(wheelZoomFactor({ deltaY: 0 }), 1);
}

// A mouse notch is about one 20% step; a big jump is held to the 25% of the buttons.
{
    const notch = wheelZoomFactor({ deltaY: -100 });

    assert.ok(notch > 1.15 && notch <= 1.25, `notch ${notch}`);
    assert.equal(wheelZoomFactor({ deltaY: -10000 }), 1.25);
    assert.equal(wheelZoomFactor({ deltaY: 10000 }), 1 / 1.25);
}

// A pinch reports small deltas: a small, smooth step, not a whole notch.
{
    const pinch = wheelZoomFactor({ deltaY: -3 });

    assert.ok(pinch > 1 && pinch < 1.02, `pinch ${pinch}`);
}

// A wheel in lines (Firefox) is converted like the pan, so one line is not one pixel.
{
    assert.equal(wheelZoomFactor({ deltaY: -3, deltaMode: 1 }), wheelZoomFactor({ deltaY: -48 }));
}

// The point under the mouse stays put: the shift puts the same spot of the sheet back
// under it after the sheet grew or shrank.
{
    const before = { left: 100, top: 50, width: 600, height: 400 };
    const grown = { left: 25, top: 0, width: 750, height: 500 };

    // The centre of a sheet scaled about its centre needs no shift.
    assert.deepEqual(anchorShift({ x: 400, y: 250 }, before, grown), { x: 0, y: 0 });

    // The top-left corner: the grown sheet's corner went 75 left and 50 up.
    assert.deepEqual(anchorShift({ x: 100, y: 50 }, before, grown), { x: 75, y: 50 });

    // A quarter in: 25 + 0.25 × 750 = 212.5 → shift 250 − 212.5 = 37.5, whole px.
    assert.deepEqual(anchorShift({ x: 250, y: 150 }, before, grown), { x: 38, y: 25 });

    // And back: shrinking about the same spot of the sheet returns it to where it was.
    assert.deepEqual(anchorShift({ x: 25, y: 0 }, grown, before), { x: -75, y: -50 });
}

console.log('pan: OK');
