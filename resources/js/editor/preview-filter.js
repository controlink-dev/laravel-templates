/**
 * Preview with real records (CGE-29).
 *
 * A template is designed against the catalogue's EXAMPLE values. This module lets the
 * editor swap, per node, those examples for the values of a real record the user picks
 * (a singular node), or for the rows of a filtered collection (the line table).
 *
 * Everything here is EPHEMERAL, and that is the whole contract:
 *
 * - The state lives in a plain object on `config.preview`, never in the GrapesJS model,
 *   `project_data`, a trait or an attribute.
 * - What reaches the canvas is the `content` of the data-field and line-table blocks,
 *   which GrapesJS does serialise. So every read of the project data that matters (the
 *   dirty check and the save) goes through `plainProjectData()`, which puts the example
 *   content back — silently, for the length of that one synchronous read — before
 *   exporting. Real values therefore never reach a saved version, and swapping a record
 *   never marks the layout as changed.
 * - Nothing is persisted in the browser either.
 */

/** How many collection rows the canvas draws (the panel lists the same ones). */
export const PREVIEW_ROW_LIMIT = 5;

const FILTER_DEBOUNCE_MS = 400;

const states = new WeakMap();

/**
 * The preview state of one editor, created once and shared with the block types through
 * `config.preview`.
 */
export function initPreviewState(editor, config) {
    const state = {
        // nodeId -> the chosen record's presented values (`nodeId.column` -> text)
        nodes: {},
        // nodeId -> the chosen record's id, for the server-side `selection` (CGE-37)
        ids: {},
        // collection binding key -> the filtered presented rows
        rows: {},
        // the merged `nodes`, the one lookup a block does
        values: {},

        isActive() {
            return Object.keys(this.values).length > 0 || Object.keys(this.rows).length > 0;
        },

        setNode(nodeId, values, recordId = null) {
            if (values) {
                this.nodes[nodeId] = values;
            } else {
                delete this.nodes[nodeId];
            }

            if (values && recordId !== null && recordId !== undefined) {
                this.ids[nodeId] = String(recordId);
            } else {
                delete this.ids[nodeId];
            }

            this.values = Object.assign({}, ...Object.values(this.nodes));
        },

        setRows(key, rows) {
            if (Array.isArray(rows) && rows.length > 0) {
                this.rows[key] = rows;
            } else {
                delete this.rows[key];
            }
        },
    };

    config.preview = state;
    states.set(editor, state);

    return state;
}

/**
 * The records being previewed, as the host's `selection` takes them
 * (`VersionController::preview`): the chosen record per node, and the ids of the rows
 * shown per collection. Null when the canvas shows the example — the host then uses the
 * type's fixture. The formula preview sends it, so a live result is the one the chosen
 * records give (CGE-37).
 */
export function selectionOf(state) {
    const nodes = { ...(state?.ids ?? {}) };
    const collections = {};

    Object.entries(state?.rows ?? {}).forEach(([key, rows]) => {
        const ids = (rows ?? []).slice(0, PREVIEW_ROW_LIMIT)
            .map((row) => row?.id)
            .filter((id) => id !== null && id !== undefined && id !== '')
            .map(String);

        if (ids.length > 0) {
            collections[key] = ids;
        }
    });

    return Object.keys(nodes).length > 0 || Object.keys(collections).length > 0 ? { nodes, collections } : null;
}

/** A data field's text: the chosen record's value when there is one, else the example. */
export function previewValue(config, key) {
    const values = config.preview?.values;

    return values && Object.prototype.hasOwnProperty.call(values, key)
        ? values[key]
        : config.fixture?.[key];
}

/** The rows the line table draws: the filtered real rows, else the example's first row. */
export function previewRows(config, key) {
    const real = config.preview?.rows?.[key];

    if (Array.isArray(real) && real.length > 0) {
        return real.slice(0, PREVIEW_ROW_LIMIT);
    }

    const example = config.fixture?.[key];

    return Array.isArray(example) ? example.slice(0, 1) : [];
}

function walk(component, callback) {
    // No wrapper while GrapesJS swaps a format's project data in: the old page is gone
    // and the new one not built yet, and `component:remove` still fires (dirty check).
    if (!component) {
        return;
    }

    callback(component);

    component.components().forEach((child) => walk(child, callback));
}

/** Redraw every block that shows a sample value, from the current preview state. */
export function refreshPreview(editor) {
    walk(editor.getWrapper(), (component) => {
        if (typeof component.syncPreview === 'function') {
            component.syncPreview();
        }
    });
}

/**
 * `editor.getProjectData()` as if no real record were being previewed.
 *
 * The sample blocks' `content` is part of the project data, so the example text is put
 * back for the duration of the read and the real text restored afterwards. Both swaps are
 * silent: no event, no re-render, no dirty flag. Synchronous on purpose — nothing can
 * observe the in-between state.
 */
export function plainProjectData(editor) {
    // Always walked, not only while a real record is shown: the canvas text is also in
    // each field's format now (CGE-49), and that answer arrives after the load snapshot.
    const swapped = [];

    walk(editor.getWrapper(), (component) => {
        if (typeof component.previewContent !== 'function') {
            return;
        }

        const real = component.get('content');
        const example = component.previewContent(true);

        if (real !== example) {
            swapped.push([component, real]);
            component.set('content', example, { silent: true });
        }
    });

    try {
        return editor.getProjectData();
    } finally {
        swapped.forEach(([component, real]) => component.set('content', real, { silent: true }));
    }
}

/* ------------------------------------------------------------------------ panel */

const text = (config, key, fallback) => config.strings?.[`preview.${key}`] ?? fallback;

const fill = (template, values) => Object.entries(values)
    .reduce((out, [name, value]) => out.replace(`:${name}`, String(value)), template);

function el(tag, className, content) {
    const node = document.createElement(tag);

    if (className) {
        node.className = className;
    }

    if (content !== undefined) {
        node.textContent = content;
    }

    return node;
}

/**
 * The data sources actually BOUND on the sheet — one preview target per source.
 *
 * CGE-29b: the cards follow what the user placed, not the document type's whole catalogue.
 * The wrapper is walked for `data-tpl-binding` (singular fields and code slots) and
 * `data-tpl-collection` (line tables); only the sources those point at become targets.
 *
 * GrapesJS `Component.find(selector)` is deliberately NOT used: it queries the rendered
 * canvas view (`view.$el.find`), which need not exist yet when this first runs — the panel
 * mounts before `setComponents`. Walking the model tree is render-independent and reads the
 * very same attributes.
 *
 * A binding's singular node is the catalogue node whose id is a PREFIX of the key
 * (`key === id` or `key` starts with `id + '.'`): node ids carry no dots, a column path
 * may, so the dot after the id is the boundary (mirrors the host's `substr(id)+1`) rather
 * than splitting on the first dot. Collection targets come only from `config.bindings`.
 *
 * Stable order: nodes in catalogue declaration order, then collection bindings in theirs,
 * filtered to those in use — the cards never reshuffle when blocks are moved around.
 */
function collectTargets(config, editor) {
    const nodes = (config.nodes ?? []).filter((node) => !node.collection && node.reachable !== false);
    const bindings = config.bindings ?? [];

    const usedNodeIds = new Set();
    const usedCollections = new Set();

    walk(editor.getWrapper(), (component) => {
        const attributes = component.getAttributes?.() ?? {};

        const binding = attributes['data-tpl-binding'] ?? '';

        if (binding !== '') {
            const node = nodes.find((entry) => binding === entry.id || binding.startsWith(entry.id + '.'));

            if (node) {
                usedNodeIds.add(node.id);
            }
        }

        const collection = attributes['data-tpl-collection'] ?? '';

        if (collection !== '') {
            usedCollections.add(collection);
        }
    });

    const targets = [];

    nodes.forEach((node) => {
        if (usedNodeIds.has(node.id)) {
            targets.push({ kind: 'node', id: node.id, label: node.label ?? node.id });
        }
    });

    bindings.forEach((binding) => {
        if (usedCollections.has(binding.key)) {
            targets.push({ kind: 'collection', id: binding.key, label: binding.label ?? binding.key });
        }
    });

    return targets;
}

/**
 * Add the "Sample" tab and its panel beside the style / field / layers ones, and wire
 * them to the endpoint. Returns null (and adds nothing) when the host gave no URL, so a
 * host that does not offer real records keeps the editor exactly as it was.
 *
 * The tab is created here rather than in the host's Blade so the bundle stays additive:
 * the existing tab handler picks it up through the same `data-tpl-tab` / `data-tpl-panel`
 * attributes, and it must be added before that handler runs.
 */
export function mountPreviewPanel({ editor, config, root, headers }) {
    const url = config.previewRecordsUrl;
    const tabList = root.querySelector('.navigation-tabs-list');
    const anchor = root.querySelector('[data-tpl-panel="layers"]');

    // A host with no preview endpoint (or no place to hang the tab) keeps the editor
    // exactly as it was. The tab mounts even with nothing bound yet: binding fills it.
    if (!url || !tabList || !anchor) {
        return null;
    }

    const state = initPreviewState(editor, config);

    const tab = el('button', 'navigation-tabs-tab', text(config, 'tab', 'Sample'));
    tab.type = 'button';
    tab.dataset.tplTab = 'preview';
    tabList.append(tab);

    const panel = el('div', 'tpl-panel-scroll tpl-pf');
    panel.dataset.tplPanel = 'preview';
    panel.hidden = true;
    anchor.after(panel);

    panel.append(el('p', 'tpl-pf-intro', text(config, 'intro',
        'Preview the layout with real records. This only changes what you see here: it is never saved.')));

    const list = el('div', 'tpl-pf-targets');
    const empty = el('p', 'tpl-pf-empty');
    panel.append(list, empty);

    // One controller per bound source, keyed by kind + id (a node and a collection could in
    // principle share a key without clashing). Reconciled in place, never rebuilt wholesale.
    const controllers = new Map();
    const keyOf = (target) => `${target.kind}:${target.id}`;

    let tabOpened = false;

    const probeController = async (entry) => {
        if (entry.probed) {
            return;
        }

        entry.probed = true;
        await entry.controller.probe();
    };

    // Nothing bound -> the bind prompt. Something bound but, once probed, none previewable
    // -> the existing "none" copy. Otherwise the message is hidden.
    const renderEmpty = () => {
        const entries = [...controllers.values()];

        if (entries.length === 0) {
            empty.textContent = text(config, 'unbound', 'Bind a field or a table to preview it with real data');
            empty.hidden = false;

            return;
        }

        if (tabOpened && !entries.some((entry) => entry.controller.available())) {
            empty.textContent = text(config, 'none', 'There is nothing to preview for this document type.');
            empty.hidden = false;

            return;
        }

        empty.hidden = true;
    };

    /*
     * Reconcile the cards with the sources now bound on the sheet, by id:
     * - a target still present keeps its controller untouched — its in-flight request,
     *   chosen record and open filters all survive;
     * - a newly bound target gets a fresh controller (probed at once if the tab is open);
     * - a target no longer bound has its controller disposed and its card removed, its
     *   ephemeral canvas state cleared, and the canvas redrawn back to the example.
     * Controllers are never rebuilt wholesale, which would discard the user's selection.
     */
    const rebuild = () => {
        const targets = collectTargets(config, editor);
        const wanted = new Map(targets.map((target) => [keyOf(target), target]));
        let removed = false;
        let added = false;

        for (const [key, entry] of controllers) {
            if (!wanted.has(key)) {
                entry.controller.dispose();
                entry.controller.root.remove();

                if (entry.target.kind === 'collection') {
                    state.setRows(entry.target.id, []);
                } else {
                    state.setNode(entry.target.id, null);
                }

                controllers.delete(key);
                removed = true;
            }
        }

        targets.forEach((target) => {
            const key = keyOf(target);

            if (controllers.has(key)) {
                return;
            }

            const controller = createTarget({ target, config, state, url, headers, onChange: () => refreshPreview(editor) });
            const entry = { target, controller, probed: false };
            controllers.set(key, entry);
            added = true;

            if (tabOpened) {
                probeController(entry).then(renderEmpty);
            }
        });

        // Only reorder the DOM when a card appeared — re-appending otherwise would steal
        // focus from a filter input being typed in (a table redraw during a live filter
        // fires component:add, which debounces into this). Appending in target order keeps
        // the cards in catalogue declaration order even when one is inserted in the middle.
        if (added) {
            targets.forEach((target) => {
                const entry = controllers.get(keyOf(target));

                if (entry) {
                    list.append(entry.controller.root);
                }
            });
        }

        // Only redraw when a source actually left — an unconditional refresh here would
        // loop against the very table redraw that triggered this rebuild.
        if (removed) {
            refreshPreview(editor);
        }

        renderEmpty();
    };

    // Opening the tab first reconciles (a source bound while the Field tab was focused is
    // already a card), then — the first time only — probes every card once (page 1, no
    // filter): that is what reveals which can be previewed and what each filters by.
    tab.addEventListener('click', async () => {
        rebuild();

        if (tabOpened) {
            return;
        }

        tabOpened = true;

        await Promise.all([...controllers.values()].map(probeController));

        renderEmpty();
    });

    // Live edits while the tab is open: a bind/unbind/rebind changes the cards. Debounced,
    // because a single drag or paste fires a burst of these events.
    let rebuildTimer = null;
    const scheduleRebuild = () => {
        clearTimeout(rebuildTimer);
        rebuildTimer = setTimeout(rebuild, 175);
    };

    editor.on('component:add', scheduleRebuild);
    editor.on('component:remove', scheduleRebuild);
    editor.on('component:update:attributes', scheduleRebuild);

    // From whatever is on the sheet now (nothing at mount, which precedes `setComponents`;
    // the events above then settle this onto the real targets once the layout loads).
    rebuild();

    return { state };
}

function createTarget({ target, config, state, url, headers, onChange }) {
    const isNode = target.kind === 'node';

    const model = {
        filters: [],          // [{ field, op, value }] — op is one of the field's `ops`
        filterable: [],       // [{ key, label, ops: [{ value, label }] }]
        items: [],
        total: 0,
        page: 0,
        status: 'idle',       // idle | loading | ready | unavailable | error
        chosen: null,         // node: the picked candidate
        applied: false,       // collection: its rows are on the canvas
        seq: 0,
    };

    // Set when this target's card is reconciled away (its source is no longer bound). A
    // pending filter timer or an in-flight request must not resurrect the ephemeral state
    // that the reconcile just cleared, so every entry point bails once disposed.
    let disposed = false;

    const root = el('section', 'tpl-pf-target');
    const headBar = el('div', 'tpl-pf-headbar');
    const head = el('button', 'tpl-pf-head');
    head.type = 'button';
    const title = el('span', 'tpl-pf-title', target.label);
    const badge = el('span', 'tpl-pf-badge');
    head.append(title, badge);

    headBar.append(head);

    const body = el('div', 'tpl-pf-body');
    body.hidden = true;
    const filtersEl = el('div', 'tpl-pf-filters');
    const addButton = el('button', 'tpl-btn tpl-btn--secondary tpl-btn--sm tpl-pf-add');
    addButton.type = 'button';
    addButton.append(el('span', 'tpl-pf-add-icon', '+'), el('span', '', text(config, 'add_filter', 'Add filter')));
    const resultsEl = el('div', 'tpl-pf-results');
    body.append(filtersEl, addButton, resultsEl);
    root.append(headBar, body);

    root.hidden = true;

    head.addEventListener('click', () => {
        body.hidden = !body.hidden;
        head.setAttribute('aria-expanded', String(!body.hidden));
        root.classList.toggle('is-open', !body.hidden);
    });

    // The operators a field offers; the first is its default. A payload that still carries
    // the single `op` is read as a one-operator list.
    const opsOf = (field) => {
        const item = model.filterable.find((entry) => entry.key === field);

        if (Array.isArray(item?.ops) && item.ops.length > 0) {
            return item.ops;
        }

        return item?.op ? [{ value: item.op, label: item.op }] : [];
    };

    const operatorOf = (filter) => filter.op || opsOf(filter.field)[0]?.value || 'eq';

    const activeFilters = () => model.filters.filter((filter) => filter.field && filter.value.trim() !== '');

    function renderBadge() {
        let label = text(config, 'example', 'Example');

        if (isNode && model.chosen) {
            label = model.chosen.label;
        } else if (!isNode && model.applied) {
            label = fill(text(config, 'lines_badge', ':count lines'), { count: Math.min(model.items.length, PREVIEW_ROW_LIMIT) });
        }

        badge.textContent = label;
        badge.classList.toggle('is-real', (isNode && model.chosen !== null) || (!isNode && model.applied));
    }

    function renderFilters() {
        filtersEl.replaceChildren();

        model.filters.forEach((filter, index) => {
            const row = el('div', 'tpl-pf-filter');

            const select = el('select', 'tpl-pf-input');
            const placeholder = el('option', '', text(config, 'field_placeholder', 'Field'));
            placeholder.value = '';
            select.append(placeholder);

            model.filterable.forEach((item) => {
                const option = el('option', '', item.label);
                option.value = item.key;
                option.selected = item.key === filter.field;
                select.append(option);
            });

            const operator = el('select', 'tpl-pf-input tpl-pf-opsel');
            operator.setAttribute('aria-label', text(config, 'operator', 'Operator'));

            // Options follow the chosen field; a field with a single operator (text:
            // contains) still shows it, just not as a choice.
            const fillOperators = () => {
                const ops = opsOf(filter.field);

                operator.replaceChildren(...ops.map((op) => {
                    const option = el('option', '', op.label);
                    option.value = op.value;
                    option.selected = op.value === filter.op;

                    return option;
                }));
                operator.disabled = ops.length <= 1;
            };

            fillOperators();

            const input = el('input', 'tpl-pf-input');
            input.type = 'text';
            input.placeholder = text(config, 'value_placeholder', 'Value');
            input.value = filter.value;

            const remove = el('button', 'tpl-pf-remove', '✕');
            remove.type = 'button';
            remove.title = text(config, 'remove_filter', 'Remove filter');

            let timer = null;
            const schedule = () => {
                clearTimeout(timer);
                timer = setTimeout(() => load(false), FILTER_DEBOUNCE_MS);
            };

            select.addEventListener('change', () => {
                filter.field = select.value;
                // A new field starts on its own default operator.
                filter.op = opsOf(filter.field)[0]?.value ?? '';
                fillOperators();

                if (filter.value.trim() !== '') {
                    schedule();
                }
            });

            operator.addEventListener('change', () => {
                filter.op = operator.value;

                if (filter.value.trim() !== '') {
                    schedule();
                }
            });

            input.addEventListener('input', () => {
                filter.value = input.value;
                schedule();
            });

            input.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    clearTimeout(timer);
                    load(false);
                }
            });

            remove.addEventListener('click', () => {
                clearTimeout(timer);
                model.filters.splice(index, 1);
                renderFilters();
                load(false);
            });

            row.append(select, operator, input, remove);
            filtersEl.append(row);
        });
    }

    function renderResults() {
        resultsEl.replaceChildren();

        if (model.status === 'loading' && model.items.length === 0) {
            resultsEl.append(el('p', 'tpl-pf-note', text(config, 'loading', 'Loading…')));

            return;
        }

        if (model.status === 'error') {
            resultsEl.append(el('p', 'tpl-pf-note is-error', text(config, 'error', 'The records could not be loaded.')));

            return;
        }

        if (isNode) {
            if (model.items.length === 0) {
                resultsEl.append(el('p', 'tpl-pf-note', text(config, 'no_results', 'No records match.')));

                return;
            }

            const ul = el('ul', 'tpl-pf-list');

            model.items.forEach((item) => {
                const li = el('li');
                const button = el('button', 'tpl-pf-item');
                button.append(el('span', 'tpl-pf-item-label', item.label || item.id));

                // Display only: the record's other presented values as a secondary line.
                const meta = Object.entries(item)
                    .filter(([key, value]) => key !== 'id' && key !== 'label' && String(value ?? '') !== '')
                    .map(([, value]) => String(value))
                    .slice(0, 2)
                    .join(' · ');

                if (meta !== '') {
                    button.append(el('span', 'tpl-pf-item-meta', meta));
                }

                button.type = 'button';
                button.setAttribute('aria-pressed', model.chosen?.id === item.id ? 'true' : 'false');
                button.classList.toggle('is-chosen', model.chosen?.id === item.id);
                // Re-clicking the chosen record clears it: the node falls back to the example.
                const isChosen = model.chosen?.id === item.id;

                if (isChosen) {
                    button.title = text(config, 'use_example', 'Click to go back to the example');
                }

                button.addEventListener('click', () => choose(isChosen ? null : item));
                li.append(button);
                ul.append(li);
            });

            resultsEl.append(ul);

            appendMore();

            return;
        }

        // Collection: only filtered rows are shown, and applied.
        if (activeFilters().length === 0) {
            resultsEl.append(el('p', 'tpl-pf-note', text(config, 'collection_hint', 'Add a filter to preview real lines.')));

            return;
        }

        if (model.items.length === 0) {
            resultsEl.append(el('p', 'tpl-pf-note', text(config, 'no_results', 'No records match.')));

            return;
        }

        const shown = model.items.slice(0, PREVIEW_ROW_LIMIT);

        resultsEl.append(el('p', 'tpl-pf-note', fill(text(config, 'rows_shown', 'Showing :count of :total'), { count: shown.length, total: model.total })));

        const ul = el('ul', 'tpl-pf-list');

        shown.forEach((row) => {
            ul.append(el('li', 'tpl-pf-row', Object.values(row).filter((value) => String(value) !== '').slice(0, 3).join(' · ')));
        });

        resultsEl.append(ul);
    }

    // Paging context: "Showing 15 of 42", and the load-more action while there is more.
    function appendMore() {
        if (model.total <= 0) {
            return;
        }

        const foot = el('div', 'tpl-pf-foot');

        foot.append(el('span', 'tpl-pf-count', fill(text(config, 'showing', 'Showing :shown of :total'), { shown: model.items.length, total: model.total })));

        if (model.items.length < model.total) {
            const more = el('button', 'tpl-btn tpl-btn--secondary tpl-btn--sm', text(config, 'show_more', 'Show more'));
            more.type = 'button';
            more.disabled = model.status === 'loading';
            more.addEventListener('click', () => load(true));
            foot.append(more);
        }

        resultsEl.append(foot);
    }

    // A singular record: its presented values (`nodeId.column`), without the picker's own
    // `id` and `label`, become the sample values of that node's blocks.
    function choose(item) {
        if (disposed) {
            return;
        }

        model.chosen = item;

        if (item) {
            const { id, label, ...values } = item;
            state.setNode(target.id, values, id);
        } else {
            state.setNode(target.id, null);
        }

        renderBadge();
        renderResults();
        onChange();
    }

    function applyCollection() {
        if (disposed) {
            return;
        }

        const rows = activeFilters().length > 0 && model.status === 'ready' ? model.items.slice(0, PREVIEW_ROW_LIMIT) : [];

        model.applied = rows.length > 0;
        state.setRows(target.id, rows);
        renderBadge();
        onChange();
    }

    async function load(append) {
        if (disposed) {
            return;
        }

        const seq = ++model.seq;
        const page = append ? model.page + 1 : 1;

        model.status = 'loading';
        renderResults();

        try {
            const request = new URL(url, window.location.href);
            request.searchParams.set(isNode ? 'nodeId' : 'bindingKey', target.id);
            request.searchParams.set('page', String(page));

            const filters = activeFilters().map((filter) => ({ field: filter.field, op: operatorOf(filter), value: filter.value.trim() }));

            if (filters.length > 0) {
                request.searchParams.set('filters', JSON.stringify(filters));
            }

            const response = await fetch(request, { headers });

            // A newer request was made while this one was in flight.
            if (seq !== model.seq) {
                return;
            }

            if (response.status === 204) {
                model.status = 'unavailable';
                model.items = [];
                root.hidden = true;
            } else if (!response.ok) {
                model.status = 'error';
                model.items = append ? model.items : [];
                root.hidden = false;
            } else {
                const payload = await response.json();

                if (seq !== model.seq) {
                    return;
                }

                model.status = 'ready';
                model.filterable = payload.filterable ?? model.filterable;
                model.total = payload.meta?.total ?? 0;
                model.page = payload.meta?.page ?? page;
                model.items = append ? model.items.concat(payload.data ?? []) : (payload.data ?? []);
                root.hidden = false;
            }
        } catch (error) {
            if (seq !== model.seq) {
                return;
            }

            model.status = 'error';
            model.items = append ? model.items : [];
            root.hidden = false;
        }

        // Collection rows follow the filter; a failure or an empty answer drops back to
        // the example, so the canvas is never left blank.
        if (!isNode) {
            applyCollection();
        }

        renderResults();
    }

    addButton.addEventListener('click', () => {
        model.filters.push({ field: '', op: '', value: '' });
        renderFilters();
        filtersEl.querySelector('.tpl-pf-filter:last-child select')?.focus();
    });

    renderBadge();
    renderResults();

    return {
        root,
        available: () => model.status !== 'unavailable',
        // Stop a pending filter timer and void any in-flight request (the seq bump makes
        // its resolve a no-op), so a removed source can never write back to the canvas.
        dispose() {
            disposed = true;
            model.seq++;
        },
        async probe() {
            await load(false);
            renderFilters();
        },
    };
}
