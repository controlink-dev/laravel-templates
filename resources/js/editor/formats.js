/**
 * A field's format with its arguments (CGE-49), and the one place the editor writes its
 * Twig expression. The server reads the same choices back with `FormatSpec`; both sides
 * are pinned to one canonical form by `tests/Fixtures/format-expressions.json`.
 *
 * A spec is `{name, pattern?, decimals?, symbol?}`. Every argument comes from a closed
 * list, and with none the expression is the bare `|name` layouts used before CGE-49.
 */

export const DATE_PATTERNS = ['dd/MM/yyyy', 'yyyy-MM-dd', 'yyyy/MM/dd', 'dd-MM-yyyy', 'dd MMM yyyy'];

export const SYMBOLS = ['before', 'after', 'code', 'none'];

/** `null` is "the currency's" (money) or "the locale's" (number). */
export const DECIMALS = [null, 0, 1, 2, 3, 4];

/** Which arguments each format takes, in filter order. */
export const ARGUMENTS = { date: ['pattern'], number: ['decimals'], money: ['decimals', 'symbol'] };

const ATTRIBUTE = {
    pattern: 'data-tpl-format-pattern',
    decimals: 'data-tpl-format-decimals',
    symbol: 'data-tpl-format-symbol',
};

const blank = (value) => value === undefined || value === null || value === '';

function make(name, raw) {
    if (blank(name)) {
        return null;
    }

    const spec = { name };

    for (const argument of ARGUMENTS[name] ?? []) {
        const value = raw[argument];

        if (argument === 'decimals') {
            spec.decimals = blank(value) ? null : Number(value);
        } else if (!blank(value)) {
            spec[argument] = String(value);
        }
    }

    return spec;
}

/** The spec of a data field, from its attributes; null for "no format". */
export function specFromAttributes(attributes) {
    return make(attributes?.['data-tpl-format'], {
        pattern: attributes?.[ATTRIBUTE.pattern],
        decimals: attributes?.[ATTRIBUTE.decimals],
        symbol: attributes?.[ATTRIBUTE.symbol],
    });
}

/** The spec of a line-table column (`format` + `formatArgs`). */
export function specFromColumn(column) {
    return make(column?.format, column?.formatArgs ?? {});
}

/** The attributes a data field stores for a spec; keys a format does not take are emptied. */
export function specToAttributes(spec) {
    const attributes = {
        'data-tpl-format': spec?.name ?? '',
        [ATTRIBUTE.pattern]: '',
        [ATTRIBUTE.decimals]: '',
        [ATTRIBUTE.symbol]: '',
    };

    for (const argument of ARGUMENTS[spec?.name] ?? []) {
        const value = spec[argument];

        attributes[ATTRIBUTE[argument]] = blank(value) ? '' : String(value);
    }

    return attributes;
}

/** The `formatArgs` a column stores for a spec. */
export function specToColumnArgs(spec) {
    const args = {};

    for (const argument of ARGUMENTS[spec?.name] ?? []) {
        if (spec[argument] !== undefined) {
            args[argument] = spec[argument];
        }
    }

    return args;
}

/** The filter's positional arguments, trailing absences dropped. */
export function previewArgs(spec) {
    const args = (ARGUMENTS[spec?.name] ?? []).map((argument) => (blank(spec[argument]) ? null : spec[argument]));

    while (args.length && args[args.length - 1] === null) {
        args.pop();
    }

    return args;
}

/** `{{ subject|name(args) }}` in the canonical form, or `{{ subject }}` without a format. */
export function expression(subject, spec) {
    if (!spec) {
        return `{{ ${subject} }}`;
    }

    const args = previewArgs(spec);

    if (!args.length) {
        return `{{ ${subject}|${spec.name} }}`;
    }

    const literals = args.map((value) => {
        if (value === null) {
            return 'null';
        }

        return typeof value === 'number' ? String(value) : `'${value}'`;
    });

    return `{{ ${subject}|${spec.name}(${literals.join(', ')}) }}`;
}

/** What a freshly placed field is given, by the type of its binding. */
export function defaultSpecFor(bindingType) {
    switch (bindingType) {
        case 'date':
            return { name: 'date', pattern: 'dd/MM/yyyy' };
        case 'money':
            return { name: 'money', decimals: null, symbol: 'after' };
        case 'number':
            return { name: 'number', decimals: 2 };
        default:
            return null;
    }
}
