import assert from 'node:assert/strict';
import { zoneAt, placementInZone } from '../../resources/js/editor/zones.js';

/*
 * A block's zone is read from where it sits in the tree, so after a drop or a move the
 * editor puts it in the band it now shows in. These are the two decisions behind that:
 * which band a point on the sheet belongs to, and where the block lands inside it.
 */

const bands = { header: 35, bodyHeight: 202 };

// 1. The band is read from the distance to the top of the printable area.
{
    assert.equal(zoneAt(0, bands), 'header');
    assert.equal(zoneAt(34.5, bands), 'header');
    assert.equal(zoneAt(35, bands), 'body');
    assert.equal(zoneAt(236.5, bands), 'body');
    assert.equal(zoneAt(237, bands), 'footer');
}

// 2. Above the printable area is still the header; below it, still the footer — a block
//    dropped on a margin goes to the nearest band rather than to none.
{
    assert.equal(zoneAt(-5, bands), 'header');
    assert.equal(zoneAt(400, bands), 'footer');
}

// 3. A block lands where it is on screen, relative to its new band, in millimetres.
{
    const pxPerMm = 96 / 25.4;
    const zone = { top: 100, left: 50 };
    const block = { top: 100 + 10 * pxPerMm, left: 50 + 20 * pxPerMm };

    assert.deepEqual(placementInZone(block, zone, pxPerMm), { top: 10, left: 20 });
}

// 4. Rounded to the half millimetre, the unit the rest of the layout is written in.
{
    const zone = { top: 0, left: 0 };
    const block = { top: 10.3, left: 3.9 };

    assert.deepEqual(placementInZone(block, zone, 1), { top: 10.5, left: 4 });
}

console.log('zones: OK');
