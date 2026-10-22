/* ============================================================================
 * "Campos e funções" (CGE-37, spec §6.3): while a formula is edited, the Blocks rail
 * shows what the formula may use instead of the blocks — the fields, grouped by model and
 * limited to what this place accepts (§4), and the functions, grouped by kind, each with
 * its signature, one line and an example. A click inserts at the caret of the formula box.
 *
 * Mounted inside the rail the blade already has (`[data-tpl-blocks]` and its
 * `.tpl-rail-head`): no markup of its own in the view. The blocks are hidden by a class on
 * the rail, never removed, so GrapesJS's block manager keeps owning them — even when it
 * renders again while the library is open.
 * ========================================================================= */

import { keepsKeyInFormulaBox } from './formula.js';
import { FUNCTION_GROUPS, functionGroupOf, scopedCatalogue, toDisplay } from './formula-names.js';

/** The class on the rail while the library is shown (see editor.css). */
const OPEN_CLASS = 'is-formula-library';

/** Text compared as the user means it: no case, no accents ("preco" finds "Preço"). */
function fold(text) {
    return String(text ?? '').normalize('NFD').replace(/\p{M}/gu, '').toLowerCase();
}

/**
 * The library after a search: the field groups keeping the items whose label, shown name
 * or key contains the query, the functions whose name, signature or description does.
 * Groups left empty are dropped; a blank query keeps everything. The catalogue given is
 * not changed.
 */
export function filterLibrary(catalogue, query) {
    const needle = fold(query).trim();
    const groups = catalogue?.groups ?? [];
    const functions = catalogue?.functions ?? [];

    if (needle === '') {
        return { groups, functions };
    }

    const matches = (...texts) => texts.some((text) => fold(text).includes(needle));

    return {
        groups: groups
            .map((group) => ({ ...group, items: group.items.filter((item) => matches(item.label, item.display, item.key)) }))
            .filter((group) => group.items.length),
        functions: functions.filter((fn) => matches(fn.name, fn.signature, fn.description)),
    };
}

/**
 * The functions by kind, in the reference's order (`FUNCTION_GROUPS`), then any the
 * server accepts that the list does not know yet. Each keeps its own order; a kind with
 * no function is left out.
 *
 * @returns {{id: string, functions: object[]}[]}
 */
export function functionGroups(functions) {
    const order = [...Object.keys(FUNCTION_GROUPS), 'other'];
    const byGroup = new Map(order.map((id) => [id, []]));

    (functions ?? []).forEach((fn) => {
        const id = fn.group ?? functionGroupOf(fn.name);

        byGroup.get(byGroup.has(id) ? id : 'other').push(fn);
    });

    return order.filter((id) => byGroup.get(id).length).map((id) => ({ id, functions: byGroup.get(id) }));
}

/**
 * The example line of a function: the template with `:example` replaced by the example as
 * shown. Replaced through a function, so a `$&` or `$'` the example holds (a label, a text
 * literal) is printed as it is, not expanded by `String.replace`.
 */
export function exampleLine(template, example) {
    return String(template ?? ':example').replace(':example', () => String(example ?? ''));
}

/**
 * Whether a group of the library is shown open. While a search is typed every group that
 * still has matches is open — the search never changes what the user chose. Otherwise
 * what the user chose for that tab's group (`stateMap`, keyed `tab:groupId`), and by
 * default only the row group ("Esta linha") open: the rest starts folded, so a long
 * catalogue reads as a list of headings.
 */
export function groupExpanded(stateMap, tab, groupId, query, isRowGroup = false) {
    if (fold(query).trim() !== '') {
        return true;
    }

    const key = tab + ':' + groupId;

    return stateMap?.has(key) ? Boolean(stateMap.get(key)) : Boolean(isRowGroup);
}

/** English fallbacks, for a host whose strings predate the library. */
const FALLBACK = {
    'formula.library.heading': 'Fields and functions',
    'formula.library.fields': 'Fields',
    'formula.library.functions': 'Functions',
    'formula.library.search_fields': 'Search fields…',
    'formula.library.search_functions': 'Search functions…',
    'formula.library.empty': 'Nothing found.',
    'formula.library.example': 'e.g. :example',
    'formula.group.math': 'Arithmetic',
    'formula.group.totals': 'Table totals',
    'formula.group.conditions': 'Conditions',
    'formula.group.text': 'Text',
    'formula.group.dates': 'Dates',
    'formula.group.other': 'Other',
};

/**
 * The library over the rail `rail` (the `[data-tpl-blocks]` element).
 *
 * - `open({target, rowCollection, onInsert})` shows it for the formula box `target` — of
 *   a column of the table over `rowCollection`, or of a field (null). Called again while
 *   open, it only retargets: the new box, the new catalogue, the same tab and search.
 * - `close()` gives the rail its blocks and its heading back; calling it again does nothing.
 * - `adopt(textarea)` retargets to a box drawn again for the same owner; `target()` is the
 *   box it inserts into (null when closed).
 *
 * A click inserts the item (`item.insert`, or `NAME(` for a function) over the box's
 * selection, puts the caret after it, gives the box its focus back and fires a bubbling
 * `input` on it — the same path as typing — then calls `onInsert(text)`.
 */
export function createFormulaLibrary({ rail, config }) {
    const strings = config?.strings ?? {};
    const t = (key) => strings[key] ?? FALLBACK[key] ?? '';
    const head = rail?.closest('.tpl-rail')?.querySelector('.tpl-rail-head') ?? null;

    let state = null;
    let tab = 'fields';
    let query = '';
    // `tab:groupId` -> open; kept for the life of the editor (across retargets and
    // close/open), so a group the user opened stays open the next time.
    const expanded = new Map();

    const container = document.createElement('div');
    container.className = 'tpl-formula-library';

    const tabs = document.createElement('div');
    tabs.className = 'tpl-formula-library__tabs';
    tabs.setAttribute('role', 'tablist');

    const tabButton = (id, label) => {
        const button = document.createElement('button');

        button.type = 'button';
        button.setAttribute('role', 'tab');
        button.dataset.tab = id;
        button.textContent = t(label);
        // Not taking the focus: the caret stays in the formula box.
        button.addEventListener('mousedown', (event) => event.preventDefault());
        button.addEventListener('click', () => {
            tab = id;
            render();
        });

        return button;
    };

    tabs.append(tabButton('fields', 'formula.library.fields'), tabButton('functions', 'formula.library.functions'));

    const search = document.createElement('input');
    search.type = 'search';
    search.className = 'tpl-formula-library__search';
    search.setAttribute('autocomplete', 'off');
    search.addEventListener('input', () => {
        query = search.value;
        renderList();
    });
    // Typing here edits the search, never the canvas (Backspace must not delete a block).
    search.addEventListener('keydown', (event) => {
        if (keepsKeyInFormulaBox(event)) {
            event.stopPropagation();
        }
    });

    const list = document.createElement('div');
    list.className = 'tpl-formula-library__list';

    container.append(tabs, search, list);

    function insert(text) {
        const target = state?.target;

        if (!target) {
            return;
        }

        // The box was drawn again (a format change re-creates the traits) or went away
        // (the column switched to Field) and nothing took its place: inserting into a
        // textarea no one sees would be a change no one can see either.
        if (target.isConnected === false) {
            close();

            return;
        }

        const start = target.selectionStart ?? target.value.length;
        const end = target.selectionEnd ?? start;

        target.setRangeText(text, start, end, 'end');
        target.focus();
        target.dispatchEvent(new Event('input', { bubbles: true }));
        state.onInsert?.(text);
    }

    const element = (tag, className, text) => {
        const node = document.createElement(tag);

        if (className) {
            node.className = className;
        }

        if (text !== undefined) {
            node.textContent = text;
        }

        return node;
    };

    const choice = (className, text) => {
        const button = element('button', className);

        button.type = 'button';
        button.addEventListener('mousedown', (event) => event.preventDefault());
        button.addEventListener('click', () => insert(text));

        return button;
    };

    /**
     * A group: its heading is a button (chevron, name, count) that folds it. Folding acts
     * on the drawn group in place — no redraw, so the heading keeps the keyboard focus —
     * and is remembered, unless a search is typed: then every group with matches is open
     * and a click folds only this view of it.
     */
    const section = (id, label, count, isRowGroup = false) => {
        const group = element('div', 'tpl-formula-library__group');
        const heading = element('button', 'tpl-formula-library__group-head');
        const body = element('div', 'tpl-formula-library__group-body');
        let shown = groupExpanded(expanded, tab, id, query, isRowGroup);

        const show = () => {
            heading.setAttribute('aria-expanded', String(shown));
            group.classList.toggle('is-collapsed', !shown);
            body.hidden = !shown;
        };

        heading.type = 'button';
        heading.dataset.group = id;
        heading.append(
            element('span', 'tpl-formula-library__chevron'),
            element('span', 'tpl-formula-library__group-label', label),
            element('span', 'tpl-formula-library__group-count', '· ' + count),
        );
        // Not taking the focus: the caret stays in the formula box. Enter and Space still
        // fold it once it is reached with Tab (a button's own click).
        heading.addEventListener('mousedown', (event) => event.preventDefault());
        heading.addEventListener('click', () => {
            shown = !shown;

            if (fold(query).trim() === '') {
                expanded.set(tab + ':' + id, shown);
            }

            show();
        });

        show();
        group.append(heading, body);

        return { group, body };
    };

    function renderFields(groups) {
        groups.forEach((group) => {
            const { group: node, body } = section(group.id, group.label, group.items.length, group.kind === 'row');

            group.items.forEach((item) => {
                const button = choice('tpl-formula-library__field', item.insert);
                const named = item.display.startsWith('[');
                // A field by its own label (the group heading names the model); a total by
                // its long name, `Linhas · Valor`; one with no usable name by its key.
                const shown = !named
                    ? item.display
                    : group.kind === 'totals' ? item.display.slice(1, -1) : String(item.label ?? '').trim();

                // The technical key is noise in a narrow rail: it stays in the tooltip only.
                button.title = item.key && item.key !== shown ? item.key : item.insert;
                button.append(element('span', '', shown));
                body.append(button);
            });

            list.append(node);
        });
    }

    function renderFunctions(functions) {
        functionGroups(functions).forEach((group) => {
            const { group: node, body } = section(group.id, t('formula.group.' + group.id), group.functions.length);

            group.functions.forEach((fn) => {
                const button = choice('tpl-formula-library__function', fn.name + '(');

                button.append(element('code', '', fn.signature));

                if (fn.description) {
                    button.append(element('span', '', fn.description));
                }

                if (fn.example) {
                    const example = toDisplay(fn.example, state.catalogue);

                    button.append(element('span', 'tpl-formula-library__example', exampleLine(t('formula.library.example'), example)));
                }

                body.append(button);
            });

            list.append(node);
        });
    }

    function renderList() {
        list.replaceChildren();

        const found = filterLibrary(state.catalogue, query);
        const empty = tab === 'fields' ? !found.groups.length : !found.functions.length;

        if (empty) {
            list.append(element('p', 'tpl-formula-library__empty', t('formula.library.empty')));
        } else if (tab === 'fields') {
            renderFields(found.groups);
        } else {
            renderFunctions(found.functions);
        }
    }

    function render() {
        // GrapesJS may have rendered the blocks again, and with them emptied the rail.
        if (!rail.contains(container)) {
            rail.append(container);
        }

        tabs.querySelectorAll('button').forEach((button) => {
            const on = button.dataset.tab === tab;

            button.setAttribute('aria-selected', String(on));
            button.classList.toggle('is-active', on);
        });

        search.placeholder = t(tab === 'fields' ? 'formula.library.search_fields' : 'formula.library.search_functions');
        search.setAttribute('aria-label', search.placeholder);
        search.value = query;
        renderList();
    }

    function open({ target, rowCollection = null, onInsert = null } = {}) {
        if (!state) {
            // Kept once, on the first open: a retarget must not store our own heading.
            state = { headText: head?.textContent ?? null };
            tab = 'fields';
            query = '';
            rail.classList.add(OPEN_CLASS);

            if (head) {
                head.textContent = t('formula.library.heading');
            }
        }

        Object.assign(state, { target, rowCollection, onInsert, catalogue: scopedCatalogue(config, rowCollection) });
        render();
    }

    function close() {
        if (!state) {
            return;
        }

        if (head && state.headText !== null) {
            head.textContent = state.headText;
        }

        rail.classList.remove(OPEN_CLASS);
        container.remove();
        state = null;
    }

    /**
     * A formula box was drawn (again): when it stands for the box the library inserts into
     * — the same `data-formula-owner`, a field's or a column's — the library follows it,
     * keeping its tab and search. A redraw of the panel (a format change, a column card
     * redrawn) then never leaves the library pointing at a textarea that is gone.
     */
    function adopt(input) {
        const owner = input?.dataset?.formulaOwner;

        if (state && owner && state.target !== input && state.target?.dataset?.formulaOwner === owner) {
            const { selectionStart, selectionEnd } = state.target;

            state.target = input;

            // Where the caret was in the old box, so the next click inserts there and not
            // at the end of a box that never had the focus.
            if (Number.isInteger(selectionStart) && typeof input.setSelectionRange === 'function') {
                input.setSelectionRange(selectionStart, Number.isInteger(selectionEnd) ? selectionEnd : selectionStart);
            }
        }
    }

    return { open, close, adopt, isOpen: () => state !== null, target: () => state?.target ?? null };
}
