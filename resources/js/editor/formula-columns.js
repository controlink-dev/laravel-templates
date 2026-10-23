/* ============================================================================
 * Formula columns of the line table (CGE-37).
 *
 * Any column can print a formula's result instead of its value (spec §6.2):
 *
 * - a CATALOGUE column switched to "Fórmula" keeps its own `key` (`description`) and gains
 *   `formula` (its source) and `formulaKey` (`formula_<id>`). The compositor injects the
 *   result under `formulaKey` on every row and `toHTML()` prints `linha.<formulaKey>`, so
 *   the row's own value stays there for the formula — and any other column — to read;
 * - a CALCULATED column ("+ Coluna calculada") exists only for its formula: `key` and
 *   `formulaKey` are the same `formula_<id>`. It is the user's own: removed, not hidden;
 * - a column saved before `formulaKey` existed is calculated too, with `key = formula_<id>`
 *   and no `formulaKey`; it prints under its `key`, as it always did.
 *
 * Pure functions over the columns array, so the columns editor, the canvas and the clone
 * handler agree on what a formula column is and how its key stays unique.
 * ========================================================================= */

import { newFormulaId } from './formula.js';
import { defaultSpecFor, specToColumnArgs } from './formats.js';

const COLUMN_PREFIX = 'formula_';

const isPrefixed = (key) => typeof key === 'string' && key.startsWith(COLUMN_PREFIX);

/** The key a column prints from, `linha.<this>`: its `formulaKey` when it has one. */
export function printedKeyOf(column) {
    return column?.formulaKey ?? column?.key;
}

/** Whether the column prints a formula's result: a calculated one, or a catalogue one in Formula. */
export function isFormulaColumn(column) {
    return isPrefixed(printedKeyOf(column));
}

/**
 * Whether the column exists only for its formula — new (`key === formulaKey`) or saved
 * before `formulaKey` existed (a `formula_<id>` key alone). It is removed with its own
 * button and never switched to Field: there is no field to go back to.
 */
export function isCalculatedColumn(column) {
    return isPrefixed(column?.key) && (column.formulaKey === undefined || column.formulaKey === column.key);
}

/** Every key the columns hold or print under. */
function keysOf(columns) {
    return columns.flatMap((column) => [column?.key, column?.formulaKey]).filter((key) => typeof key === 'string');
}

/** A key no column in `taken` has. Random ids, drawn again on the rare collision. */
function freshKey(taken, makeId) {
    let key;

    do {
        key = COLUMN_PREFIX + makeId();
    } while (taken.has(key));

    return key;
}

/** A new calculated column's width: an auto width gets 0px in a full fixed-layout table. */
const NEW_COLUMN_WIDTH = '20mm';

/**
 * The columns with a new, empty calculated column at the end, printed under its own key
 * (`key === formulaKey`). Aligned right and with no format: most formulas are amounts,
 * and the user picks the format once the result is known. A width of its own: the line
 * table is `table-layout: fixed`, and when the columns already add up to its width an auto
 * column gets none and is not seen.
 */
export function addFormulaColumn(columns, label = 'Formula', makeId = newFormulaId) {
    const key = freshKey(new Set(keysOf(columns)), makeId);

    return [...columns, { key, formulaKey: key, label, formula: '', format: '', formatArgs: {}, align: 'right', width: NEW_COLUMN_WIDTH }];
}

/**
 * "Valor: Campo | Fórmula" of the column at `index` (spec §6.2).
 *
 * On: a catalogue column gains a source that starts as its own value — `<collection>.<key>`,
 * the current row's, ready to be wrapped — and a `formulaKey` no column of the table has.
 * Label, width, alignment and format stay (spec §8.1). Off: both go, and the column is
 * again exactly what it was, `formulaKey` left out — never null, which the server refuses.
 *
 * The same array when there is nothing to do: already in that mode, a calculated column
 * (always a formula), or no column at `index`. `collection` is the table's.
 */
export function setColumnFormulaMode(columns, index, on, makeId = newFormulaId, collection = '') {
    const column = columns[index];

    if (!column || isCalculatedColumn(column) || Boolean(on) === isFormulaColumn(column)) {
        return columns;
    }

    const next = [...columns];

    if (on) {
        next[index] = {
            ...column,
            formula: collection ? `${collection}.${column.key}` : String(column.key ?? ''),
            formulaKey: freshKey(new Set(keysOf(columns)), makeId),
        };
    } else {
        const { formula: _formula, formulaKey: _formulaKey, ...field } = column;
        next[index] = field;
    }

    return next;
}

/** A length in millimetres (`12`, `12mm`, `1.2cm`), or null for anything else. */
function millimetres(value) {
    const match = String(value ?? '').trim().toLowerCase().match(/^(\d*\.?\d+)(mm|cm)?$/);

    if (!match) {
        return null;
    }

    return parseFloat(match[1]) * (match[2] === 'cm' ? 10 : 1);
}

/**
 * Whether the visible columns' widths add up to more than the table's: the last ones are
 * then squeezed out of a fixed-layout table. Columns with no readable width count as
 * nothing, and a table whose width is not a length in mm is never reported.
 */
export function columnsOverflow(columns, tableWidth) {
    const table = millimetres(tableWidth);

    if (table === null) {
        return false;
    }

    const total = columns
        .filter((column) => !column.hidden)
        .reduce((sum, column) => sum + (millimetres(column.width) ?? 0), 0);

    return total > table + 0.01;
}

/**
 * Whether the column is the user's own, removed with the trash rather than hidden: a
 * calculated column, or one picked with "Add column" (CGE-85, `origin: 'added'`). Calculated
 * columns are recognised by their key, as they always were, so they carry no marker.
 */
export function isRemovableColumn(column) {
    return isCalculatedColumn(column) || column?.origin === 'added';
}

/**
 * A catalogue entry as a column: its key and label, an auto width, and the alignment and
 * format of its type (CGE-49, the format written out in full). The one shape both the
 * default columns and an added one start from.
 */
function columnOf(entry) {
    const spec = defaultSpecFor(entry.default_format) ?? (entry.default_format ? { name: entry.default_format } : null);

    return {
        key: entry.key,
        label: entry.label,
        width: '',
        align: entry.default_format === 'money' || entry.default_format === 'number' ? 'right' : 'left',
        format: spec?.name ?? '',
        formatArgs: specToColumnArgs(spec),
    };
}

/**
 * Every column the catalogue declares for the collection (`binding.columns`), in its order —
 * a table that starts empty is a table the user has to build twice — except the ones marked
 * `optional`: a linked table's other fields (CGE-85), offered by "Add column" only.
 */
export function defaultColumns(binding) {
    return (binding?.columns ?? []).filter((entry) => entry.optional !== true).map(columnOf);
}

/**
 * The columns with the catalogue `entry` added at the end ("Add column", CGE-85): the
 * default shape, marked `origin: 'added'` so it is removed, not hidden, and a width of its
 * own for the same reason as a calculated column's (`NEW_COLUMN_WIDTH`).
 */
export function addLinkedColumn(columns, entry) {
    return [...columns, { ...columnOf(entry), width: NEW_COLUMN_WIDTH, origin: 'added' }];
}

/**
 * The column at `index` bound to another field of its link (CGE-85): only the key changes —
 * width, alignment, format, visibility and origin are the user's. The label follows the
 * field only while it is still `oldEntry`'s default label; without `oldEntry` nothing says
 * it is, so it stays. The same array when there is no column there, or the column is in
 * Formula: its source names the old key, and swapping the key under it would mislead.
 */
export function relinkColumn(columns, index, newEntry, oldEntry = null) {
    const column = columns[index];

    if (!column || !newEntry || isFormulaColumn(column)) {
        return columns;
    }

    const next = [...columns];
    const defaultLabel = oldEntry !== null && column.label === oldEntry.label;
    next[index] = { ...column, key: newEntry.key, ...(defaultLabel ? { label: newEntry.label } : {}) };

    return next;
}

/**
 * The columns without the one at `index` — when it is the user's own (`isRemovableColumn`).
 * A catalogue column is never removed, in Formula or not (it is hidden instead), so for
 * one the columns come back as they were.
 */
export function removeColumn(columns, index) {
    if (!isRemovableColumn(columns[index])) {
        return [...columns];
    }

    return columns.filter((_, at) => at !== index);
}

/**
 * The columns of a copied table, with every formula key another table already holds
 * renamed. Two columns printing under one key would be refused at save (V-19), and the
 * compositor would write one's result over the other's. A calculated column renames `key`
 * and `formulaKey` together (apart, its key would name nothing, V-01); a catalogue column
 * in Formula only its `formulaKey` (its `key` is its field); a column saved before
 * `formulaKey` existed, its `key`. The same array when nothing collides, so the caller can
 * tell there is nothing to write.
 */
export function freshFormulaColumns(columns, takenKeys, makeId = newFormulaId) {
    const taken = new Set(takenKeys);
    let changed = false;

    const renamed = columns.map((column) => {
        if (!isFormulaColumn(column) || !taken.has(printedKeyOf(column))) {
            return column;
        }

        changed = true;

        const key = freshKey(new Set([...taken, ...keysOf(columns)]), makeId);
        taken.add(key);

        if (column.formulaKey === undefined) {
            return { ...column, key };
        }

        return column.key === column.formulaKey ? { ...column, key, formulaKey: key } : { ...column, formulaKey: key };
    });

    return changed ? renamed : columns;
}
