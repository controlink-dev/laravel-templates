import assert from 'node:assert/strict';
import { formulaReferences } from '../../resources/js/editor/formula-names.js';
import { PREVIEW_PICK_LIMIT, addPick, batchCountLabel, collectTargets, initPreviewState, pdfSelectionOf, plainProjectData, refreshPreview, removePick, rowSummary, selectionOf, truncationNotice } from '../../resources/js/editor/preview-filter.js';

/*
 * While GrapesJS loads another format's saved project data it drops the old page before
 * the new one exists, and `component:remove` fires in between with no wrapper at all.
 * The dirty check reads the project data on that event; walking an absent wrapper threw
 * "Cannot read properties of undefined (reading 'previewContent')" and aborted the switch
 * from a talão back to A4/A5.
 */
{
    const editor = { getWrapper: () => undefined, getProjectData: () => ({ pages: [] }) };

    assert.deepEqual(plainProjectData(editor), { pages: [] });
    assert.doesNotThrow(() => refreshPreview(editor));
}

/*
 * CGE-37: the dotted keys a formula names, outside text literals — what the sample panel
 * reads to offer the records a formula uses.
 */
{
    assert.deepEqual(formulaReferences('UPPER(customer.name) & " customer.city"'), ['customer.name']);
    assert.deepEqual(formulaReferences('SUM(lines.amount) * 0.23'), ['lines.amount']);
    assert.deepEqual(formulaReferences('customer.name & customer.name & document.number'), ['customer.name', 'document.number']);
    assert.deepEqual(formulaReferences('[Cliente · Nome] & customer. & IF(x, 1, 2)'), []);
    assert.deepEqual(formulaReferences(''), []);
    assert.deepEqual(formulaReferences(undefined), []);
}

/*
 * The cards follow what the formulas name too: a data field in Formula (bound to
 * `formula.<id>`, its source in `data-tpl-formula`) and a line table's formula column
 * (its `formula` in `data-tpl-columns`). A key under a collection binding marks that
 * collection; any other its singular node. Catalogue order, whatever the walk order.
 */
{
    const component = (attributes, children = []) => ({ getAttributes: () => attributes, components: () => children });
    const config = {
        nodes: [
            { id: 'document', label: 'Documento' },
            { id: 'customer', label: 'Cliente' },
            { id: 'supplier', label: 'Fornecedor' },
            { id: 'lines', label: 'Linhas', collection: true },
        ],
        bindings: [{ key: 'lines', label: 'Linhas', type: 'collection' }, { key: 'taxes', label: 'Impostos', type: 'collection' }],
    };
    const ids = (targets) => targets.map((target) => target.kind + ':' + target.id);
    const editorOf = (...children) => ({ getWrapper: () => component({}, children) });

    // A formula field naming a node and a collection total; literals ignored.
    const field = component({
        'data-tpl-binding': 'formula.ab12cd34',
        'data-tpl-formula': 'UPPER(customer.name) & " supplier.name " & SUM(taxes.amount)',
    });

    assert.deepEqual(ids(collectTargets(config, editorOf(field))), ['node:customer', 'collection:taxes']);

    // A line table: its own collection, plus what its formula column names.
    const table = component({
        'data-tpl-collection': 'lines',
        'data-tpl-columns': JSON.stringify([
            { key: 'quantity', label: 'Quantidade' },
            { key: 'formula_aa', formulaKey: 'formula_aa', label: 'Total', formula: 'lines.quantity * lines.unit_price & supplier.name' },
        ]),
    });

    assert.deepEqual(ids(collectTargets(config, editorOf(table))), ['node:supplier', 'collection:lines']);

    // Both, walked in either order: catalogue order holds; a plain binding still counts.
    const plain = component({ 'data-tpl-binding': 'document.number' });

    assert.deepEqual(ids(collectTargets(config, editorOf(table, field, plain))),
        ['node:document', 'node:customer', 'node:supplier', 'collection:lines', 'collection:taxes']);
    assert.deepEqual(ids(collectTargets(config, editorOf(plain, field, table))),
        ['node:document', 'node:customer', 'node:supplier', 'collection:lines', 'collection:taxes']);

    // A field just switched to Formula: its source is the field it was bound to.
    const switched = component({ 'data-tpl-binding': 'formula.ee55ff66', 'data-tpl-formula': 'customer.name' });

    assert.deepEqual(ids(collectTargets(config, editorOf(switched))), ['node:customer']);

    // An empty formula names nothing; columns that are not JSON do not throw.
    const broken = component({ 'data-tpl-collection': 'lines', 'data-tpl-columns': '{oops' });
    const blank = component({ 'data-tpl-binding': 'formula.00000000', 'data-tpl-formula': '' });

    assert.deepEqual(ids(collectTargets(config, editorOf(broken, blank))), ['collection:lines']);
}

console.log('preview-filter: ok');

/*
 * CGE-84: what the PDF preview sends. A node by its chosen record's id; a collection by
 * its QUERY (the Amostra's filters, none for "all records"), so the PDF prints every row
 * the query gives rather than the canvas's first few. The formula preview's
 * `selectionOf` keeps sending the canvas rows' ids.
 */
{
    const editor = {};
    const state = initPreviewState(editor, {});

    assert.equal(pdfSelectionOf(state), null);

    state.setNode('subject', { 'subject.name': 'Parafuso' }, 3);
    state.setRows('items', [{ id: 7, name: 'Porca' }]);
    state.setQuery('items', [{ field: 'name', op: 'contains', value: 'Par' }]);

    assert.deepEqual(pdfSelectionOf(state), {
        nodes: { subject: '3' },
        queries: { items: { filters: [{ field: 'name', op: 'contains', value: 'Par' }] } },
    });
    assert.deepEqual(selectionOf(state), { nodes: { subject: '3' }, collections: { items: ['7'] } });

    // "All records": a query with no filters.
    state.setQuery('items', []);
    assert.deepEqual(pdfSelectionOf(state).queries, { items: { filters: [] } });

    // Back to the example: the collection is not queried; nothing chosen is null again.
    state.setQuery('items', null);
    state.setNode('subject', null);
    assert.equal(pdfSelectionOf(state), null);
}

/*
 * CGE-84: the cut warning, from the preview response's headers. None when nothing was
 * left out; the shown/total/pages filled in when it was.
 */
{
    const headersOf = (values) => ({ get: (name) => values[name] ?? null });
    const strings = { previewTruncated: 'Mostra :shown de :total (máx. :pages páginas)' };

    assert.equal(truncationNotice(headersOf({}), strings), null);
    assert.equal(
        truncationNotice(headersOf({ 'X-Preview-Truncated': '12/20', 'X-Preview-Max-Pages': '50' }), strings),
        'Mostra 12 de 20 (máx. 50 páginas)',
    );
    assert.equal(truncationNotice(headersOf({ 'X-Preview-Truncated': 'nonsense' }), strings), null);
}

/*
 * A collection row in the Amostra list: its first three values, never the record id the
 * PDF/formula selection carries alongside them.
 */
{
    assert.equal(rowSummary({ id: '01abc', code: 'T1', name: 'Porca', cost: '', price: '2,00', unit: 'UN' }), 'T1 · Porca · 2,00');
}

/*
 * CGE-98: a label type previews SEVERAL articles. The batch node's ids are a list in pick
 * order, repeats allowed: the PDF prints one label per pick, while the formula preview
 * (one record) and the canvas use the first pick only.
 */
{
    const state = initPreviewState({}, {});

    state.ids.article = ['1', '2', '1'];

    assert.deepEqual(pdfSelectionOf(state), { nodes: { article: ['1', '2', '1'] }, queries: {} });
    assert.deepEqual(selectionOf(state), { nodes: { article: '1' }, collections: {} });

    // A copy, never the live list: a later pick must not change a request already sent.
    pdfSelectionOf(state).nodes.article.push('9');
    assert.deepEqual(state.ids.article, ['1', '2', '1']);
}

{
    const state = initPreviewState({}, {});
    const pick = (id, name) => ({ id, label: name, values: { 'article.name': name } });

    state.setPicks('article', [pick(5, 'Porca'), pick(7, 'Parafuso'), pick(5, 'Porca')]);

    assert.deepEqual(state.ids.article, ['5', '7', '5']);
    // The canvas shows the first pick's values.
    assert.deepEqual(state.values, { 'article.name': 'Porca' });

    // Removing the first pick: the canvas follows the new first one.
    state.setPicks('article', [pick(7, 'Parafuso'), pick(5, 'Porca')]);
    assert.deepEqual(state.values, { 'article.name': 'Parafuso' });

    // No picks left: back to the example, and nothing is sent (the host uses the fixture).
    state.setPicks('article', []);
    assert.equal(state.ids.article, undefined);
    assert.deepEqual(state.values, {});
    assert.equal(pdfSelectionOf(state), null);
    assert.equal(selectionOf(state), null);

    // The card being reconciled away clears a batch the same way.
    state.setPicks('article', [pick(1, 'A')]);
    state.setNode('article', null);
    assert.equal(pdfSelectionOf(state), null);
}

/*
 * The batch card's list of picks: appended in order, repeats allowed, capped at 200;
 * removed by position (a repeated article removes only that one).
 */
{
    const a = { id: '1', label: 'A' };
    const b = { id: '2', label: 'B' };

    assert.equal(PREVIEW_PICK_LIMIT, 200);

    let picks = addPick([], a);
    picks = addPick(picks, b);
    picks = addPick(picks, a);
    assert.deepEqual(picks.map((p) => p.id), ['1', '2', '1']);

    assert.deepEqual(removePick(picks, 2).map((p) => p.id), ['1', '2']);
    assert.deepEqual(removePick(picks, 0).map((p) => p.id), ['2', '1']);
    assert.deepEqual(picks.map((p) => p.id), ['1', '2', '1'], 'never mutated');

    const full = Array.from({ length: PREVIEW_PICK_LIMIT }, () => a);
    assert.equal(addPick(full, b).length, PREVIEW_PICK_LIMIT);
}

/* CGE-98: the Amostra badge says «1 artigo», not «1 artigos». */
{
    const config = { strings: { 'preview.batch_count': ':count artigos', 'preview.batch_count_one': '1 artigo' } };

    assert.equal(batchCountLabel(config, 1), '1 artigo');
    assert.equal(batchCountLabel(config, 3), '3 artigos');
    assert.equal(batchCountLabel({}, 1), '1 article');
    assert.equal(batchCountLabel({}, 2), '2 articles');
}
