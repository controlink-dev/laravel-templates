/*
 * L-ESC: a receipt (a roll) written only in the receipt vocabulary (CGE-97).
 *
 * The editor's live mirror of the server's V-21, `src/Output/EscPos/RollVocabulary.php`:
 * the same lists and the same decisions, so a receipt the editor calls clean activates.
 * Both tests read the cases in `tests/Fixtures/roll-vocabulary-cases.json`. Change the
 * lists here and there together; the comment on the PHP constants records what the editor
 * writes on every save (Task 4, Step 0) and why each entry is allowed.
 *
 * One reader for the browser and node: a small tag tokenizer with a stack, not DOMParser.
 * The verdict must not depend on where it runs, and the tests run without a DOM.
 */

/** The receipt classes print.css ships (Task 3). Mirror: `RollVocabulary::CLASSES`. */
export const ROLL_CLASSES = Object.freeze([
    'esc-double', 'esc-bold', 'esc-left', 'esc-center', 'esc-right',
    'esc-gap-1', 'esc-gap-2', 'esc-gap-3', 'esc-row', 'esc-cell', 'esc-nowrap',
]);

/** GrapesJS's default `protectedCss`, which leads every saved stylesheet. */
const PROTECTED_CSS = {
    '*': { 'box-sizing': 'border-box' },
    body: { margin: '0' },
};

/** The page variables editor.js `pageVariables` writes on `.page`, all lengths in mm. */
const PAGE_VARIABLES = [
    '--margin-top', '--margin-right', '--margin-bottom', '--margin-left',
    '--header-height', '--footer-height',
];

/** The block types whose size an `#id` rule (or inline style) may set, in mm. */
const SIZED_TYPES = ['code-slot', 'image'];

const ALIGNMENTS = ['left', 'center', 'right'];

const MM = /^(\d+(\.\d+)?|\.\d+)mm$/;

const CHARACTERS = /^[1-9]\d*ch$/;

const VOID = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr']);

/**
 * @param {string} html    the version's HTML, as `editor.getHtml()` gives it
 * @param {string} css     the version's CSS, as `editor.getCss()` gives it
 * @param {number} columns the roll's grid width in characters
 * @returns {Array<{code: 'L-ESC', componentId: ?string, key: string, subject: string}>}
 *   `key` is the message under `validation.*` (`roll_css`, `roll_inline_style`, …);
 *   `subject` is the selector or element the message names.
 */
export function rollProblems(html, css, columns) {
    const elements = parseElements(String(html ?? ''));
    const ids = new Map();

    elements.forEach((element) => {
        const id = attr(element, 'id');

        if (id !== '' && !ids.has(id)) {
            ids.set(id, element);
        }
    });

    const problems = [];

    rules(String(css ?? '')).forEach(([selector, block]) => {
        if (!allowedRule(selector, block, ids)) {
            problems.push(problem('roll_css', firstId(selector), selector));
        }
    });

    elements.forEach((element) => problems.push(...elementProblems(element, Number(columns))));

    return problems;
}

/* ---------------------------------------------------------------------------
 * CSS
 * ------------------------------------------------------------------------ */

/** `[selector, block]` per rule, in order; an at-rule's block is null (none is allowed). */
function rules(source) {
    const css = source.replace(/\/\*[\s\S]*?\*\//g, '');
    const result = [];
    let at = 0;

    while (at < css.length) {
        const open = css.indexOf('{', at);
        const semi = css.indexOf(';', at);

        // `@import …;` and the like: an at-rule with no block.
        if (semi !== -1 && (open === -1 || semi < open) && css.slice(at, semi).trimStart().startsWith('@')) {
            result.push([squash(css.slice(at, semi)), null]);
            at = semi + 1;
            continue;
        }

        if (open === -1) {
            break;
        }

        const prelude = squash(css.slice(at, open));
        let depth = 1;
        let cursor = open + 1;

        while (cursor < css.length && depth > 0) {
            const char = css[cursor];
            depth += char === '{' ? 1 : (char === '}' ? -1 : 0);
            cursor++;
        }

        const block = css.slice(open + 1, open + 1 + Math.max(0, cursor - open - 2));
        result.push([prelude, prelude.startsWith('@') ? null : block]);
        at = cursor;
    }

    return result;
}

function allowedRule(selector, block, ids) {
    if (block === null) {
        return false;
    }

    const declared = declarations(block);

    return selector.split(',').every((part) => allowedFor(part.trim(), declared, ids));
}

function allowedFor(selector, declared, ids) {
    if (Object.hasOwn(PROTECTED_CSS, selector)) {
        const expected = PROTECTED_CSS[selector];
        const keys = Object.keys(declared);

        return keys.length === Object.keys(expected).length
            && keys.every((key, index) => key === Object.keys(expected)[index] && declared[key] === expected[key]);
    }

    if (selector === '.page') {
        return Object.entries(declared).every(([property, value]) => PAGE_VARIABLES.includes(property) && MM.test(value));
    }

    const match = selector.match(/^#([A-Za-z_][\w-]*)$/);

    if (match && ids.has(match[1]) && isSized(ids.get(match[1]))) {
        return onlySizes(declared);
    }

    return false;
}

/** property → value, property lowercased, the last one wins. */
function declarations(block) {
    const declared = {};

    block.split(';').forEach((declaration) => {
        if (declaration.trim() === '') {
            return;
        }

        const colon = declaration.indexOf(':');
        const property = (colon === -1 ? declaration : declaration.slice(0, colon)).trim().toLowerCase();

        // Re-inserting keeps the order PHP's array gives an overwritten key: its first place.
        declared[property] = colon === -1 ? '' : squash(declaration.slice(colon + 1));
    });

    return declared;
}

function onlySizes(declared) {
    return Object.entries(declared).every(([property, value]) => ['width', 'height'].includes(property) && MM.test(value));
}

/* ---------------------------------------------------------------------------
 * HTML
 * ------------------------------------------------------------------------ */

/**
 * Every element in document order, as `{tag, attributes, parent}`. `html`, `head` and
 * `body` are left out (they wrap the page; the server reads under the body too).
 */
function parseElements(html) {
    const elements = [];
    const stack = [];
    const token = /<!--[\s\S]*?-->|<![^>]*>|<\/([A-Za-z][\w-]*)\s*>|<([A-Za-z][\w-]*)((?:\s+[^\s"'>/=]+(?:\s*=\s*(?:"[^"]*"|'[^']*'|[^\s"'=<>`]+))?)*)\s*\/?>/g;

    for (const match of html.matchAll(token)) {
        const [, closing, opening, rawAttributes] = match;

        if (closing) {
            const tag = closing.toLowerCase();
            const index = stack.map((element) => element.tag).lastIndexOf(tag);

            if (index !== -1) {
                stack.length = index;
            }

            continue;
        }

        if (!opening) {
            continue;
        }

        const tag = opening.toLowerCase();

        if (['html', 'head', 'body'].includes(tag)) {
            continue;
        }

        const element = { tag, attributes: parseAttributes(rawAttributes ?? ''), parent: stack[stack.length - 1] ?? null };
        elements.push(element);

        if (!VOID.has(tag) && !match[0].endsWith('/>')) {
            stack.push(element);
        }
    }

    return elements;
}

/** Attribute name → decoded value; the first of a repeated attribute wins, as in the DOM. */
function parseAttributes(source) {
    const attributes = new Map();
    const pair = /([^\s"'>/=]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?/g;

    for (const [, name, double, single, bare] of source.matchAll(pair)) {
        const key = name.toLowerCase();

        if (!attributes.has(key)) {
            attributes.set(key, decodeEntities(double ?? single ?? bare ?? ''));
        }
    }

    return attributes;
}

function decodeEntities(value) {
    const named = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ' };

    return value.replace(/&(#x[0-9a-f]+|#\d+|[a-z]+);/gi, (entity, body) => {
        if (body[0] === '#') {
            const code = body[1] === 'x' || body[1] === 'X' ? parseInt(body.slice(2), 16) : parseInt(body.slice(1), 10);

            return Number.isFinite(code) ? String.fromCodePoint(code) : entity;
        }

        return named[body.toLowerCase()] ?? entity;
    });
}

function attr(element, name) {
    return element.attributes.get(name) ?? '';
}

function elementProblems(element, columns) {
    const problems = [];
    const component = nearestId(element);
    const lineTable = lineTableOf(element);

    if (attr(element, 'style').trim() !== '' && !allowedStyle(element, lineTable)) {
        problems.push(problem('roll_inline_style', component, describe(element)));
    }

    classesOf(element).forEach((name) => {
        if (name.startsWith('esc-') && !ROLL_CLASSES.includes(name)) {
            problems.push(problem('roll_class', component, name));
        }
    });

    if (element.attributes.has('data-tpl-cols')) {
        const cols = attr(element, 'data-tpl-cols');

        if (!/^\d+$/.test(cols) || Number(cols) > columns) {
            problems.push(problem('roll_cols', component, cols));
        }
    }

    if (element.tag === 'table' && lineTable === null) {
        problems.push(problem('roll_table', component, describe(element)));
    }

    if (attr(element, 'data-tpl-type') === 'line-table') {
        columnsOf(element).forEach((column) => {
            const width = String(column.width ?? '').trim().toLowerCase();

            if (width !== '' && !CHARACTERS.test(width)) {
                const label = String(column.label ?? column.key ?? '');
                problems.push(problem('roll_column_width', component, `${label} (${width})`));
            }
        });
    }

    return problems;
}

function allowedStyle(element, lineTable) {
    const declared = declarations(attr(element, 'style'));

    if (isSized(element)) {
        return onlySizes(declared);
    }

    if (lineTable !== null && ['col', 'th', 'td'].includes(element.tag)) {
        return Object.entries(declared).every(([property, value]) => {
            if (property === 'text-align') {
                return ALIGNMENTS.includes(value);
            }

            return property === 'width' && CHARACTERS.test(value);
        });
    }

    return false;
}

/** An image, the code slot or an image block: the elements a size in mm may be set on. */
function isSized(element) {
    return element.tag === 'img' || SIZED_TYPES.includes(attr(element, 'data-tpl-type'));
}

function lineTableOf(element) {
    for (let node = element; node; node = node.parent) {
        if (attr(node, 'data-tpl-type') === 'line-table') {
            return node;
        }
    }

    return null;
}

/** The visible columns of a line table. */
function columnsOf(table) {
    let columns;

    try {
        columns = JSON.parse(attr(table, 'data-tpl-columns'));
    } catch {
        return [];
    }

    return Array.isArray(columns)
        ? columns.filter((column) => column && typeof column === 'object' && !Array.isArray(column) && !column.hidden)
        : [];
}

/** The element's own id, or its nearest ancestor's: the component the editor selects. */
function nearestId(element) {
    for (let node = element; node; node = node.parent) {
        if (attr(node, 'id') !== '') {
            return attr(node, 'id');
        }
    }

    return null;
}

function classesOf(element) {
    return attr(element, 'class').trim().split(/\s+/).filter((name) => name !== '');
}

/** `div#id`, else `div.class.other`, else `div`. */
function describe(element) {
    if (attr(element, 'id') !== '') {
        return `${element.tag}#${attr(element, 'id')}`;
    }

    const classes = classesOf(element);

    return classes.length === 0 ? element.tag : `${element.tag}.${classes.join('.')}`;
}

/* ---------------------------------------------------------------------------
 * Shared
 * ------------------------------------------------------------------------ */

function firstId(selector) {
    return selector.match(/#([A-Za-z_][\w-]*)/)?.[1] ?? null;
}

function squash(text) {
    return text.replace(/\s+/g, ' ').trim();
}

function problem(key, componentId, subject) {
    return { code: 'L-ESC', componentId, key, subject };
}
