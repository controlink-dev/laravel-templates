import assert from 'node:assert/strict';
import { missingMandatoryFields } from '../../resources/js/editor/mandatory-fields.js';

/*
 * The live "mandatory field missing" check (CGE-69, L-03): which mandatory fields the law
 * does not require are neither placed by a data field nor named in a data field's formula.
 */

const bindings = [
    { key: 'document.supply_date', label: 'Supply date', type: 'date', mandatory: true },
    { key: 'totals.net_total', label: 'Net total', type: 'money', mandatory: true, legal: false },
    { key: 'issuer.name', label: 'Issuer', type: 'text', mandatory: true, legal: true },
    { key: 'document.notes', label: 'Notes', type: 'text' },
    { key: 'lines', label: 'Lines', type: 'collection', mandatory: true },
    { key: 'document.qr', label: 'QR', type: 'qr', mandatory: true },
];
const keys = (missing) => missing.map((binding) => binding.key);

// Nothing on the sheet: every mandatory, non-legal data-field binding is missing.
assert.deepEqual(keys(missingMandatoryFields(bindings, [])), ['document.supply_date', 'totals.net_total'],
    'legal fields are V-10, tables L-02, codes the server: none of them here');

// Placed by its own block.
assert.deepEqual(keys(missingMandatoryFields(bindings, [
    { 'data-tpl-binding': 'document.supply_date' },
])), ['totals.net_total']);

// Named in a data field's formula: placed (Review Focus 4).
assert.deepEqual(keys(missingMandatoryFields(bindings, [
    { 'data-tpl-binding': 'formula.abc123', 'data-tpl-formula': '"Fornecido em " & TEXT(document.supply_date, "date", "yyyy/MM/dd")' },
    { 'data-tpl-binding': 'totals.net_total' },
])), []);

// Inside a text literal it is only words, not a reference.
assert.deepEqual(keys(missingMandatoryFields(bindings, [
    { 'data-tpl-binding': 'formula.abc123', 'data-tpl-formula': '"document.supply_date"' },
    { 'data-tpl-binding': 'totals.net_total' },
])), ['document.supply_date']);

// A legal field named in a formula is not this check's business either way.
assert.deepEqual(keys(missingMandatoryFields(bindings, [
    { 'data-tpl-binding': 'formula.abc123', 'data-tpl-formula': 'issuer.name' },
])), ['document.supply_date', 'totals.net_total']);

// No bindings (a pane still loading its catalogue): nothing to say.
assert.deepEqual(missingMandatoryFields(undefined, []), []);

console.log('mandatory-fields: ok');
