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

/** Millimetres, to the half — the unit the rest of the layout is written in. */
export function round(value) {
    return Math.round(value * 2) / 2;
}
