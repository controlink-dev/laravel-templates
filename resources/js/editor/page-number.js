/**
 * The text of a page-number block for one of its formats.
 *
 * Shared by the canvas preview (plausible numbers) and the export (the template
 * expressions), so the two can never disagree on a format. `escape` applies to the
 * translated "of" only, which is the one piece of user-facing text in the result.
 *
 * - `x`         → "1"
 * - `x-of-y`    → "1 of 3" (translated: "1 de 3")
 * - `x-slash-y` → "1 / 3" (CGE-74: the WinMax invoice prints it so)
 */
export function pageNumberText(format, strings, number, total, escape = (text) => text) {
    if (format === 'x-of-y') {
        return `${number} ${escape(strings['page_format.of'] ?? 'of')} ${total}`;
    }

    if (format === 'x-slash-y') {
        return `${number} / ${total}`;
    }

    return number;
}
