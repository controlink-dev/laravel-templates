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

/**
 * The part of `move` the sheet may make, per axis.
 *
 * On an axis where the sheet fits the pane (with `air` either side) it does not move.
 * Where it does not fit, its near edge may go no further in than `air` from the pane's
 * edge and its far edge no further in than `air` from the other one. The result is the
 * clamped position minus the current one, so a sheet already past a limit is pulled
 * back even by a move of nothing.
 */
export function clampMove(canvasRect, frameRect, move, air) {
    const axis = (start, size, paneStart, paneSize, delta) => {
        if (size <= paneSize - 2 * air) {
            return 0;
        }

        const lowest = paneStart + paneSize - air - size;
        const highest = paneStart + air;
        const wanted = Math.min(highest, Math.max(lowest, start + delta));

        return wanted - start || 0;
    };

    return {
        x: axis(frameRect.left, frameRect.width, canvasRect.left, canvasRect.width, move.x),
        y: axis(frameRect.top, frameRect.height, canvasRect.top, canvasRect.height, move.y),
    };
}
