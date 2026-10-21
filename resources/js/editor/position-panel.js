import { PX_PER_MM, lengthToMm, roundTo, switchAnchor } from './anchors.js';

/**
 * The position and size the style panel shows are the block's real ones (CGE-51).
 *
 * - Writing a side makes it the anchor of its axis and drops the opposite side, rounded
 *   to the tenth of a millimetre — a total pinned to the bottom that is given a Top moves
 *   there instead of stretching to the bottom of its zone.
 * - A side or size the block does not set shows the measured value as the field's hint,
 *   never a default it does not have (the old 180mm width, the 0 of an unset left).
 * - A length a drag left in pixels is rewritten in millimetres on selection, the unit
 *   the panel and the rest of the layout are written in.
 */

const SIDES = ['left', 'top', 'right', 'bottom'];
const LENGTHS = [...SIDES, 'width', 'height'];

export function wirePositionPanel(editor, root) {
    let applying = false;

    const apply = (component, set, remove) => {
        const style = { ...component.getStyle(), ...set };

        remove.forEach((side) => delete style[side]);

        applying = true;

        try {
            component.setStyle(style);
        } finally {
            applying = false;
        }
    };

    SIDES.forEach((side) => {
        editor.on(`component:styleUpdate:${side}`, (component) => {
            if (applying || !component?.getAttributes?.()['data-tpl-type']) {
                return;
            }

            const style = component.getStyle();
            const value = lengthToMm(style[side]);

            if (value === null) {
                return;
            }

            const { set, remove } = switchAnchor(style, side, value);

            if (remove.length === 0 && set[side] === style[side]) {
                return;
            }

            apply(component, set, remove);
            schedule();
        });
    });

    editor.on('component:toggled', () => {
        const component = editor.getSelected();

        if (component?.getAttributes()['data-tpl-type']) {
            normalisePixels(component);
        }

        schedule();
    });

    editor.on('component:styleUpdate', schedule);

    function normalisePixels(component) {
        const style = component.getStyle();
        const set = {};

        LENGTHS.forEach((property) => {
            if (/px\s*$/.test(String(style[property] ?? ''))) {
                set[property] = roundTo(lengthToMm(style[property]), 0.1) + 'mm';
            }
        });

        if (Object.keys(set).length) {
            apply(component, set, []);
        }
    }

    let pending = false;

    function schedule() {
        if (pending) {
            return;
        }

        pending = true;

        // After GrapesJS has re-rendered the properties for the selection.
        setTimeout(() => {
            pending = false;
            showMeasuredHints(editor.getSelected(), root);
        }, 0);
    }
}

/** The block's measured offsets and size, in mm, relative to its zone. */
export function measuredBox(el) {
    const parent = el?.offsetParent;

    if (!el || !parent) {
        return null;
    }

    const left = el.offsetLeft / PX_PER_MM;
    const top = el.offsetTop / PX_PER_MM;
    const width = el.offsetWidth / PX_PER_MM;
    const height = el.offsetHeight / PX_PER_MM;

    return {
        left,
        top,
        width,
        height,
        right: parent.clientWidth / PX_PER_MM - left - width,
        bottom: parent.clientHeight / PX_PER_MM - top - height,
    };
}

function showMeasuredHints(component, root) {
    if (!component?.getAttributes()['data-tpl-type'] || !root) {
        return;
    }

    const box = measuredBox(component.getEl());

    if (!box) {
        return;
    }

    LENGTHS.forEach((property) => {
        const input = root.querySelector(`.gjs-sm-property__${property} input`);

        if (input) {
            input.placeholder = String(roundTo(box[property], 0.1));
        }
    });
}
