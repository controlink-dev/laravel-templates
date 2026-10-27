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
