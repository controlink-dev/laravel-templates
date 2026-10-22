import assert from 'node:assert/strict';
import { offsetCopy } from '../../resources/js/editor/copy-offset.js';

/*
 * A copied block lands 3mm right and down of the one it was copied from, by the side it
 * is anchored to, and never outside its zone (CGE-53).
 */
const zone = { width: 180, height: 55 };

// Anchored top/left: plain +3mm on each axis.
assert.deepEqual(
    offsetCopy({ left: '20mm', top: '10mm', width: '30mm', height: '5mm' }, { width: 30, height: 5 }, zone),
    { left: '23mm', top: '13mm' },
);

// Anchored right/bottom (a total): moving right/down would leave the zone, so it goes the
// other way — and it never gains a left or a top, which would stretch it across the band.
assert.deepEqual(
    offsetCopy({ right: '0mm', bottom: '2mm', width: '40mm' }, { width: 40, height: 4 }, zone),
    { right: '3mm', bottom: '5mm' },
);

// As wide as the zone: nowhere to go on that axis, so it stays on the edge.
assert.deepEqual(
    offsetCopy({ left: '0mm', top: '0mm' }, { width: 180, height: 5 }, zone),
    { left: '0mm', top: '3mm' },
);

// Copying the copy makes a staircase.
const first = offsetCopy({ left: '20mm', top: '10mm' }, { width: 30, height: 5 }, zone);
assert.deepEqual(offsetCopy(first, { width: 30, height: 5 }, zone), { left: '26mm', top: '16mm' });

// Positions written in px by an old drag are read as millimetres out.
assert.deepEqual(
    offsetCopy({ left: '37.795px', top: '0' }, { width: 10, height: 5 }, zone),
    { left: '13mm', top: '3mm' },
);

console.log('copy-offset: ok');

// Review I-1: the copy is placed from ITS OWN style (the clone of the block copied), never
// from whatever block was selected when pasting, and it steps past copies already there,
// so pasting several times — or several blocks at once — never stacks them.
{
    const { freeSpotForCopy } = await import('../../resources/js/editor/copy-offset.js');
    const total = { right: '0mm', bottom: '2mm' };
    const size = { width: 40, height: 4 };

    // Nothing in the way: the plain offset.
    assert.deepEqual(freeSpotForCopy(total, size, zone, [total]), { right: '3mm', bottom: '5mm' });

    // A first copy already sits there: the next one steps on.
    assert.deepEqual(
        freeSpotForCopy(total, size, zone, [total, { right: '3mm', bottom: '5mm' }]),
        { right: '6mm', bottom: '8mm' },
    );
}
