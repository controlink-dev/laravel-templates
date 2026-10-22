/* ============================================================================
 * Formula fields (CGE-37).
 *
 * A data field in formula mode is bound to `formula.<id>` and carries its source in
 * `data-tpl-formula`. The editor evaluates nothing itself: the language is closed and
 * checked on the server (V-19), and a second evaluator in JS would drift from it the way
 * a second copy of the format rules would (CGE-49). So the canvas asks the host, which
 * checks the formula exactly as the save does and evaluates it on the data the canvas
 * shows, and draws whatever comes back — the value, or the reason it has none.
 * ========================================================================= */

const FORMULA_PREFIX = 'formula.';

const SIGNATURE_PREFIX = 'formula.signature.';

/** The autocomplete never offers more than this; typing one more letter narrows it. */
const MAX_SUGGESTIONS = 20;

/**
 * The functions of the language, in the server's order. There is no list in JS: the host
 * sends one signature per function the parser accepts (`Editor::strings()` walks
 * `Formula::functions()`), and the names are read off those keys — so the autocomplete
 * and the library never offer a function the save would refuse, nor miss one.
 */
export function formulaFunctionsOf(strings) {
    return Object.keys(strings ?? {})
        .filter((key) => key.startsWith(SIGNATURE_PREFIX))
        .map((key) => key.slice(SIGNATURE_PREFIX.length));
}

/** The traits' names: the switch has no attribute of its own; the box is the source's. */
export const VALUE_MODE_TRAIT = 'tpl-value-mode';
export const FORMULA_SOURCE_TRAIT = 'data-tpl-formula';

/** The endpoint refuses a batch above this (FormulaPreviewController::MAX_ITEMS). */
const MAX_ITEMS = 50;

const ID_ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

/** The letters at the start of `ID_ALPHABET`. */
const LETTERS = 26;

/**
 * A new formula id: a letter, then seven of `[a-z0-9]`, which the server's key pattern
 * (`[a-z][a-z0-9]{5,11}`) accepts. Random rather than counted: a layout is edited across
 * sessions and copied between formats, and no counter survives that.
 */
export function newFormulaId() {
    const bytes = new Uint8Array(8);
    globalThis.crypto.getRandomValues(bytes);

    // The first character is a letter: the key prints as a Twig path, and Twig reads
    // `formula.3b…` as the number 3 followed by a name (the server refuses that key).
    return Array.from(bytes, (byte, index) => (index === 0
        ? ID_ALPHABET[byte % LETTERS]
        : ID_ALPHABET[byte % ID_ALPHABET.length])).join('');
}

export function isFormulaKey(key) {
    return typeof key === 'string' && key.startsWith(FORMULA_PREFIX);
}

/**
 * Why a field bound to `binding` may not become a formula, or null when it may (spec §6.1):
 * `legal` — the document would lose the field the law asks for — or `mandatory` — the type
 * requires that value placed (V-02). Legal first: every legal field is mandatory too, and
 * the law is the reason the user needs to hear.
 *
 * Only the last block carrying the field is held: with `othersCarrying` other data fields
 * on the same binding, this one is a redundant copy and may become a formula — the rule
 * that already lets the last legal block alone ask before it is deleted. A block in
 * Fórmula carries a `formula.*` key, so it never counts. The server still has the last
 * word (V-02/V-10).
 */
export function formulaLock(binding, othersCarrying = 0) {
    if (othersCarrying > 0) {
        return null;
    }

    if (binding?.legal) {
        return 'legal';
    }

    return binding?.mandatory ? 'mandatory' : null;
}

/** Ctrl/Meta chords that edit text in the box: undo, redo, select all, copy, paste, cut. */
const TEXT_EDITING_CHORDS = new Set(['z', 'y', 'a', 'c', 'v', 'x']);

/**
 * Whether a keydown in the formula box must stop there. Plain keys always do: GrapesJS's
 * shortcuts (Delete, Backspace, arrows) act on the selected block. So do the text-editing
 * chords above and Ctrl/Meta with a non-character key (Ctrl+Backspace deletes a word,
 * Ctrl+Arrow moves by one). Any other Ctrl/Meta chord — Ctrl+S above all — reaches the
 * host's shortcuts. Ctrl+Alt is AltGr on Windows (`€`, `@`, `{`): typing, so it stays.
 */
export function keepsKeyInFormulaBox(event) {
    const chord = (event.ctrlKey || event.metaKey) && !event.altKey;
    const key = String(event.key ?? '');

    if (!chord || key.length !== 1) {
        return true;
    }

    return TEXT_EDITING_CHORDS.has(key.toLowerCase());
}

/**
 * The key a formula field should carry, given the keys already on the sheet: its own
 * when no other field has it, a new one when it does — which is what a copy is. Two
 * formulas on one key would be refused at save (V-19), and the user could not tell why.
 */
export function freshFormulaKey(existingKeys, key, makeId = newFormulaId) {
    if (!isFormulaKey(key)) {
        return key;
    }

    const taken = new Set(existingKeys);

    if (!taken.has(key)) {
        return key;
    }

    let candidate;

    do {
        candidate = FORMULA_PREFIX + makeId();
    } while (taken.has(candidate));

    return candidate;
}

/**
 * The name and the key line of the panel header for a selected block bound to `key`.
 * A formula field is named "Fórmula" and shows its own key: the binding it had before
 * the switch is gone, and naming it would say the block prints that field.
 */
export function selectionHeading(key, binding, fallbackName, strings) {
    if (isFormulaKey(key)) {
        return { name: strings?.['formula.label'] ?? 'Formula', meta: [key] };
    }

    return { name: binding?.label ?? fallbackName ?? '', meta: [key, binding?.type].filter(Boolean) };
}

/* ---------------------------------------------------------- autocomplete */

/**
 * What a formula may name, from the editor config: the reachable models with their fields
 * (as the "Model" select offers them), the collections with their columns, and the
 * functions. Never `formula.*` — a formula cannot read another formula's result — and a
 * collection the graph also lists as a node is offered once, as a collection.
 *
 * @returns {{nodes: Array<{id, label, fields: Array<{key, label}>}>,
 *            collections: Array<{key, label, columns: Array<{key, label}>}>, functions: string[]}}
 */
export function formulaCatalogue(config) {
    const collections = (config?.bindings ?? [])
        .filter((binding) => binding.type === 'collection')
        .map((binding) => ({
            key: binding.key,
            label: binding.label ?? binding.key,
            columns: (binding.columns ?? []).map((column) => ({ key: column.key, label: column.label ?? column.key })),
        }));
    const collectionKeys = new Set(collections.map((collection) => collection.key));

    const nodes = (config?.nodes ?? [])
        .filter((node) => !node.collection && node.reachable !== false)
        .filter((node) => !collectionKeys.has(node.id) && !isFormulaKey(node.id + '.'))
        .map((node) => ({
            id: node.id,
            label: node.label ?? node.id,
            fields: (node.fields ?? [])
                .filter((field) => !isFormulaKey(field.key))
                .map((field) => ({ key: field.key, label: field.label ?? field.key })),
        }));

    return { nodes, collections, functions: [...(config?.formulaFunctions ?? [])] };
}

/**
 * The formula cut into the pieces the readable names (`formula-names.js`) and the
 * autocomplete care about, in order:
 *
 * - `literal`: `"…"`, `""` being an escaped quote; one not closed runs to the end.
 * - `name`: `[…]`, closed by `]`; one not closed ends where a `[`, a `"` or a line break
 *   starts (a label never holds one) or at the end — `closed` says which.
 * - `code`: everything else.
 */
export function formulaSegments(text) {
    const segments = [];
    let code = '';
    let index = 0;

    const flush = () => {
        if (code !== '') {
            segments.push({ type: 'code', text: code });
            code = '';
        }
    };

    while (index < text.length) {
        const char = text[index];

        if (char === '"') {
            let end = index + 1;
            let closed = false;

            while (end < text.length && !closed) {
                if (text[end] !== '"') {
                    end++;
                } else if (text[end + 1] === '"') {
                    end += 2;
                } else {
                    end++;
                    closed = true;
                }
            }

            flush();
            segments.push({ type: 'literal', text: text.slice(index, end), closed });
            index = end;
        } else if (char === '[') {
            let end = index + 1;

            while (end < text.length && !'[]"\n'.includes(text[end])) {
                end++;
            }

            const closed = text[end] === ']';

            flush();
            segments.push({ type: 'name', text: text.slice(index, closed ? end + 1 : end), closed });
            index = closed ? end + 1 : end;
        } else {
            code += char;
            index++;
        }
    }

    flush();

    return segments;
}

/** A character of a typed word: a key's (`[A-Za-z0-9_.]`) or a label's letter (`Núm`). */
const WORD_CHAR = /[\p{L}\p{N}_.]/u;

/** Where the word that ends at `caret` starts: the run of `WORD_CHAR` before it. */
function tokenStart(source, caret) {
    let start = caret;

    while (start > 0 && WORD_CHAR.test(source[start - 1])) {
        start--;
    }

    return start;
}

/**
 * The token the caret completes, or null inside a text literal (the words there are the
 * user's own): a `name` from a `[` not closed before the caret — up to its `]` when the
 * caret is inside one, so applying replaces the whole name — or the `word` before it.
 */
function tokenAt(text, caret) {
    const segments = formulaSegments(text.slice(0, caret));
    const last = segments[segments.length - 1];

    if (last?.type === 'literal' && !last.closed) {
        return null;
    }

    if (last?.type === 'name' && !last.closed) {
        let end = caret;

        while (end < text.length && !'[]"\n'.includes(text[end])) {
            end++;
        }

        return { kind: 'name', start: caret - last.text.length, end: text[end] === ']' ? end + 1 : caret, token: last.text };
    }

    const start = tokenStart(text, caret);

    return { kind: 'word', start, end: caret, token: text.slice(start, caret) };
}

/**
 * What can be typed at the caret, as `{label, insert, group}`: `insert` replaces the token
 * before the caret (see `applySuggestion`), `label` is what the user knows it by, `group`
 * the model, collection or list it belongs to.
 *
 * - No token, or one that starts with a digit or a dot (a number): nothing.
 * - Inside a text literal: nothing — the words there are the user's own (`""` is an
 *   escaped quote).
 * - A token with a dot: the models and collections whose key starts with it (with their
 *   dot), the fields and collection columns whose full key does — a technical key typed
 *   by hand still completes to keys.
 * - A caret inside a word (a word character right after it): nothing — the rest of the
 *   word would be left after the insert.
 *
 * With a catalogue from `scopedCatalogue` (it has `groups`), names are offered (spec §5):
 *
 * - After a `[`: the names whose text, or whose field's label, starts with what follows
 *   it. A total is offered as its column's name: the user is writing a name, maybe
 *   already inside `SUM(`.
 * - A token without a dot: the items whose label starts with it, case-insensitively (an
 *   item shown by its key: by its key), inserted as shown — a total whole, `SUM([…])`,
 *   unless the token follows `SUM(` or `COUNT(` — then the functions whose name does.
 *
 * With the flat `formulaCatalogue`, a token without a dot offers the models and
 * collections whose key starts with it (with their dot), then the functions.
 *
 * Functions are inserted with `(`. A suggestion that is exactly the token is left out
 * (there is nothing to complete), and at most `MAX_SUGGESTIONS` come back.
 */
export function suggestionsAt(source, caret, catalogue) {
    const text = String(source ?? '');
    const end = Math.max(0, Math.min(caret ?? text.length, text.length));
    const at = tokenAt(text, end);
    const named = Array.isArray(catalogue?.groups);

    if (at === null) {
        return [];
    }

    if (at.kind === 'name') {
        return named ? nameSuggestions(at.token, catalogue).slice(0, MAX_SUGGESTIONS) : [];
    }

    const { start, token } = at;

    if (token === '' || !/^[\p{L}_]/u.test(token)) {
        return [];
    }

    // A caret inside a word: completing would leave the rest of it after the insert.
    if (WORD_CHAR.test(text[end] ?? '')) {
        return [];
    }

    const prefix = token.toLowerCase();
    const matches = (candidate) => candidate.toLowerCase().startsWith(prefix) && candidate !== token;
    const nodes = catalogue?.nodes ?? [];
    const collections = catalogue?.collections ?? [];
    const found = [];

    if (token.includes('.') || !named) {
        nodes.forEach((node) => {
            if (matches(node.id + '.')) {
                found.push({ label: node.label, insert: node.id + '.', group: node.label });
            }
        });
        collections.forEach((collection) => {
            if (matches(collection.key + '.')) {
                found.push({ label: collection.label, insert: collection.key + '.', group: collection.label });
            }
        });
    }

    if (token.includes('.')) {
        nodes.forEach((node) => node.fields.forEach((field) => {
            if (matches(field.key)) {
                found.push({ label: field.label, insert: field.key, group: node.label });
            }
        }));
        collections.forEach((collection) => collection.columns.forEach((column) => {
            const key = collection.key + '.' + column.key;

            if (matches(key)) {
                found.push({ label: column.label, insert: key, group: collection.label });
            }
        }));

        return found.slice(0, MAX_SUGGESTIONS);
    }

    if (named) {
        // Right after `SUM(`, a total is only its column's name: the bracket is already there.
        const inTotal = /\b(SUM|COUNT)\s*\(\s*$/i.test(text.slice(0, start));
        const seen = new Set();

        catalogue.groups.forEach((group) => group.items.forEach((item) => {
            const insert = group.kind === 'totals' && inTotal ? item.display : item.insert;
            const known = item.display === item.key ? item.key : String(item.label ?? '');

            if (!seen.has(insert) && insert !== token && known.toLowerCase().startsWith(prefix)) {
                seen.add(insert);
                found.push({ label: item.label, insert, group: group.label });
            }
        }));
    }

    (catalogue?.functions ?? []).forEach((entry) => {
        const name = typeof entry === 'string' ? entry : entry.name;

        // `SUM` typed in full still completes to `SUM(`: the bracket is the suggestion.
        if (name.toLowerCase().startsWith(prefix) && name + '(' !== token) {
            found.push({ label: name, insert: name + '(', group: catalogue.functionsLabel ?? '' });
        }
    });

    return found.slice(0, MAX_SUGGESTIONS);
}

/** The names behind a `[` not closed yet (see `suggestionsAt`), each once. */
function nameSuggestions(token, catalogue) {
    const prefix = token.slice(1).trimStart().toLowerCase();
    const seen = new Set();
    const found = [];

    catalogue.groups.forEach((group) => group.items.forEach((item) => {
        const name = item.display;

        if (!name.startsWith('[') || seen.has(name) || name === token) {
            return;
        }

        if (name.slice(1, -1).toLowerCase().startsWith(prefix) || String(item.label ?? '').toLowerCase().startsWith(prefix)) {
            seen.add(name);
            found.push({ label: item.label, insert: name, group: group.label });
        }
    }));

    return found;
}

/**
 * The source with the token at the caret replaced by the suggestion, and the caret right
 * after it. Only that token: whatever follows the caret stays as it was — except the rest
 * of a `[…]` name the caret is inside, which the new name replaces whole.
 */
export function applySuggestion(source, caret, suggestion) {
    const text = String(source ?? '');
    const end = Math.max(0, Math.min(caret ?? text.length, text.length));
    const at = tokenAt(text, end) ?? { start: end, end };

    return {
        source: text.slice(0, at.start) + suggestion.insert + text.slice(at.end),
        caret: at.start + suggestion.insert.length,
    };
}

/**
 * Formula results for the canvas, asked of the host (CGE-37).
 *
 * The same shape as `createFormatPreview`: `result()` answers synchronously — the cached
 * answer, or `null` while it is being asked — and `onReady` is called once per answered
 * batch so the canvas redraws. Two differences, both about typing:
 *
 * - The ask waits `debounceMs` after the last NEW ask, not one tick: the source changes
 *   on every keystroke, and each intermediate text would otherwise be a request.
 * - An ask may name a `slot` (the field asking). A newer ask from the same slot replaces
 *   its older one still waiting, so `1`, `1 +` and `1 + 2` typed in one box send only
 *   `1 + 2`.
 *
 * EVERY answer is cached, errors and failed requests included: the redraw `onReady`
 * triggers asks again, and an uncached key would loop for ever. A failed request answers
 * `{value: '', error: null}` — the network being down is not something wrong with the
 * formula, and V-19 still checks it on save.
 *
 * `selection` returns the real records the sample panel shows (or null); it is sent with
 * the batch and is part of the cache key, so picking a record recalculates.
 */
export function createFormulaPreview({
    url, headers = {}, fetchImpl = (...args) => fetch(...args), debounceMs = 400, onReady = () => {}, selection = () => null,
}) {
    const cache = new Map();
    const inFlight = new Set();
    // key -> {item, selection}; insertion order is the order the items are sent in.
    const pending = new Map();
    // slot -> the key it last asked for, while that ask is pending
    const slots = new Map();
    let timer = null;

    const keyOf = (source, collection, chosen) => JSON.stringify([source, collection ?? null, chosen ?? null]);

    async function send(entries, chosen) {
        let results = [];

        try {
            const response = await fetchImpl(url, {
                method: 'POST',
                headers: { ...headers, 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({
                    items: entries.map(([, entry]) => entry.item),
                    ...(chosen ? { selection: chosen } : {}),
                }),
            });

            if (!response.ok) {
                throw new Error('formula preview failed');
            }

            results = (await response.json()).results ?? [];
        } catch {
            results = [];
        }

        entries.forEach(([key], index) => {
            const answer = results[index];

            cache.set(key, answer && typeof answer === 'object'
                ? { value: answer.value ?? '', error: answer.error ?? null }
                : { value: '', error: null });
            inFlight.delete(key);
        });
    }

    async function flush() {
        timer = null;

        const batch = [...pending.entries()];
        pending.clear();
        slots.clear();

        if (!batch.length) {
            return;
        }

        batch.forEach(([key]) => inFlight.add(key));

        // One request per selection (in practice there is one) and per 50 items.
        const groups = new Map();

        batch.forEach((entry) => {
            const group = JSON.stringify(entry[1].selection ?? null);

            groups.set(group, [...(groups.get(group) ?? []), entry]);
        });

        const requests = [];

        groups.forEach((entries) => {
            for (let start = 0; start < entries.length; start += MAX_ITEMS) {
                const chunk = entries.slice(start, start + MAX_ITEMS);

                requests.push(send(chunk, chunk[0][1].selection));
            }
        });

        await Promise.all(requests);

        onReady();
    }

    return {
        result(source, collection = null, slot = null) {
            const text = String(source ?? '');

            if (!url || text.trim() === '') {
                return null;
            }

            const chosen = selection() ?? null;
            const key = keyOf(text, collection, chosen);

            if (cache.has(key)) {
                return cache.get(key);
            }

            if (inFlight.has(key) || pending.has(key)) {
                return null;
            }

            if (slot !== null && slots.has(slot)) {
                pending.delete(slots.get(slot));
            }

            pending.set(key, { item: collection ? { source: text, collection } : { source: text }, selection: chosen });

            if (slot !== null) {
                slots.set(slot, key);
            }

            clearTimeout(timer);
            timer = setTimeout(flush, debounceMs);

            return null;
        },
    };
}

/* ------------------------------------------------- the box's assistance */

/**
 * The autocomplete of one formula box — the data field's and every formula column's
 * (CGE-37). Returns the pieces for the caller to place: `field` (the textarea, moved into
 * a wrapper) and `suggestions` (the list, right under it). What every field and function
 * is, with an example, is the "Campos e funções" library's to show (spec §6.3).
 *
 * Applying a suggestion writes the textarea and dispatches a bubbling `input`, so the
 * owner's own handler — the trait's `onEvent`, the column card's listener — stores the
 * source and asks for the live result exactly as for a keystroke.
 *
 * Keydowns stop in the textarea: GrapesJS's shortcuts (delete, undo, arrows) act on the
 * selected block and must never fire while the user types a formula. Host chords such as
 * Ctrl+S still get through (see `keepsKeyInFormulaBox`).
 *
 * `catalogue`: what to suggest — a function returning a `scopedCatalogue`, so the
 * suggestions are readable names; without one, the flat key catalogue.
 */
export function formulaAssist(input, config, { catalogue = null } = {}) {
    const strings = config.strings ?? {};

    const field = document.createElement('div');
    field.className = 'tpl-formula-input';
    field.append(input);

    const suggestions = document.createElement('ul');
    suggestions.className = 'tpl-formula-suggest';
    suggestions.setAttribute('role', 'listbox');
    suggestions.hidden = true;

    let items = [];
    let active = 0;

    const close = () => {
        items = [];
        suggestions.hidden = true;
        suggestions.replaceChildren();
    };

    const paint = () => {
        suggestions.replaceChildren();

        let group = null;

        items.forEach((item, index) => {
            if (item.group && item.group !== group) {
                group = item.group;

                const head = document.createElement('li');
                head.className = 'tpl-formula-suggest__group';
                head.setAttribute('role', 'presentation');
                head.textContent = group;
                suggestions.append(head);
            }

            const option = document.createElement('li');
            option.className = 'tpl-formula-suggest__item' + (index === active ? ' is-active' : '');
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', String(index === active));

            const code = document.createElement('code');
            code.textContent = item.insert;
            option.append(code);

            if (item.label && item.label !== item.insert && item.label + '(' !== item.insert) {
                const label = document.createElement('span');
                label.textContent = item.label;
                option.append(label);
            }

            // On mousedown, not click: a click lands after the textarea's blur, and the
            // blur would have closed the list (and run the owner's `change`) first.
            option.addEventListener('mousedown', (event) => {
                event.preventDefault();
                apply(item);
            });

            suggestions.append(option);
        });

        suggestions.hidden = items.length === 0;
        suggestions.querySelector('.is-active')?.scrollIntoView?.({ block: 'nearest' });
    };

    const refresh = () => {
        const scoped = typeof catalogue === 'function' ? catalogue() : catalogue;
        const offered = { ...(scoped ?? formulaCatalogue(config)), functionsLabel: strings['formula.functions_group'] ?? '' };

        items = suggestionsAt(input.value, input.selectionStart, offered);
        active = 0;
        paint();
    };

    function apply(item) {
        const next = applySuggestion(input.value, input.selectionStart, item);

        input.value = next.source;
        input.focus();
        input.setSelectionRange(next.caret, next.caret);
        // Bubbling, so a trait's delegated `input textarea` handler hears it too. It also
        // comes back through `refresh`: after `customer.` the list goes on to its fields.
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }

    input.addEventListener('input', refresh);
    input.addEventListener('blur', close);
    input.addEventListener('click', close);

    input.addEventListener('keydown', (event) => {
        if (keepsKeyInFormulaBox(event)) {
            event.stopPropagation();
        }

        if (items.length === 0) {
            return;
        }

        switch (event.key) {
            case 'ArrowDown':
            case 'ArrowUp':
                event.preventDefault();
                active = (active + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
                paint();
                break;
            case 'Enter':
            case 'Tab':
                // Only the plain key: Shift+Tab moves focus back, Shift+Enter is a new line.
                if (event.shiftKey || event.ctrlKey || event.metaKey || event.altKey) {
                    close();
                    break;
                }

                event.preventDefault();
                apply(items[active]);
                break;
            case 'Escape':
                event.preventDefault();
                close();
                break;
            case 'ArrowLeft':
            case 'ArrowRight':
            case 'Home':
            case 'End':
                // The caret leaves the token: what was suggested no longer applies.
                close();
                break;
            default:
        }
    });

    return { field, suggestions };
}

/* ---------------------------------------------------------------- traits */

/**
 * The two traits of a data field's value (spec §6.1).
 *
 * Both return a WRAPPER element from `createInput`, not an input. GrapesJS's default
 * `onChange` copies `elInput.value` into the trait's value, and the trait writes its value
 * to the component's attribute named after it: a bare radio would write `"formula"` into
 * an attribute, and a bare textarea would only write on blur. A wrapper has no `value`, so
 * nothing is written behind the component's back — each trait writes through the
 * component's own methods in `onEvent`. `setInputValue` is a no-op for the same reason:
 * GrapesJS would otherwise park the attribute's value on the wrapper as an expando
 * `value`, and the next keystroke would write that stale copy back first.
 */
export function registerFormula(editor, config) {
    const strings = config.strings;

    /*
     * "Valor: Campo | Fórmula". Its state is not stored anywhere: it is read from the
     * binding (`formula.*` or not), so it can never disagree with what is exported, and
     * undo of a binding change turns it back by itself. GrapesJS's own radio markup, so the
     * segmented look of the style manager's radios (`.gjs-radio-items`) applies as it is.
     */
    editor.TraitManager.addType('tpl-value-mode', {
        eventCapture: ['change input'],

        setInputValue() {},

        createInput({ component }) {
            const wrap = document.createElement('div');
            wrap.className = 'tpl-value-mode';

            const items = document.createElement('div');
            items.className = 'gjs-radio-items';
            items.setAttribute('role', 'radiogroup');
            items.setAttribute('aria-label', strings['formula.value'] ?? 'Value');

            const group = `tpl-value-mode-${component.cid}`;

            [['field', 'formula.value_field', 'Field'], ['formula', 'formula.value_formula', 'Formula']]
                .forEach(([value, key, fallback]) => {
                    const item = document.createElement('div');
                    item.className = 'gjs-radio-item';

                    const input = document.createElement('input');
                    input.type = 'radio';
                    input.className = 'gjs-sm-radio';
                    input.name = group;
                    input.id = `${group}-${value}`;
                    input.value = value;

                    const label = document.createElement('label');
                    label.className = 'gjs-radio-item-label';
                    label.htmlFor = input.id;
                    label.textContent = strings[key] ?? fallback;

                    item.append(input, label);
                    items.append(item);
                });

            wrap.append(items);
            paintValueMode(wrap, component, strings);

            return wrap;
        },

        onEvent({ elInput, component }) {
            const chosen = [...elInput.querySelectorAll('input')].find((input) => input.checked)?.value;

            component.setFormulaMode?.(chosen === 'formula');
            // Repainted whatever happened: a refused switch (a held field) goes back.
            paintValueMode(elInput, component, strings);
        },

        onUpdate({ elInput, component }) {
            paintValueMode(elInput, component, strings);
        },
    });

    /*
     * The formula box: the formula with readable names (`[Cliente · Nome]`), what is wrong
     * with it or what it gives, the quick examples and "Concluir". The attribute holds the
     * technical source and is written on every keystroke, so a save is never behind what
     * the box shows; only the server ask waits (the debounce is `createFormulaPreview`'s).
     * The readable text lives in the box only: nothing here writes it anywhere else.
     *
     * Focusing the box opens "Campos e funções" over the Blocks rail (spec §6.3); "Concluir"
     * closes it (as selecting another block does, see editor.js).
     */
    editor.TraitManager.addType('tpl-formula', {
        eventCapture: ['input textarea'],

        setInputValue() {},

        createInput({ component }) {
            const wrap = document.createElement('div');
            wrap.className = 'tpl-formula';

            const input = document.createElement('textarea');
            input.rows = 3;
            input.className = 'tpl-textarea tpl-formula-source';
            input.spellcheck = false;
            // The example in the placeholder is written with keys; shown by name like the rest.
            input.placeholder = component.formulaDisplay?.(strings['formula.placeholder'] ?? '') ?? strings['formula.placeholder'] ?? '';
            input.setAttribute('aria-label', strings['formula.source'] ?? 'Formula');
            input.value = component.formulaDisplay?.() ?? component.getAttributes()[FORMULA_SOURCE_TRAIT] ?? '';
            // Who this box is for: a format change draws the traits again, and the library
            // follows the new box instead of inserting into the one taken away (`adopt`).
            input.dataset.formulaOwner = String(component.cid);

            input.addEventListener('focus', () => {
                config.formulaLibrary?.open({ target: input, rowCollection: null });
            });

            const status = document.createElement('p');
            status.className = 'tpl-formula-status';
            status.setAttribute('aria-live', 'polite');

            const assist = formulaAssist(input, config, { catalogue: () => component.formulaNames?.() ?? null });

            wrap.append(assist.field, assist.suggestions, status);

            const examples = component.formulaQuickExamples?.() ?? [];

            if (examples.length) {
                const caption = document.createElement('p');
                caption.className = 'tpl-formula-help';
                caption.textContent = strings['formula.quick.heading'] ?? '';

                const list = document.createElement('div');
                list.className = 'tpl-formula-examples';

                examples.forEach((example) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'tpl-btn tpl-btn--secondary tpl-btn--sm tpl-formula-example';
                    button.textContent = example.label;
                    button.title = component.formulaDisplay?.(example.source) ?? example.source;

                    // Fills the box and goes the way typing goes: the bubbling `input` is
                    // what the trait listens to.
                    button.addEventListener('click', () => {
                        input.value = component.formulaDisplay?.(example.source) ?? example.source;
                        input.focus();
                        input.setSelectionRange(input.value.length, input.value.length);
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                    });

                    list.append(button);
                });

                wrap.append(caption, list);
            }

            const actions = document.createElement('div');
            actions.className = 'tpl-formula-actions';

            const done = document.createElement('button');
            done.type = 'button';
            done.className = 'tpl-btn tpl-btn--primary tpl-btn--sm tpl-formula-done';
            done.textContent = strings['formula.done'] ?? 'Done';
            done.addEventListener('click', () => {
                config.formulaLibrary?.close();
                input.blur();
            });

            actions.append(done);
            wrap.append(actions);

            paintFormulaStatus(wrap, component.formulaStatus?.(), strings);
            config.formulaLibrary?.adopt?.(input);

            return wrap;
        },

        onEvent({ elInput, component }) {
            const value = elInput.querySelector('textarea').value;

            if (component.setFormulaDisplay) {
                component.setFormulaDisplay(value);
            } else {
                component.addAttributes({ [FORMULA_SOURCE_TRAIT]: value });
            }
        },

        onUpdate({ elInput, component }) {
            const input = elInput.querySelector('textarea');
            const value = component.getAttributes()[FORMULA_SOURCE_TRAIT] ?? '';
            const shown = component.formulaSourceOf ? component.formulaSourceOf(input?.value ?? '') : input?.value;

            // Only when the box says something else (an undo): the box shows names, the
            // attribute keys, so the texts always differ. Never while the user types in it
            // — resetting the value moves the caret — and never just because an answer
            // arrived while the focus is in the library's search, or the library would
            // insert at the end instead of at the caret.
            if (input && shown !== value && input.ownerDocument.activeElement !== input) {
                input.value = component.formulaDisplay?.() ?? value;
            }

            paintFormulaStatus(elInput, component.formulaStatus?.(), strings);
        },
    });
}

/**
 * Repaint both traits from the component, when what they show changed without their
 * attribute changing: a server answer arrived, or the field became (or stopped being) the
 * last one carrying a legal or mandatory binding. `trait.el` is the wrapper `createInput`
 * returned; absent until first shown.
 */
export function refreshFormulaTraits(component, strings) {
    const toggle = component.getTrait?.(VALUE_MODE_TRAIT)?.el;
    const box = component.getTrait?.(FORMULA_SOURCE_TRAIT)?.el;

    if (toggle) {
        paintValueMode(toggle, component, strings);
    }

    if (box) {
        paintFormulaStatus(box, component.formulaStatus?.(), strings);
    }
}

function paintValueMode(wrap, component, strings) {
    const formula = isFormulaKey(component.getAttributes()['data-tpl-binding']);
    // Only asked of a field: a formula carries no binding to hold.
    const lock = formula ? null : component.formulaLock?.() ?? null;

    wrap.querySelectorAll('input').forEach((input) => {
        input.checked = input.value === (formula ? 'formula' : 'field');
        // The last block with a legal or mandatory field never becomes a formula: the
        // document would lose a field the type requires. The server would refuse it too
        // (V-02/V-10); the switch says so first, and why, on hover.
        input.disabled = input.value === 'formula' && lock !== null;
    });

    wrap.classList.toggle('is-locked', lock !== null);
    wrap.title = strings[lock === null ? 'formula.toggle' : `formula.${lock}_locked`] ?? '';
}

/**
 * The line under the formula box. `status` is `{state, text}` from the component:
 * `error` (what the server says is wrong), `result` (the formatted value), `blank` (a
 * result that prints nothing), `pending`, or `empty` (no source yet: no line).
 */
export function paintFormulaStatus(wrap, status, strings) {
    const line = wrap?.querySelector?.('.tpl-formula-status');

    if (!line) {
        return;
    }

    const state = status?.state ?? 'empty';

    line.className = 'tpl-formula-status is-' + state;
    line.textContent = state === 'error'
        ? status.text
        : state === 'result'
            ? (strings['formula.result'] ?? ':value').replace(':value', status.text)
            : state === 'pending'
                ? (strings['formula.calculating'] ?? '')
                : state === 'blank'
                    ? (strings['formula.blank'] ?? '')
                    : '';
}
