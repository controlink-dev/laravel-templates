/*
 * A roll (talão) is a monospace character grid (CGE-97): `columns` characters fill its
 * printable width, as they do on the ESC/POS printer. The canvas sets the same letter as
 * the PDF (DocumentComposer::rollGrid), byte for byte, so a receipt breaks its lines in
 * the editor where it breaks them on paper. Kept free of GrapesJS so it can be tested.
 */

/** The roll's grid width in characters: declared, or 40 on an 80 mm roll and 32 below. */
export function rollColumns(geometry) {
    const declared = Number(geometry?.columns);

    if (declared > 0) {
        return Math.floor(declared);
    }

    return Number(geometry?.width_mm) >= 76 ? 40 : 32;
}

/**
 * The roll's letter, dashed rules and wrapped-cell line height, or '' for a non-roll.
 *
 * `html .page` for the reason the server gives: the family is set on `html .page` before
 * it, and a plain `.page` rule would lose. Rounded to 3 decimals like PHP's round().
 */
export function rollGridCss(geometry) {
    if (geometry?.medium !== 'roll' || !(Number(geometry.width_mm) > 0)) {
        return '';
    }

    const margins = geometry.margins ?? {};
    const printable = Number(geometry.width_mm) - (Number(margins.left) || 0) - (Number(margins.right) || 0);
    const fontMm = Math.round((printable / rollColumns(geometry) / 0.6) * 1000) / 1000;

    if (!(fontMm > 0)) {
        return '';
    }

    return 'html .page{font-family:"Liberation Mono","Courier New",monospace;'
        + 'font-size:' + fontMm + 'mm;line-height:1.25}'
        + '.page hr,.page [data-tpl-type="rule"]{border:0;border-top:1px dashed #000;margin:0.5em 0}'
        // Canvas only: a wrapped line-table text cell stays on the grid's line height, as
        // the PDF's PageStructure::rollWrapCss gives it, over the canvas-wide 1.2 of
        // `table.lines td.tpl-wrap` (higher specificity, whatever the order).
        + 'html .page table.lines td.tpl-wrap{line-height:1.25}'
        // The PDF's own roll layout (PageStructure::ROLL_FLOW, ::ROLL_ZONES, ::ROLL_CELLS,
        // ::ROLL_CENTRED), byte for byte: a block in flow, as the printer prints everything
        // in document order; the zones in flow with no band height, so the receipt starts
        // at the top margin as on paper (a roll shows no zone bands anyway); line table
        // cells without padding, so an Nch column holds N characters (it comes after the
        // canvas-wide `padding: 0 1mm` in the frame, and so wins); and the code slot and
        // images centred, as the printer centres them (`ESC a 1`).
        + ROLL_FLOW
        + ROLL_ZONES
        + ROLL_CELLS
        + ROLL_CENTRED;
}

/** PageStructure::ROLL_FLOW: a roll's blocks in document order; beats print.css `.block`. */
export const ROLL_FLOW = '.page .block{position:static}';

/** PageStructure::ROLL_CELLS: a roll's line table cells, no padding, on the row's first line. */
export const ROLL_CELLS = 'table.lines th,table.lines td{padding:0;vertical-align:top}';

/** PageStructure::ROLL_CENTRED: a roll's code slots and images centred. */
export const ROLL_CENTRED = '.page [data-tpl-type="code-slot"],.page [data-tpl-type="image"]{display:block;margin-left:auto;margin-right:auto}';

/** PageStructure::ROLL_ZONES: a roll's zones in flow, no band height; beats print.css `.zone`. */
export const ROLL_ZONES = '.page .zone{position:static;height:auto}';
