import assert from 'node:assert/strict';
import {
    dragResultToMm, lengthToMm, opposite, readAnchor, roundTo, snapOffsets, switchAnchor,
} from '../../resources/js/editor/anchors.js';

/*
 * A block is placed by one side per axis: left or right, top or bottom. The starting
 * layouts pin totals to the right and the bottom; the panel has to show those sides and
 * never leave both sides of an axis set, which stretches the box across the zone (CGE-51).
 */

// Rounding: to the tenth for typed and snapped values, to the half for drops and drags.
assert.equal(roundTo(0.00134973, 0.1), 0);
assert.equal(Object.is(roundTo(-0.00134973, 0.1), -0), false);
assert.equal(roundTo(12.34, 0.5), 12.5);
assert.equal(roundTo(7.04, 0.1), 7);
assert.equal(roundTo(7.06, 0.1), 7.1);

// Lengths: mm as is, px through the CSS ratio, nothing for an unset side.
assert.equal(lengthToMm('22mm'), 22);
assert.ok(Math.abs(lengthToMm('453px') - 119.86) < 0.01);
assert.equal(lengthToMm('5'), 5);
assert.equal(lengthToMm(''), null);
assert.equal(lengthToMm('auto'), null);
assert.equal(lengthToMm(undefined), null);

assert.equal(opposite('left'), 'right');
assert.equal(opposite('bottom'), 'top');

// The anchor a block uses on each axis.
assert.deepEqual(readAnchor({ right: '0', bottom: '22mm' }), { x: 'right', y: 'bottom' });
assert.deepEqual(readAnchor({ left: '3mm', top: '4mm' }), { x: 'left', y: 'top' });
assert.deepEqual(readAnchor({}), { x: 'left', y: 'top' });

// Writing the other side moves the anchor: the old side goes, so the box never stretches.
assert.deepEqual(
    switchAnchor({ right: '0', bottom: '22mm' }, 'top', 40),
    { set: { top: '40mm' }, remove: ['bottom'] },
);
assert.deepEqual(
    switchAnchor({ right: '0', bottom: '22mm' }, 'left', 12.34),
    { set: { left: '12.3mm' }, remove: ['right'] },
);
assert.deepEqual(switchAnchor({ left: '1mm' }, 'left', -0.3), { set: { left: '-0.3mm' }, remove: [] });

// Bringing a block back onto the sheet corrects the side it is anchored by.
assert.deepEqual(snapOffsets({ right: '-0.5mm', top: '1mm' }, { right: 0.5 }), { set: { right: '0mm' }, remove: [] });
assert.deepEqual(snapOffsets({ left: '-0.00134973mm', top: '1mm' }, { left: 0.00134973 }), { set: { left: '0mm' }, remove: [] });
assert.deepEqual(snapOffsets({ left: '170mm', top: '1mm' }, { right: 5.04 }), { set: { left: '165mm' }, remove: [] });
assert.deepEqual(snapOffsets({ right: '0mm', bottom: '-2mm' }, { bottom: 2 }), { set: { bottom: '0mm' }, remove: [] });

// A drag inside a zone writes whole pixels; they become millimetres on the half, and a
// block anchored right/bottom stays anchored there.
const box = { width: 40, height: 5 };
const zone = { width: 180, height: 100 };

assert.deepEqual(dragResultToMm({ left: '231px', top: '8px' }, box, zone), {
    set: { left: '61mm', top: '2mm' },
    remove: [],
});
assert.deepEqual(dragResultToMm({ left: '378px', top: '189px', right: '0mm', bottom: '22mm' }, box, zone), {
    set: { right: '40mm', bottom: '45mm' },
    remove: ['left', 'top'],
});
