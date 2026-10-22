/* ============================================================================
 * Formula columns of the line table (CGE-37).
 *
 * A formula column is a column of `data-tpl-columns` keyed `formula_<id>` and carrying
 * its source in `formula`. The compositor computes it for every row and injects it under
 * that key, so `toHTML()` prints it like any catalogue column. Unlike a catalogue column
 * it is the user's own: it can be removed, not just hidden.
 *
 * Pure functions over the columns array, so the columns editor, the canvas and the clone
 * handler agree on what a formula column is and how its key stays unique.
 * ========================================================================= */

import { newFormulaId } from './formula.js';

const COLUMN_PREFIX = 'formula_';

export function isFormulaColumn(column) {
    return typeof column?.key === 'string' && column.key.startsWith(COLUMN_PREFIX);
}

/** A key no column in `taken` has. Random ids, drawn again on the rare collision. */
function freshKey(taken, makeId) {
    let key;

    do {
        key = COLUMN_PREFIX + makeId();
    } while (taken.has(key));

    return key;
}

/**
 * The columns with a new, empty formula column at the end. Aligned right and with no
 * format: most formulas are amounts, and the user picks the format once the result is
 * known.
 */
export function addFormulaColumn(columns, label = 'Formula', makeId = newFormulaId) {
    const key = freshKey(new Set(columns.map((column) => column.key)), makeId);

    return [...columns, { key, label, formula: '', format: '', formatArgs: {}, align: 'right', width: '' }];
}

/**
 * The columns without the one at `index` — when it is a formula column. A catalogue
 * column is never removed (it is hidden instead), so for one the columns come back as
 * they were.
 */
export function removeColumn(columns, index) {
    if (!isFormulaColumn(columns[index])) {
        return [...columns];
    }

    return columns.filter((_, at) => at !== index);
}

/**
 * The columns of a copied table, with every formula key another table already holds
 * renamed. Two columns on one key would be refused at save (V-19), and the compositor
 * would write one's result over the other's. The same array when nothing collides, so
 * the caller can tell there is nothing to write.
 */
export function freshFormulaColumns(columns, takenKeys, makeId = newFormulaId) {
    const taken = new Set(takenKeys);
    let changed = false;

    const renamed = columns.map((column) => {
        if (!isFormulaColumn(column) || !taken.has(column.key)) {
            return column;
        }

        changed = true;

        const key = freshKey(new Set([...taken, ...columns.map((other) => other.key)]), makeId);
        taken.add(key);

        return { ...column, key };
    });

    return changed ? renamed : columns;
}
