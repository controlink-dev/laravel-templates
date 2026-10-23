/* ============================================================================
 * "Add column" of the line table (CGE-85).
 *
 * A tree with a search box under the column cards: the table's own columns (already in
 * it), then one group per linked table with its fields, and — fixed at the end — the
 * calculated column. `pickerTree` is the tree as data, pure, so what the picker offers is
 * tested without a DOM; `columnPicker` draws it.
 * ========================================================================= */

/** Text as the search compares it: no accents, lower case, trimmed. */
export function foldText(value) {
    return String(value ?? '').normalize('NFD').replace(/\p{M}/gu, '').toLowerCase().trim();
}

/**
 * The picker's groups for the collection `binding` and the table's `columns`, filtered by
 * `query`. Group `own` holds the collection's own columns (no `linked`), one group per link
 * alias holds that link's fields, in catalogue order. A field already in the table — even
 * hidden: its eye shows it again — is `inTable`. The search matches the field's label or
 * its link's, ignoring case and accents; groups left empty are dropped. The calculated
 * column is never here: it is the picker's fixed last entry.
 *
 * @returns {Array<{group: string, label: string, items: Array<{key: string, label: string, inTable: boolean}>}>}
 */
export function pickerTree(binding, columns, query = '') {
    const present = new Set((columns ?? []).map((column) => column?.key));
    const wanted = foldText(query);
    const matches = (...labels) => wanted === '' || labels.some((label) => foldText(label).includes(wanted));
    const groups = new Map([['own', { group: 'own', label: binding?.label ?? '', items: [] }]]);

    for (const entry of binding?.columns ?? []) {
        const link = entry.linked;
        const fieldLabel = link ? (link.field_label ?? entry.label) : entry.label;

        if (link && !groups.has(link.alias)) {
            groups.set(link.alias, { group: link.alias, label: link.label, items: [] });
        }

        if (!matches(fieldLabel, link?.label)) {
            continue;
        }

        groups.get(link ? link.alias : 'own').items.push({ key: entry.key, label: fieldLabel, inTable: present.has(entry.key) });
    }

    return [...groups.values()].filter((group) => group.items.length > 0);
}

/**
 * The picker, drawn. `state` ({query, expanded: Set}) lives with the caller, so a redrawn
 * panel opens the picker as it was. Typing redraws the tree only, never the search box,
 * which keeps its caret. `onPick(entry)` gets the chosen catalogue entry; `onCalculated()`
 * the fixed last entry.
 */
export function columnPicker({ binding, columns, strings, state, onPick, onCalculated, icons }) {
    const picker = document.createElement('div');
    picker.className = 'tpl-picker';

    const search = document.createElement('input');
    search.type = 'search';
    search.className = 'gjs-field tpl-picker__search';
    search.placeholder = strings['trait.column_search'] ?? '';
    search.setAttribute('aria-label', search.placeholder);
    search.value = state.query;

    const tree = document.createElement('div');
    tree.className = 'tpl-picker__tree';

    const calculatedLabel = strings['trait.calculated_column'] ?? 'Calculated column';

    const drawTree = () => {
        tree.replaceChildren();

        const searching = foldText(state.query) !== '';

        pickerTree(binding, columns, state.query).forEach((group) => {
            const open = searching || state.expanded.has(group.group);

            const head = document.createElement('button');
            head.type = 'button';
            head.className = 'tpl-picker__group';
            head.setAttribute('aria-expanded', String(open));
            head.innerHTML = icons.chevron + (group.group === 'own' ? '' : icons.link);
            head.append(document.createTextNode(group.label));
            head.addEventListener('click', () => {
                if (state.expanded.has(group.group)) {
                    state.expanded.delete(group.group);
                } else {
                    state.expanded.add(group.group);
                }

                drawTree();
            });
            tree.append(head);

            if (!open) {
                return;
            }

            group.items.forEach((item) => {
                const leaf = document.createElement('button');
                leaf.type = 'button';
                leaf.className = 'tpl-picker__leaf';
                leaf.textContent = item.label;
                leaf.disabled = item.inTable;

                if (item.inTable) {
                    const note = document.createElement('span');
                    note.className = 'tpl-picker__in';
                    note.textContent = strings['trait.already_in_table'] ?? '';
                    leaf.append(note);
                } else {
                    leaf.addEventListener('click', () => {
                        const entry = (binding?.columns ?? []).find((candidate) => candidate.key === item.key);

                        if (entry) {
                            onPick(entry);
                        }
                    });
                }

                tree.append(leaf);
            });
        });

        if (!searching || foldText(calculatedLabel).includes(foldText(state.query))) {
            const calculated = document.createElement('button');
            calculated.type = 'button';
            calculated.className = 'tpl-picker__leaf tpl-picker__calc';
            calculated.innerHTML = icons.calculator;
            calculated.append(document.createTextNode(calculatedLabel));
            calculated.addEventListener('click', () => onCalculated());
            tree.append(calculated);
        }

        if (tree.childElementCount === 0) {
            const empty = document.createElement('div');
            empty.className = 'tpl-picker__empty';
            empty.textContent = strings['trait.column_no_match'] ?? '';
            tree.append(empty);
        }
    };

    // Not bubbling to the trait: GrapesJS's own `change` handler would copy the wrapper's
    // stale `value` into `data-tpl-columns` (see the value-mode radios).
    search.addEventListener('input', (event) => {
        event.stopPropagation();
        state.query = search.value;
        drawTree();
    });
    search.addEventListener('change', (event) => event.stopPropagation());

    drawTree();
    picker.append(search, tree);

    return picker;
}
