import { lengthToMm, readAnchor, roundTo } from './anchors.js';

/**
 * Where a copied block goes (CGE-53): a few millimetres right and down of the block it was
 * copied from, so the copy is visibly a second block and not an invisible twin on top.
 *
 * Each axis moves by the side the block is anchored to — a total pinned to the right and
 * the bottom moves its `right`/`bottom`, and never gains a `left`/`top`, which would
 * stretch it across the band. When the move would leave the zone it goes the other way;
 * when neither fits (a block as wide as its zone) it stays on the edge.
 *
 * @param {Record<string, string>} style  the `#id` rule of the block copied from
 * @param {{width: number, height: number}} size  the block's measured box, in mm
 * @param {{width: number, height: number}} zone  the band it sits in, in mm
 * @returns {Record<string, string>}  the anchored sides to write on the copy
 */
export function offsetCopy(style, size, zone, step = 3) {
    const anchor = readAnchor(style);

    return {
        [anchor.x]: place(lengthToMm(style[anchor.x]) ?? 0, size.width, zone.width, anchor.x === 'left' ? step : -step),
        [anchor.y]: place(lengthToMm(style[anchor.y]) ?? 0, size.height, zone.height, anchor.y === 'top' ? step : -step),
    };
}

/** One axis: the offset from the anchored edge after moving, kept inside `0..extent`. */
function place(offset, length, extent, delta) {
    const fits = (value) => value >= 0 && value + length <= extent;

    let value = offset + delta;

    if (!fits(value)) {
        value = offset - delta;
    }

    if (!fits(value)) {
        value = Math.min(Math.max(offset, 0), Math.max(extent - length, 0));
    }

    return roundTo(value, 0.1) + 'mm';
}

/**
 * Where a pasted copy goes: offset from ITS OWN style — the clone of the block it was
 * copied from — by one step, then two, … until no block already in its band sits on that
 * spot. Several pastes, or several blocks pasted at once, so never stack; and the block
 * that happened to be selected when pasting plays no part (its anchor may differ).
 *
 * @param {Array<Record<string, string>>} taken  the `#id` styles of the band's blocks
 */
export function freeSpotForCopy(style, size, zone, taken, step = 3, tries = 20) {
    const same = (spot, other) => Object.entries(spot).every(([side, value]) => other?.[side] === value);

    let spot = offsetCopy(style, size, zone, step);

    for (let k = 2; k <= tries && taken.some((other) => same(spot, other)); k++) {
        spot = offsetCopy(style, size, zone, k * step);
    }

    return spot;
}
