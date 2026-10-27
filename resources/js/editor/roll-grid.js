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
 * The roll's letter and dashed rules, or '' for anything that is not a roll.
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
        + '.page hr,.page [data-tpl-type="rule"]{border:0;border-top:1px dashed #000;margin:0.5em 0}';
}
