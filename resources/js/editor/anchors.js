/**
 * Where a block sits, by the side it is anchored to (CGE-51).
 *
 * An absolute block is placed by one side per axis: `left` or `right`, `top` or `bottom`.
 * The starting layouts pin totals, hash and certification to the right and the bottom,
 * so the panel shows all four sides — and never leaves both sides of an axis set, which
 * stretches the box across its zone and covers the area blocks are dropped on.
 *
 * Every length written here is in millimetres, rounded: to the tenth for what is typed
 * or snapped, to the half for drops and drags (the editor's placement grid).
 */

/** CSS pixels per millimetre — the canvas is laid out at 96 px to the inch. */
export const PX_PER_MM = 96 / 25.4;

const OPPOSITE = { left: 'right', right: 'left', top: 'bottom', bottom: 'top' };

export function opposite(side) {
    return OPPOSITE[side];
}

export function axisOf(side) {
    return side === 'left' || side === 'right' ? 'x' : 'y';
}

/** `value` to the nearest `step`, without float dust and never `-0`. */
export function roundTo(value, step) {
    const rounded = Number((Math.round(value / step) * step).toFixed(4));

    return rounded === 0 ? 0 : rounded;
}

/** A CSS length in millimetres; null for an unset side (`''`, `auto`, nothing). */
export function lengthToMm(value, pxPerMm = PX_PER_MM) {
    const match = /^\s*(-?\d*\.?\d+)\s*(mm|px|pt)?\s*$/.exec(String(value ?? ''));

    if (!match) {
        return null;
    }

    const amount = Number(match[1]);

    switch (match[2]) {
        case 'px':
            return amount / pxPerMm;
        case 'pt':
            return amount * (25.4 / 72);
        default:
            return amount;
    }
}

const isSet = (style, side) => lengthToMm(style?.[side]) !== null;

/** The side each axis is anchored by: right/bottom only when set without left/top. */
export function readAnchor(style) {
    return {
        x: isSet(style, 'right') && !isSet(style, 'left') ? 'right' : 'left',
        y: isSet(style, 'bottom') && !isSet(style, 'top') ? 'bottom' : 'top',
    };
}

const mm = (value, step) => roundTo(value, step) + 'mm';

/**
 * Writing one side: it becomes the anchor of its axis and the opposite side goes, so the
 * block moves to the typed place instead of stretching between the two.
 */
export function switchAnchor(style, side, valueMm) {
    const other = opposite(side);

    return {
        set: { [side]: mm(valueMm, 0.1) },
        remove: style?.[other] !== undefined && style?.[other] !== '' ? [other] : [],
    };
}

/**
 * Bringing a block back onto the sheet. `overflowMm` is how far it sticks out on each
 * side (positive). The side the block is anchored by is the one corrected — a block
 * pinned right gets a new `right`, never a `left` added next to it.
 */
export function snapOffsets(style, overflowMm) {
    const anchor = readAnchor(style);
    const set = {};

    const correct = (axis, start, end) => {
        const side = anchor[axis];
        const current = lengthToMm(style?.[side]) ?? 0;
        const inward = (overflowMm[start] ?? 0) - (overflowMm[end] ?? 0);

        if (inward === 0) {
            return;
        }

        // Moving inward from `start` is a larger start offset or a smaller end offset.
        set[side] = mm(side === start ? current + inward : current - inward, 0.1);
    };

    correct('x', 'left', 'right');
    correct('y', 'top', 'bottom');

    return { set, remove: [] };
}

/**
 * A drag inside a zone: GrapesJS writes `left`/`top` in whole pixels. They become
 * millimetres on the half, and a block anchored right/bottom keeps that anchor, its
 * offset measured from the zone's far edge. `box` and `zone` are sizes in millimetres.
 */
export function dragResultToMm(style, box, zone, pxPerMm = PX_PER_MM) {
    const set = {};
    const remove = [];

    const place = (near, far, size, span) => {
        const nearMm = lengthToMm(style?.[near], pxPerMm);

        if (nearMm === null) {
            return;
        }

        if (isSet(style, far)) {
            set[far] = mm(span - roundTo(nearMm, 0.5) - size, 0.5);
            remove.push(near);
        } else {
            set[near] = mm(nearMm, 0.5);
        }
    };

    place('left', 'right', box.width, zone.width);
    place('top', 'bottom', box.height, zone.height);

    return { set, remove };
}
