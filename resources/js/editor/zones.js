/**
 * The band a point on the sheet belongs to.
 *
 * `fromTop` is the distance, in millimetres, from the top of the printable area. A point
 * on the top margin counts as the header and one on the bottom margin as the footer, so
 * a block dropped on a margin still ends up in a band instead of in none.
 */
export function zoneAt(fromTop, { header, bodyHeight }) {
    if (fromTop < header) {
        return 'header';
    }

    return fromTop < header + bodyHeight ? 'body' : 'footer';
}

/**
 * Where a block lands inside its band: its on-screen position relative to the band's,
 * in millimetres rounded to the half.
 *
 * Both boxes are measured in the same space (the canvas frame), so the result is the
 * block's `top` and `left` once it is a child of the band.
 */
export function placementInZone(block, zone, pxPerMm) {
    return {
        top: round((block.top - zone.top) / pxPerMm),
        left: round((block.left - zone.left) / pxPerMm),
    };
}

/**
 * The top a block keeps when it is moved to another band by choice (CGE-48).
 *
 * The bands differ in height (an A4 invoice: header 55, footer 35), so a top that fitted
 * the old band can put the block past the bottom of the new one. It is pulled up just
 * enough to fit — rounded DOWN to the half, so the rounding cannot push it back out — and
 * never above the band's top, even for a block taller than the band.
 */
export function topInZone(top, blockHeight, zoneHeight) {
    const lowest = Math.floor(Math.max(0, zoneHeight - blockHeight) * 2) / 2;

    return Math.min(Math.max(0, top), lowest);
}

/**
 * Blocks that only make sense in some bands — the server's `LayoutValidator`
 * ZONE_RESTRICTIONS (V-06), repeated here so the editor does not offer what it refuses.
 */
const ZONE_RESTRICTIONS = {
    'line-table': ['body'],
    'page-number': ['header', 'footer'],
};

/**
 * The bands a block may be moved to (CGE-48), out of the ones the sheet has.
 *
 * A legal field that must show on every page needs a band that repeats, so not the body
 * (R-02). These are offered or not; the server still checks every one of them.
 */
export function zonesFor(blockType, bands, { everyPage = false } = {}) {
    const allowed = ZONE_RESTRICTIONS[blockType] ?? bands;

    return bands.filter((zone) => allowed.includes(zone) && !(everyPage && zone === 'body'));
}

/** Millimetres, to the half — the unit the rest of the layout is written in. */
export function round(value) {
    return Math.round(value * 2) / 2;
}
