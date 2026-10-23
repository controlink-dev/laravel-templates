/**
 * Panning the zoomed sheet with the wheel.
 *
 * The zoom is a transform on the frame, so a sheet larger than the pane has no
 * scrollbar to reach its top and bottom: the transform does not grow the scroll area,
 * and growing it back is the frame-resized-per-zoom model CGE-23 removed. The sheet is
 * moved with `Canvas.setCoords` instead.
 *
 * The limits are worked out from measured boxes, not from a model of how GrapesJS
 * centres the sheet. The frame is drawn as `scale(z) translate(x/z, y/z)`, so changing
 * the coordinates by Δ moves the sheet Δ screen pixels, and the frame's bounding box
 * (which includes the transform) is all that is needed to know where its edges are.
 */

/** Pixels per line and per page, for a wheel that reports in lines (Firefox) or pages. */
const LINE = 16;
const PAGE = 800;

/**
 * How far one wheel event asks the sheet to move, in screen pixels.
 *
 * Wheel down moves the sheet up, as a page scrolls. With shift the wheel goes sideways;
 * some browsers already report that as `deltaX` and others leave it in `deltaY`, so
 * whichever is filled is used.
 */
export function wheelMove({ deltaX = 0, deltaY = 0, deltaMode = 0, shiftKey = false }) {
    const unit = deltaMode === 1 ? LINE : deltaMode === 2 ? PAGE : 1;

    if (shiftKey) {
        return { x: -(deltaX || deltaY) * unit || 0, y: 0 };
    }

    return { x: -deltaX * unit || 0, y: -deltaY * unit || 0 };
}

/** How hard a wheel pixel zooms, and the most one event may: the step of the buttons. */
const ZOOM_PER_PIXEL = 0.002;
const MAX_ZOOM_STEP = 1.25;

/**
 * How much one Ctrl + wheel event scales the zoom, as a factor (CGE-82).
 *
 * Exponential in the delta, so a mouse notch (≈100px) is a step of about 20% and a
 * trackpad pinch, which Chrome sends as a wheel with ctrlKey and small deltas, zooms
 * smoothly. Wheel up zooms in. A burst is held to the 25% the buttons step by.
 */
export function wheelZoomFactor({ deltaY = 0, deltaMode = 0 }) {
    const unit = deltaMode === 1 ? LINE : deltaMode === 2 ? PAGE : 1;
    const factor = Math.exp(-deltaY * unit * ZOOM_PER_PIXEL);

    return Math.min(MAX_ZOOM_STEP, Math.max(1 / MAX_ZOOM_STEP, factor));
}

/**
 * How far to move the sheet, in screen pixels, so the spot of it under `point` before a
 * zoom is under `point` again after it. `before` and `after` are the frame's boxes either
 * side of the zoom: the spot is the same fraction of the box in both.
 */
export function anchorShift(point, before, after) {
    const axis = (p, start, size, newStart, newSize) => {
        const fraction = size ? (p - start) / size : 0.5;

        return Math.round(p - (newStart + fraction * newSize)) || 0;
    };

    return {
        x: axis(point.x, before.left, before.width, after.left, after.width),
        y: axis(point.y, before.top, before.height, after.top, after.height),
    };
}

/**
 * The part of `move` the sheet may make, per axis.
 *
 * On an axis where the sheet fits the pane (with `air` either side) it does not move,
 * unless it lies partly outside, in which case it is brought back inside the air.
 * Where it does not fit, its near edge may go no further in than `air` from the pane's
 * edge and its far edge no further in than `air` from the other one. The result is the
 * clamped position minus the current one, rounded to whole pixels, so a sheet already
 * past a limit is pulled back even by a move of nothing.
 */
export function clampMove(canvasRect, frameRect, move, air) {
    const axis = (start, size, paneStart, paneSize, delta) => {
        const nearEdge = paneStart + air;
        const farEdge = paneStart + paneSize - air - size;

        // It fits: the wheel does not move it, but a sheet left partly outside (panned,
        // then the pane grew) is brought back within the air rather than stranded.
        const wanted = size <= paneSize - 2 * air
            ? Math.min(farEdge, Math.max(nearEdge, start))
            : Math.min(nearEdge, Math.max(farEdge, start + delta));

        // Whole pixels: the transform leaves slivers at a limit, and a sliver is no move.
        return Math.round(wanted - start) || 0;
    };

    return {
        x: axis(frameRect.left, frameRect.width, canvasRect.left, canvasRect.width, move.x),
        y: axis(frameRect.top, frameRect.height, canvasRect.top, canvasRect.height, move.y),
    };
}
