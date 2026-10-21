/*
 * A save that may come back with warnings instead of being written (CGE-27).
 *
 * The host answers a layout with covered non-legal fields with its `warnings` and writes
 * nothing; the user is asked whether to save anyway, and a yes sends the same save again
 * acknowledging them. Blocking `problems` are never put to the user as a choice: they are
 * thrown, as any refused save. A declined confirmation throws an error marked `cancelled`,
 * which the callers swallow — the user chose not to save, there is nothing to report.
 *
 * Kept apart from editor.js so it can be tested without GrapesJS (tests/js/save.test.mjs).
 */

/**
 * @param {(acknowledge: boolean) => Promise<{ ok: boolean, payload: object }>} send
 * @param {(warnings: Array<{code: string, component_id: string, message: string}>) => Promise<boolean>} confirm
 * @param {string} [fallbackMessage] the error message when the host gives none
 * @returns {Promise<object>} the payload of the save that was written
 */
export async function saveConfirmingWarnings(send, confirm, fallbackMessage = '') {
    let response = await send(false);

    const warnings = response.payload?.warnings ?? [];
    const problems = response.payload?.problems ?? [];

    if (!response.ok && warnings.length > 0 && problems.length === 0) {
        if (!(await confirm(warnings))) {
            const cancelled = new Error('cancelled');
            cancelled.cancelled = true;

            throw cancelled;
        }

        response = await send(true);
    }

    if (!response.ok) {
        const error = new Error(response.payload?.message || fallbackMessage);
        error.problems = response.payload?.problems ?? [];

        throw error;
    }

    return response.payload;
}
