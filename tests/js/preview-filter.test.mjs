import assert from 'node:assert/strict';
import { formulaReferences } from '../../resources/js/editor/formula-names.js';
import { collectTargets, initPreviewState, pdfSelectionOf, plainProjectData, refreshPreview, selectionOf, truncationNotice } from '../../resources/js/editor/preview-filter.js';

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
