import assert from 'node:assert/strict';
import { zoneAt, placementInZone, topInZone, zonesFor } from '../../resources/js/editor/zones.js';

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

// 5. Moved to another band by choice (CGE-48), a block keeps its top when it fits there…
{
    assert.equal(topInZone(12, 5, 35), 12);
}

// 6. …and is pulled up until it does when the band is shorter: header (55) to footer (35).
{
    assert.equal(topInZone(48, 5, 35), 30);
    assert.equal(topInZone(48, 5.3, 35), 29.5);
}

// 7. A block taller than its new band starts at the band's top, never above it.
{
    assert.equal(topInZone(10, 50, 35), 0);
    assert.equal(topInZone(-3, 5, 35), 0);
}

// 8. The bands a block may be moved to, by the same rules the server applies (V-06, R-02):
//    the line table only paginates in the body, a page number has no place in it, and a
//    legal field shown on every page needs a band that repeats.
{
    const all = ['header', 'body', 'footer'];

    assert.deepEqual(zonesFor('data-field', all), all);
    assert.deepEqual(zonesFor('line-table', all), ['body']);
    assert.deepEqual(zonesFor('page-number', all), ['header', 'footer']);
    assert.deepEqual(zonesFor('data-field', all, { everyPage: true }), ['header', 'footer']);
}

// 9. Only the bands the sheet has: a roll's three, a label's one.
{
    assert.deepEqual(zonesFor('page-number', ['body']), []);
    assert.deepEqual(zonesFor('static-text', ['body']), ['body']);
}

console.log('zones: OK');
