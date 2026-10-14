/*
 * The validation codes `checkLegalFieldsLocally` (panels.js) can decide in the browser.
 *
 * For these, the LIVE local check is authoritative: it re-runs on every edit and reflects
 * the current layout. A server copy of the same code — left over from the last refused
 * save — must therefore NOT be kept beside the local one. Keeping it listed the problem
 * twice while the field was bad, and left a phantom entry once the user fixed the field
 * (the live check cleared its copy, the server's lingered until the next save).
 *
 * Server problems with any OTHER code (contrast against an effective background, occlusion,
 * structure — things the browser cannot decide) are kept: dropping them on the next
 * keystroke would hide the only reason the layout will not save.
 */
export const LOCAL_CHECK_CODES = new Set(['V-10', 'V-11', 'V-13', 'V-16']);

/**
 * Merge the server's last-save verdict with the live local checks for display.
 *
 * Server problems whose code the browser decides locally are dropped (the local list is
 * authoritative for those); everything else the server reported is kept and listed before
 * the live ones, so a server-only refusal still shows after an edit.
 *
 * @param {Array<{code: string}>} serverProblems problems from the last refused save
 * @param {Array<{code: string}>} local          problems from the live local check
 * @returns {Array<{code: string}>}
 */
export function mergeProblems(serverProblems, local) {
    const serverOnly = serverProblems.filter((problem) => !LOCAL_CHECK_CODES.has(String(problem.code)));

    return [...serverOnly, ...local];
}
