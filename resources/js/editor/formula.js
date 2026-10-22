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

/** The functions of Linguagem v1, listed under the formula box as a reminder. */
export const FORMULA_FUNCTIONS = [
    'IF', 'AND', 'OR', 'NOT', 'SUM', 'COUNT', 'ROUND', 'ABS', 'MIN', 'MAX', 'UPPER', 'LOWER',
    'CONCAT', 'LEFT', 'RIGHT', 'LEN', 'TRIM', 'YEAR', 'MONTH', 'DAY', 'DAYS', 'TEXT',
];

const FORMULA_PREFIX = 'formula.';

/** The traits' names: the switch has no attribute of its own; the box is the source's. */
export const FORMULA_TOGGLE_TRAIT = 'tpl-formula-mode';
export const FORMULA_SOURCE_TRAIT = 'data-tpl-formula';

/** The endpoint refuses a batch above this (FormulaPreviewController::MAX_ITEMS). */
const MAX_ITEMS = 50;

const ID_ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

/**
 * A new formula id: eight of `[a-z0-9]`, which the server's key pattern
 * (`[a-z0-9]{6,12}`) accepts. Random rather than counted: a layout is edited across
 * sessions and copied between formats, and no counter survives that.
 */
export function newFormulaId() {
    const bytes = new Uint8Array(8);
    globalThis.crypto.getRandomValues(bytes);

    return Array.from(bytes, (byte) => ID_ALPHABET[byte % ID_ALPHABET.length]).join('');
}

export function isFormulaKey(key) {
    return typeof key === 'string' && key.startsWith(FORMULA_PREFIX);
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

/* ---------------------------------------------------------------- traits */

/**
 * The two traits of a formula field.
 *
 * Both return a WRAPPER element from `createInput`, not the input itself. GrapesJS's
 * default `onChange` copies `elInput.value` into the trait's value, and the trait writes
 * its value to the component's attribute named after it: a bare checkbox would write
 * `"on"` into an attribute, and a bare textarea would only write on blur. A wrapper has
 * no `value`, so nothing is written behind the component's back — each trait writes
 * through the component's own methods in `onEvent`. `setInputValue` is a no-op for the
 * same reason: GrapesJS would otherwise park the attribute's value on the wrapper as an
 * expando `value`, and the next keystroke would write that stale copy back first.
 */
export function registerFormula(editor, config) {
    const strings = config.strings;

    /*
     * The "Fórmula" switch. Its state is not stored anywhere: it is read from the binding
     * (`formula.*` or not), so it can never disagree with what is exported, and undo of
     * a binding change turns it back by itself.
     */
    editor.TraitManager.addType('tpl-formula-toggle', {
        eventCapture: ['change input'],

        setInputValue() {},

        createInput({ component }) {
            const wrap = document.createElement('label');
            wrap.className = 'tpl-formula-toggle';

            const input = document.createElement('input');
            input.type = 'checkbox';

            const text = document.createElement('span');
            text.textContent = strings['formula.toggle'] ?? 'Formula';

            wrap.append(input, text);
            paintToggle(wrap, component, strings);

            return wrap;
        },

        onEvent({ elInput, component }) {
            component.setFormulaMode?.(elInput.querySelector('input').checked);
            paintToggle(elInput, component, strings);
        },

        onUpdate({ elInput, component }) {
            paintToggle(elInput, component, strings);
        },
    });

    /*
     * The formula box: the source, then a line with what is wrong with it or what it
     * gives. The attribute is written on every keystroke, so a save is never behind what
     * the box shows; it is only the server ask that waits (the debounce lives in
     * `createFormulaPreview`).
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
            input.placeholder = strings['formula.placeholder'] ?? '';
            input.value = component.getAttributes()['data-tpl-formula'] ?? '';

            const status = document.createElement('p');
            status.className = 'tpl-formula-status';
            status.setAttribute('aria-live', 'polite');

            const functions = document.createElement('p');
            functions.className = 'tpl-formula-help';
            functions.textContent = (strings['formula.functions'] ?? ':list')
                .replace(':list', (config.formulaFunctions ?? FORMULA_FUNCTIONS).join(', '));

            wrap.append(input, status, functions);
            paintFormulaStatus(wrap, component.formulaStatus?.(), strings);

            return wrap;
        },

        onEvent({ elInput, component }) {
            component.addAttributes({ 'data-tpl-formula': elInput.querySelector('textarea').value });
        },

        onUpdate({ elInput, component }) {
            const input = elInput.querySelector('textarea');
            const value = component.getAttributes()['data-tpl-formula'] ?? '';

            // Never while the user types in it: resetting the value moves the caret.
            if (input && input.value !== value && input.ownerDocument.activeElement !== input) {
                input.value = value;
            }

            paintFormulaStatus(elInput, component.formulaStatus?.(), strings);
        },
    });
}

/**
 * Repaint both traits from the component, when what they show changed without their
 * attribute changing: a server answer arrived, or the binding became (or stopped being)
 * legal. `trait.el` is the wrapper `createInput` returned; absent until first shown.
 */
export function refreshFormulaTraits(component, strings) {
    const toggle = component.getTrait?.(FORMULA_TOGGLE_TRAIT)?.el;
    const box = component.getTrait?.(FORMULA_SOURCE_TRAIT)?.el;

    if (toggle) {
        paintToggle(toggle, component, strings);
    }

    if (box) {
        paintFormulaStatus(box, component.formulaStatus?.(), strings);
    }
}

function paintToggle(wrap, component, strings) {
    const input = wrap.querySelector('input');
    const legal = Boolean(component.isLegalBinding?.());

    input.checked = isFormulaKey(component.getAttributes()['data-tpl-binding']);
    // A legal field never becomes a formula: the document would lose the field the law
    // asks for. The server would refuse it too (V-02/V-10); the switch says so first.
    input.disabled = legal;
    wrap.classList.toggle('is-disabled', legal);
    wrap.title = legal ? (strings['formula.legal_locked'] ?? '') : '';
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
