/* ============================================================================
 * Blocks overlapping each other (CGE-90), for the live check L-OVER.
 *
 * A mirror of the server's V-20 (`LayoutValidator::checkOverlap`), on purpose: the same
 * boxes from the stated styles — not the canvas's measured ones, which a wrapped line
 * makes taller — the same content rule and the same tolerance, so what the panel shows
 * while dragging is what the save will ask about, never stricter.
 * ========================================================================= */

import { lengthToMm } from './anchors.js';

/** `templates.geometry_tolerance_mm`: the engine's rounding, not an overlap. */
const TOLERANCE_MM = 0.5;

/** The font size a text block prints at when it states none (the browser's 16px). */
const DEFAULT_FONT_PT = 12;

/** The editor's placeholder before an image file is chosen; it paints nothing. */
const EMPTY_IMAGE = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

/**
 * Every pair of content blocks overlapping in one zone, `[under, over]` in document
 * order (later paints on top), minus the pairs that are occlusion: an opaque block over
 * a field or a code, which V-15/V-18 already report.
 *
 * @param {Array<{id: string, type: string, zone: ?string, ancestors: string[], style: Object, src?: string}>} blocks
 */
export function overlappingPairs(blocks) {
    const content = blocks
        .filter(hasContent)
        .map((block) => ({ block, box: inkBox(block) }))
        .filter(({ box }) => box !== null);
    const pairs = [];

    content.forEach(({ block: under, box: a }, i) => {
        content.slice(i + 1).forEach(({ block: over, box: b }) => {
            if (over.zone !== under.zone || over.ancestors.includes(under.id) || under.ancestors.includes(over.id)) {
                return;
            }

            const occlusion = ['data-field', 'code-slot'].includes(under.type) && isOpaque(over);
            const width = Math.min(a.left + a.width, b.left + b.width) - Math.max(a.left, b.left);
            const height = Math.min(a.top + a.height, b.top + b.height) - Math.max(a.top, b.top);

            if (!occlusion && width > TOLERANCE_MM && height > TOLERANCE_MM) {
                pairs.push([under, over]);
            }
        });
    });

    return pairs;
}

/** Text, a value, an image with a file, a code, a table. Lines and rectangles are not. */
function hasContent(block) {
    if (block.type === 'image') {
        return String(block.src ?? '').startsWith('data:') && block.src !== EMPTY_IMAGE;
    }

    return ['data-field', 'static-text', 'page-number', 'code-slot', 'line-table'].includes(block.type);
}

/** As `LayoutValidator::occlusionBox`: text with no height is its em box; anything else missing, no box. */
function boxOf({ type, style }) {
    let height = lengthToMm(style.height);

    if (height === null && (type === 'data-field' || type === 'static-text')) {
        height = (lengthToMm(style['font-size']) ?? DEFAULT_FONT_PT * (25.4 / 72));
    }

    const box = { left: lengthToMm(style.left), top: lengthToMm(style.top), width: lengthToMm(style.width), height };

    return Object.values(box).some((value) => value === null) ? null : box;
}

/**
 * As `LayoutValidator::inkBox`: one line of text prints as wide as its text — measured on
 * the canvas (`inkWidth`, mm), where the server measures the sample value — placed by its
 * alignment. Wrapped, unmeasured, or filling its box: the whole box.
 */
function inkBox(block) {
    const box = boxOf(block);
    const ink = block.inkWidth;

    if (box === null || block.wrap || typeof ink !== 'number' || ink >= box.width) {
        return box;
    }

    const align = String(block.style['text-align'] ?? '').trim().toLowerCase();
    const offset = align === 'right' || align === 'end' ? box.width - ink : align === 'center' ? (box.width - ink) / 2 : 0;

    return { ...box, left: box.left + offset, width: ink };
}

/** As `LayoutValidator::isOpaqueOccluder`. */
function isOpaque(block) {
    if (isOpaqueColour(block.style['background-color'] ?? block.style.background)) {
        return true;
    }

    if (block.type === 'code-slot') {
        return true;
    }

    const opacity = block.style.opacity;

    return block.type === 'image' && hasContent(block) && (opacity === undefined || opacity === '' || Number(opacity) >= 1);
}

/** Whether a CSS colour paints fully, read as `Colour::parse` reads it (null → no). */
export function isOpaqueColour(value) {
    const colour = String(value ?? '').trim().toLowerCase();

    if (colour === 'white' || colour === 'black') {
        return true;
    }

    const hex = /^#([0-9a-f]{3,8})$/.exec(colour);

    if (hex) {
        const digits = hex[1];

        if (digits.length === 4) {
            return digits[3] === 'f';
        }

        return digits.length === 8 ? digits.slice(6) === 'ff' : [3, 6].includes(digits.length);
    }

    const rgb = /^rgba?\(([^)]+)\)$/.exec(colour);

    if (!rgb) {
        return false;
    }

    const parts = rgb[1].split(/[\s,/]+/).filter(Boolean);
    const alpha = parts[3];

    if (parts.length < 3) {
        return false;
    }

    if (alpha === undefined) {
        return true;
    }

    return (alpha.endsWith('%') ? parseFloat(alpha) / 100 : parseFloat(alpha)) >= 1;
}
