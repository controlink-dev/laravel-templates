/* ============================================================================
 * A line table whose items span several rows (CGE-97).
 *
 * A column flagged `break` starts a new row of the item: the visible columns split
 * before each one into segments, one `<tr>` per segment. The longest segment is the
 * grid every row is laid on (the `<colgroup>`); a shorter one stretches its last cell
 * over the grid columns it leaves free.
 *
 * Mirrors the server's `src/Output/Pdf/LineRows.php`, which reads what this writes: the
 * two must agree on the segments, the grid and the spans, and on the `lines__text`
 * class on the text cells of a multi-row table. Pure functions, no DOM.
 * ========================================================================= */

/** The class on a multi-row table's text cells (`LineRows::TEXT_CLASS`). */
export const TEXT_CLASS = 'lines__text';

/**
 * The visible columns, split before each one with `break === true`.
 *
 * A hidden column's break moves to the next visible column; a break on the first
 * visible column opens no empty row. Without a break: `[visible]`.
 */
export function segments(columns) {
    const result = [[]];
    let pending = false;

    for (const column of columns ?? []) {
        if (!column || typeof column !== 'object') {
            continue;
        }

        pending = pending || column.break === true;

        if (column.hidden) {
            continue;
        }

        if (pending && result[result.length - 1].length > 0) {
            result.push([]);
        }

        pending = false;
        result[result.length - 1].push(column);
    }

    return result;
}

export function isMultiRow(columns) {
    return segments(columns).length > 1;
}

/** The columns of the longest segment (the first, on a tie): the table's grid. */
export function grid(columns) {
    return segments(columns).reduce((longest, segment) => (segment.length > longest.length ? segment : longest), []);
}

/**
 * The colspan of each cell, per segment: 1, but for the last cell of a segment shorter
 * than the grid, which takes the grid columns left over.
 */
export function spans(columns) {
    const width = grid(columns).length;

    return segments(columns).map((segment) => segment.map((column, index) => (
        index === segment.length - 1 ? width - segment.length + 1 : 1
    )));
}

/**
 * The `<colgroup>` of the grid: a `<col>` per grid column, with the width `widthOf` gives
 * it (a CSS length, or '' for none). It carries the widths of a multi-row table, whose
 * header cells span differently on each row.
 */
export function colgroupHTML(columns, widthOf) {
    return '<colgroup>' + grid(columns)
        .map((column) => {
            const width = widthOf(column);

            return width ? `<col style="width:${width}">` : '<col>';
        })
        .join('') + '</colgroup>';
}

/**
 * One `<tr>` per segment, of `tag` cells (`th` or `td`): `colspan` where it is > 1, the
 * class `classOf` gives (if any), then the column's alignment. `inner` gives the cell's
 * content, already markup.
 */
export function rowsHTML(columns, tag, inner, classOf = () => '') {
    const all = spans(columns);

    return segments(columns)
        .map((segment, row) => '<tr>' + segment
            .map((column, index) => {
                const span = all[row][index];
                const cls = classOf(column);

                return `<${tag}`
                    + (span > 1 ? ` colspan="${span}"` : '')
                    + (cls ? ` class="${cls}"` : '')
                    + ` style="text-align:${column.align || 'left'}">${inner(column)}</${tag}>`;
            })
            .join('') + '</tr>')
        .join('');
}
