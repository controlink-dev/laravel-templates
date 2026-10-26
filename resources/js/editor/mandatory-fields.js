/* ============================================================================
 * Mandatory fields missing from the sheet (CGE-69), for the live check L-03.
 *
 * The rule is the server's V-02 for the fields a data field places: a mandatory field
 * the law does not require is placed by a data field bound to it, or by a data field's
 * formula that names it. Legal fields are V-10's (they need a block of their own),
 * tables L-02's, and codes the server's — none of them is reported here.
 * ========================================================================= */

import { formulaReferences } from './formula-names.js';

/** The binding types a data field places, as `LayoutValidator::PLACED_BY`. */
const PLACED_BY_DATA_FIELD = new Set(['text', 'number', 'date', 'money', 'image']);

/**
 * @param {Array<{key: string, type?: string, mandatory?: boolean, legal?: boolean}>|undefined} bindings
 * @param {Array<Record<string, string>>} fields  every data field's attributes
 * @returns the mandatory, non-legal bindings neither placed nor named in a formula
 */
export function missingMandatoryFields(bindings, fields) {
    const placed = new Set();

    fields.forEach((attributes) => {
        placed.add(attributes['data-tpl-binding']);
        formulaReferences(attributes['data-tpl-formula'] ?? '').forEach((key) => placed.add(key));
    });

    return (bindings ?? []).filter((binding) => binding.mandatory
        && !binding.legal
        && PLACED_BY_DATA_FIELD.has(binding.type ?? 'text')
        && !placed.has(binding.key));
}
