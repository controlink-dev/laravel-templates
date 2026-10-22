/**
 * Which style-panel sections are open, remembered in this browser (CGE-53).
 *
 * A convenience, so every read and write is guarded: a private window, blocked site data
 * or a full storage leave the panel with its defaults and never break the editor.
 */
export const SECTOR_STORAGE_KEY = 'tpl-editor:sectors';

/** @returns {Record<string, boolean>} */
export function readSectors(storage) {
    try {
        const parsed = JSON.parse(storage?.getItem(SECTOR_STORAGE_KEY) ?? '{}');

        return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
    } catch {
        return {};
    }
}

export function writeSector(storage, id, open) {
    try {
        storage?.setItem(SECTOR_STORAGE_KEY, JSON.stringify({ ...readSectors(storage), [id]: Boolean(open) }));
    } catch {
        // Nothing to do: the panel simply forgets.
    }
}
