/* ============================================================================
 * Inline icons.
 *
 * The bundle has no dependency on an icon font (RF-E03: no jQuery, no Bootstrap, no
 * host global — and a `<link>` to Font Awesome is exactly that kind of dependency).
 * Every glyph the editor chrome or the block rail needs is drawn here instead, as a
 * small inline SVG string: 16x16, single colour (`fill="currentColor"`, so it always
 * matches the surrounding text), and `aria-hidden="true"` because every place one of
 * these is used already carries its own accessible label.
 *
 * `width="1em" height="1em"` on every `<svg>` is not decorative: an inline SVG with no
 * intrinsic size and no CSS defaults to ~300x150px, and every FA glyph these replace
 * was sized by the container's `font-size` (an icon font's natural behaviour). Without
 * it the notice banner, the counts badge, the legal chip and the block rail media all
 * render a giant icon. This is the only place that sizes them: `editor.css` deliberately
 * does not add a general `svg` sizing rule, because GrapesJS's own chrome renders its
 * own unsized inline SVGs and sizes them through wrapper boxes instead
 * (`.gjs-badge__icon`, `.gjs-frame-wrapper__icon`, `.gjs-layer__icon`, `.gjs-clm-tag-*`,
 * `.gjs-block svg`) — a root-wide `svg { width: 1em; height: 1em }` rule would have
 * overridden every one of those.
 * ========================================================================= */

export const icons = {
    /** Triangle-exclamation: the counts panel and the notice banner. */
    warning: '<svg viewBox="0 0 16 16" width="1em" height="1em" fill="currentColor" aria-hidden="true">'
        + '<path fill-rule="evenodd" clip-rule="evenodd" '
        + 'd="M8 1.3 15.4 14.7H0.6L8 1.3Zm0 2.9L2.9 13.4h10.2L8 4.2Z"/>'
        + '<rect x="7.3" y="6.4" width="1.4" height="3.8" rx="0.4"/>'
        + '<rect x="7.3" y="11" width="1.4" height="1.4" rx="0.3"/>'
        + '</svg>',

    /** Xmark: the notice banner's close button. */
    close: '<svg viewBox="0 0 16 16" width="1em" height="1em" fill="currentColor" aria-hidden="true">'
        + '<path d="M3.5 2.4 8 6.9l4.5-4.5 1.1 1.1L9.1 8l4.5 4.5-1.1 1.1L8 9.1l-4.5 4.5-1.1-1.1L6.9 8 2.4 3.5Z"/>'
        + '</svg>',

    /** Shield-check: the legal-field chip in the selection panel. */
    shieldCheck: '<svg viewBox="0 0 16 16" width="1em" height="1em" fill="currentColor" aria-hidden="true">'
        + '<path fill-rule="evenodd" clip-rule="evenodd" '
        + 'd="M8 1 13.5 3v4.2c0 4-2.3 6.6-5.5 7.8C4.8 13.8 2.5 11.2 2.5 7.2V3L8 1Zm0 1.7L4 4.1v3.1c0 3.1 1.6 5.1 4 6.1 2.4-1 4-3 4-6.1V4.1L8 2.7Z"/>'
        + '<path d="M4.9 8.6 6 7.5 6.7 8.2 9.9 5 11 6.1 6.7 10.4Z"/>'
        + '</svg>',

    /** Hashtag: the page-number block. */
    hashtag: '<svg viewBox="0 0 16 16" width="1em" height="1em" fill="currentColor" aria-hidden="true">'
        + '<rect x="1.5" y="5.7" width="13" height="1.6"/>'
        + '<rect x="1.5" y="9.4" width="13" height="1.6"/>'
        + '<rect x="5" y="1" width="1.8" height="14"/>'
        + '<rect x="9.2" y="1" width="1.8" height="14"/>'
        + '</svg>',

    /** A capital "A": the static-text block. */
    font: '<svg viewBox="0 0 16 16" width="1em" height="1em" fill="currentColor" aria-hidden="true">'
        + '<path fill-rule="evenodd" clip-rule="evenodd" '
        + 'd="M8 2 3 14h1.8l1-2.6h4.4l1 2.6H13L8 2Zm0 3.4 1.6 4.2H6.4L8 5.4Z"/>'
        + '</svg>',

    /** Curly braces: the data-field block. */
    bracketsCurly: '<svg viewBox="0 0 16 16" width="1em" height="1em" fill="currentColor" aria-hidden="true">'
        + '<path d="M6.4 1.6c-1.7 0-2.6 1-2.6 2.7v1.6c0 1-.4 1.4-1.3 1.4v1.4c.9 0 1.3.4 1.3 1.4v1.6c0 1.7.9 2.7 2.6 2.7v-1.4c-.8 0-1.1-.4-1.1-1.3v-1.9c0-.9-.3-1.5-.9-1.8.6-.3.9-.9.9-1.8V3.3c0-.9.3-1.3 1.1-1.3V1.6Z"/>'
        + '<path d="M9.6 1.6c1.7 0 2.6 1 2.6 2.7v1.6c0 1 .4 1.4 1.3 1.4v1.4c-.9 0-1.3.4-1.3 1.4v1.6c0 1.7-.9 2.7-2.6 2.7v-1.4c.8 0 1.1-.4 1.1-1.3v-1.9c0-.9.3-1.5.9-1.8-.6-.3-.9-.9-.9-1.8V3.3c0-.9-.3-1.3-1.1-1.3V1.6Z"/>'
        + '</svg>',

    /** A framed picture: the image block. */
    image: '<svg viewBox="0 0 16 16" width="1em" height="1em" fill="currentColor" aria-hidden="true">'
        + '<path fill-rule="evenodd" clip-rule="evenodd" '
        + 'd="M1.5 2.5h13v11h-13v-11Zm1.5 1.5v8h10V4H3Z"/>'
        + '<circle cx="6" cy="6.5" r="1.2"/>'
        + '<path d="M4 11.5 6.8 8l2 2.1 1.7-2.1L12.5 11.5H4Z"/>'
        + '</svg>',

    /** A horizontal rule: the rule block. */
    minus: '<svg viewBox="0 0 16 16" width="1em" height="1em" fill="currentColor" aria-hidden="true">'
        + '<rect x="2.5" y="7.2" width="11" height="1.6"/>'
        + '</svg>',

    /** A table with header and rows: the line-table block. */
    tableList: '<svg viewBox="0 0 16 16" width="1em" height="1em" fill="currentColor" aria-hidden="true">'
        + '<path fill-rule="evenodd" clip-rule="evenodd" '
        + 'd="M1.5 2.5h13v11h-13v-11Zm1.5 1.5v2h10V4H3Zm0 3.5v2h10v-2H3Zm0 3.5v1.5h10V11H3Z"/>'
        + '</svg>',

    /** Two cells side by side: the receipt's «Linha com colunas» block (CGE-97). */
    rowColumns: '<svg viewBox="0 0 16 16" width="1em" height="1em" fill="currentColor" aria-hidden="true">'
        + '<rect x="1.5" y="6" width="5" height="1.6"/>'
        + '<rect x="1.5" y="8.6" width="3.5" height="1.6"/>'
        + '<rect x="8.5" y="6" width="6" height="1.6"/>'
        + '<rect x="11" y="8.6" width="3.5" height="1.6"/>'
        + '</svg>',

    /** A QR-code glyph: the code-slot block. */
    qrcode: '<svg viewBox="0 0 16 16" width="1em" height="1em" fill="currentColor" aria-hidden="true">'
        + '<path fill-rule="evenodd" clip-rule="evenodd" '
        + 'd="M1.5 1.5h5v5h-5v-5Zm1.3 1.3v2.4h2.4V2.8H2.8ZM9.5 1.5h5v5h-5v-5Zm1.3 1.3v2.4h2.4V2.8h-2.4ZM1.5 9.5h5v5h-5v-5Zm1.3 1.3v2.4h2.4v-2.4H2.8Z"/>'
        + '<path d="M9.5 9.5h1.6v1.6H9.5V9.5Zm2.9 0h1.6v1.6h-1.6V9.5Zm-2.9 2.9h1.6v1.6H9.5v-1.6Zm2.9 0h1.6v1.6h-1.6v-1.6Z"/>'
        + '</svg>',

    /** An arrow into a tray: "Download ZPL" (CGE-98). */
    download: '<svg viewBox="0 0 16 16" width="1em" height="1em" fill="currentColor" aria-hidden="true">'
        + '<path d="M7.25 1.5h1.5v6.69l2.22-2.22 1.06 1.06L8 11.06 3.97 7.03l1.06-1.06 2.22 2.22V1.5Z"/>'
        + '<path d="M2 10.5h1.5v2.5h9v-2.5H14v4H2v-4Z"/>'
        + '</svg>',
};
