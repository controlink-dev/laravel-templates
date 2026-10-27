/*
 * Switching the version's format through the toolbar's format select (CGE-97).
 *
 * The geometry is set FIRST, before the chosen format's starting layout is loaded. Its
 * blocks are built — `init()`, `component:add` — while it loads, and a block that follows
 * the medium (a rule is an `hr` on a roll, a text a flow block) must read the medium it is
 * being loaded into. Set afterwards, a sheet's layout loaded over a roll was built as a
 * roll: every rule lost its position and size.
 *
 * Kept free of GrapesJS so it can be tested (tests/js/format-switch.test.mjs).
 *
 * @param {object} config        the editor's config; its `geometry` is replaced
 * @param {object} geometry      the chosen format's geometry
 * @param {object} steps
 * @param {?object} steps.layout the starting layout to load, or null to keep the canvas
 * @param {(layout: object) => void} steps.loadLayout
 * @param {(geometry: object) => void} steps.applyPaper
 */
export function applyFormat(config, geometry, { layout = null, loadLayout, applyPaper }) {
    config.geometry = geometry;

    if (layout) {
        loadLayout(layout);
    }

    applyPaper(config.geometry);
}

/**
 * Replace the canvas with a layout's markup and its rules (a format's starting layout,
 * loaded by the format tabs and the format select alike).
 *
 * A layout never saved from the editor is its seeded HTML: rules, images and code slots
 * carry their position as INLINE styles, which GrapesJS moves into `#id` rules as the
 * components are added; the data fields have theirs in the layout's css. So the order
 * is fixed (CGE-97, Task 7b), and the same as the editor's own first open:
 *
 *  1. empty the canvas: a removed component takes its `#id` rule with it, so the blocks
 *     on screen must go before the new sheet is in, or a block both formats share (the
 *     same id) would take the new format's rule away;
 *  2. the sheet: `setStyle` replaces it whole, and re-renders the mounted canvas;
 *  3. the markup: its inline styles merge into the sheet just installed. Added before
 *     the sheet, they were wiped by it — every rule of an A4 opened from a talão tab was
 *     left with no style, 0×0, and a save would have kept it so.
 *
 * @param {object} editor the GrapesJS editor
 * @param {string} html   the layout's markup
 * @param {string} css    the whole sheet: the page variables and the layout's own rules
 */
export function replaceLayout(editor, html, css) {
    editor.setComponents('');
    editor.setStyle(css);
    editor.setComponents(html);
}
