/**
 * The name a fixed text shows in Layers and in the selection header (CGE-53): its own
 * words on one line, cut to `max` characters, or `emptyLabel` when it says nothing.
 *
 * Computed, never stored: a `custom-name` would go into the project data, change the hash,
 * and mark every opened version as having unsaved changes.
 */
export function staticTextName(text, emptyLabel, max = 40) {
    const flat = String(text ?? '').replace(/\s+/g, ' ').trim();

    if (flat === '') {
        return emptyLabel;
    }

    return flat.length > max ? flat.slice(0, max) + '…' : flat;
}
