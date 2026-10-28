/**
 * How many characters a Code 128 barcode fits in a code slot (CGE-98).
 *
 * The article's code is typed by the user and has no fixed length, so the slot cannot be
 * checked against it while designing. Instead it tells how long a code still fits at the
 * narrowest module a 203 dpi head prints reliably (2 dots): information, never a block.
 *
 * The arithmetic is the package's `Code128Fit::maxChars`, kept identical on purpose — the
 * hint must agree with the check that refuses a batch whose code does not fit. Subset B is
 * forced, so the length depends only on the character count: start, check and data
 * characters are 11 modules each, the stop 13, plus a 10-module quiet zone on each side.
 */

/** 203 dpi: 8 dots per millimetre. */
const DOTS_PER_MM = 8;

/** The narrowest module the head prints reliably. */
const MIN_MODULE_DOTS = 2;

/** Everything that is not a data character: start + check + stop + both quiet zones. */
const FIXED_MODULES = 11 * 2 + 13 + 2 * 10;

/** The longest value that fits `widthMm` at the minimum module; 0 when none does. */
export function maxChars(widthMm) {
    const modules = Number(widthMm) * DOTS_PER_MM / MIN_MODULE_DOTS;

    if (!Number.isFinite(modules)) {
        return 0;
    }

    return Math.max(0, Math.floor((modules - FIXED_MODULES) / 11));
}

/** The slot's hint for a barcode `widthMm` wide. */
export function barcodeHint(widthMm, strings) {
    const template = strings?.['code_slot.barcode_hint'] ?? 'Barcode — fits up to :n characters';

    return template.replace(':n', String(maxChars(widthMm)));
}
