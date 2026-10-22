/* ============================================================================
 * Readable names in formulas (CGE-37, spec §4/§5).
 *
 * The server reads technical keys (`customer.name`) and only those: its tokenizer has no
 * `[` `]`. The editor shows the same formula with the names the user knows the fields by
 * (`[Cliente · Nome]`) and turns them back into keys before the source is stored or sent.
 * Both directions read one map, built from the same catalogue the "Model" select offers
 * (`formulaCatalogue`), so they can never disagree. Text between quotes is the user's
 * own and is never translated.
 *
 * What may be named depends on where the formula is (§4): a single field names the
 * models' fields and reaches a table only through SUM/COUNT; a column of a table also
 * names that table's columns, short (`[Quantidade]`), as the value of the current row.
 * ========================================================================= */

import { formulaCatalogue, formulaSegments } from './formula.js';

/** Between the model's label and the field's: ` · ` (U+00B7 with a space on each side). */
export const NAME_SEPARATOR = ' · ';

/** The functions by the group the reference lists them under (spec §6.3), in its order. */
export const FUNCTION_GROUPS = {
    math: ['ROUND', 'ABS', 'MIN', 'MAX'],
    totals: ['SUM', 'COUNT'],
    conditions: ['IF', 'AND', 'OR', 'NOT'],
    text: ['UPPER', 'LOWER', 'LEFT', 'RIGHT', 'LEN', 'TRIM', 'CONCAT', 'TEXT'],
    dates: ['YEAR', 'MONTH', 'DAY', 'DAYS'],
};

/** Where a function the server added after this list is shown. */
const OTHER_GROUP = 'other';

/** The column formats that add up: only those columns are offered with SUM. */
const NUMERIC_FORMATS = new Set(['money', 'number']);

export function functionGroupOf(name) {
    return Object.keys(FUNCTION_GROUPS).find((group) => FUNCTION_GROUPS[group].includes(name)) ?? OTHER_GROUP;
}

/**
 * A label that can stand between brackets: not empty, and without `[`, `]`, `"` or a line
 * break, which would end the name (or open a text) where the user did not mean to. One
 * that cannot is shown by its key.
 */
function nameable(label, key) {
    return typeof label === 'string' && label.trim() !== '' && label !== key && !/[[\]"\r\n]/.test(label);
}

/**
 * What a formula may name where it is, grouped as the list shows it, plus the functions:
 *
 * - `row` ("this line"), only in a column of the table over `rowCollection`: that
 *   collection's columns, by short name, worth the current row's value;
 * - one `singular` group per model, `[Model · Field]`;
 * - `totals`: per collection, `SUM([Collection · Column])` on its numeric columns (on every
 *   column when none declares a format) and one `COUNT`, on its first column.
 *
 * Every item is `{key, label, display, insert}`: `display` is its name in brackets — or its
 * key, when it has no usable label or shares its name with another key (two equal labels
 * in one model, two models with one label): a name that stands for two fields would be
 * turned back into the wrong one. Never `formula.*` (see `formulaCatalogue`).
 *
 * The catalogue also carries `nodes`/`collections` (for completing a typed dotted key) and
 * the map `toDisplay`/`toSource` read.
 */
export function scopedCatalogue(config, rowCollection = null) {
    const strings = config?.strings ?? {};
    const flat = formulaCatalogue(config);
    const formats = new Map((config?.bindings ?? [])
        .filter((binding) => binding.type === 'collection')
        .map((binding) => [binding.key, new Map((binding.columns ?? []).map((column) => [column.key, column.default_format ?? null]))]));
    const row = flat.collections.find((collection) => collection.key === rowCollection) ?? null;

    // Every name each key could be shown by, in one namespace, to find the shared ones.
    const claims = new Map();
    const claim = (name, key) => {
        if (name !== null) {
            claims.set(name, new Set([...(claims.get(name) ?? []), key]));
        }

        return name;
    };
    const columnKey = (collection, column) => collection.key + '.' + column.key;
    const longName = (collection, column) => (nameable(collection.label, collection.key) && nameable(column.label, column.key)
        ? collection.label.trim() + NAME_SEPARATOR + column.label.trim()
        : null);

    const shortNames = new Map((row?.columns ?? []).map((column) => [columnKey(row, column),
        claim(nameable(column.label, column.key) ? column.label.trim() : null, columnKey(row, column))]));
    const fieldNames = new Map(flat.nodes.flatMap((node) => node.fields.map((field) => [field.key,
        claim(nameable(node.label, node.id) && nameable(field.label, field.key)
            ? node.label.trim() + NAME_SEPARATOR + field.label.trim()
            : null, field.key)])));
    const longNames = new Map(flat.collections.flatMap((collection) => collection.columns.map((column) => [
        columnKey(collection, column), claim(longName(collection, column), columnKey(collection, column))])));

    const usable = (name) => name !== null && name !== undefined && claims.get(name).size === 1;
    const displayOf = (name, key) => (usable(name) ? `[${name}]` : key);

    const byName = new Map();
    const byKey = new Map();
    // A table column by its long name, for the whole argument of SUM/COUNT (`toDisplay`).
    const longByKey = new Map();

    longNames.forEach((name, key) => {
        if (usable(name)) {
            longByKey.set(key, `[${name}]`);
        }
    });

    [shortNames, fieldNames, longNames].forEach((names) => names.forEach((name, key) => {
        if (usable(name)) {
            byName.set(name, key);

            // The short name first: in a column, the row's own columns are shown short.
            if (!byKey.has(key)) {
                byKey.set(key, `[${name}]`);
            }
        }
    }));

    const groups = [];

    if (row && row.columns.length) {
        groups.push({
            id: 'row',
            label: strings['formula.library.this_row'] ?? 'This line',
            kind: 'row',
            items: row.columns.map((column) => {
                const key = columnKey(row, column);
                // As `toDisplay` shows it: short, or long when the short name is shared.
                const display = byKey.get(key) ?? key;

                return { key, label: column.label, display, insert: display };
            }),
        });
    }

    flat.nodes.filter((node) => node.fields.length).forEach((node) => groups.push({
        id: node.id,
        label: node.label,
        kind: 'singular',
        items: node.fields.map((field) => {
            const display = displayOf(fieldNames.get(field.key), field.key);

            return { key: field.key, label: field.label, display, insert: display };
        }),
    }));

    const totals = flat.collections.flatMap((collection) => {
        if (!collection.columns.length) {
            return [];
        }

        const columnFormats = formats.get(collection.key) ?? new Map();
        const declared = [...columnFormats.values()].some((format) => format);
        const summed = collection.columns.filter((column) => !declared || NUMERIC_FORMATS.has(columnFormats.get(column.key)));
        const total = (fn, column) => {
            const key = columnKey(collection, column);
            const display = displayOf(longNames.get(key), key);

            return { key, label: column.label, display, insert: `${fn}(${display})` };
        };

        return [...summed.map((column) => total('SUM', column)), total('COUNT', collection.columns[0])];
    });

    if (totals.length) {
        groups.push({ id: 'totals', label: strings['formula.group.totals'] ?? 'Table totals', kind: 'totals', items: totals });
    }

    const functions = flat.functions.map((name) => ({
        name,
        signature: strings['formula.signature.' + name] ?? name + '()',
        description: strings['formula.description.' + name] ?? '',
        example: strings['formula.example.' + name] ?? '',
        group: functionGroupOf(name),
    }));

    return {
        groups,
        functions,
        nodes: flat.nodes,
        collections: flat.collections,
        rowCollection: row ? row.key : null,
        names: { byKey, byName, longByKey },
    };
}

/**
 * The formula with its keys shown by name, outside text literals. A key with no name
 * (removed from the catalogue, or sharing its label) stays as it is, so a formula whose
 * field was renamed still opens — showing the key — instead of losing it. Whole tokens
 * only: `customer.name_full` is not `customer.name` with a tail, and `0.23` is a number.
 */
export function toDisplay(source, catalogue) {
    const byKey = catalogue?.names?.byKey ?? new Map();
    const longByKey = catalogue?.names?.longByKey ?? new Map();

    return formulaSegments(String(source ?? '')).map((segment) => (segment.type !== 'code'
        ? segment.text
        : segment.text.replace(/[A-Za-z0-9_.]+/g, (token, offset, text) => {
            if (!/^[A-Za-z_]/.test(token)) {
                return token;
            }

            // The whole argument of SUM/COUNT is the table's column, not this line's
            // value: named long (`SUM([Linhas · Valor])`), as the totals insert it.
            if (longByKey.has(token)
                && /\b(SUM|COUNT)\s*\(\s*$/i.test(text.slice(0, offset))
                && /^\s*\)/.test(text.slice(offset + token.length))) {
                return longByKey.get(token);
            }

            return byKey.get(token) ?? token;
        }))).join('');
}

/**
 * The formula with its names turned back into keys, outside text literals, and the names
 * that match nothing — exactly, after trimming the spaces inside the brackets — in
 * `unknown`, left as they were (the editor says "Campo desconhecido" without asking the
 * server). A name not closed yet is unknown too. Keys typed by hand pass through.
 *
 * @returns {{source: string, unknown: string[]}}
 */
export function toSource(display, catalogue) {
    const byName = catalogue?.names?.byName ?? new Map();
    const unknown = [];

    const source = formulaSegments(String(display ?? '')).map((segment) => {
        if (segment.type !== 'name') {
            return segment.text;
        }

        const key = segment.closed ? byName.get(segment.text.slice(1, -1).trim()) : undefined;

        if (key !== undefined) {
            return key;
        }

        if (!unknown.includes(segment.text)) {
            unknown.push(segment.text);
        }

        return segment.text;
    }).join('');

    return { source, unknown };
}

/**
 * The quick examples under a data field's formula box (spec §6.4), as technical sources:
 * a text field in the middle of a sentence, that field in capitals, and a total of the
 * table. Built on the fields this type has — the first text field, the first column SUM
 * is offered on — so an example whose field the type lacks is simply not offered.
 *
 * @returns {{id: string, label: string, source: string}[]}
 */
export function quickExamples(config, catalogue) {
    const strings = config?.strings ?? {};
    const types = new Map();

    (config?.nodes ?? []).forEach((node) => (node.fields ?? []).forEach((field) => {
        if (field.type) {
            types.set(field.key, field.type);
        }
    }));
    (config?.bindings ?? []).forEach((binding) => {
        if (binding.type && !types.has(binding.key)) {
            types.set(binding.key, binding.type);
        }
    });

    const groups = catalogue?.groups ?? [];
    const text = groups.filter((group) => group.kind === 'singular')
        .flatMap((group) => group.items)
        .find((item) => types.get(item.key) === 'text');
    const total = groups.find((group) => group.kind === 'totals')?.items.find((item) => item.insert.startsWith('SUM('));
    const examples = [];

    if (text) {
        examples.push({
            id: 'text',
            label: strings['formula.quick.text'] ?? 'Text with a field in the middle',
            source: String(strings['formula.quick.text_source'] ?? '"Text before " & :field & " text after"')
                .replace(':field', () => text.key),
        });
        examples.push({ id: 'upper', label: strings['formula.quick.upper'] ?? 'Capitals', source: `UPPER(${text.key})` });
    }

    if (total) {
        examples.push({ id: 'total', label: strings['formula.quick.total'] ?? 'Table total', source: `SUM(${total.key})` });
    }

    return examples;
}
