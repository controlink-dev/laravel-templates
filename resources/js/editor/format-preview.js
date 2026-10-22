import { previewArgs } from './formats.js';

/**
 * The canvas's formatted sample values, asked of the host (CGE-49).
 *
 * The editor does not format anything itself: the host formats through the renderer's
 * own filters (`TemplateRenderer::formatValue`), so the canvas shows exactly what the PDF
 * prints — currency symbol, separators and all, which depend on the licence and which a
 * second copy of the rules in JS would drift from.
 *
 * `text()` answers synchronously: the cached server text, or the raw value while the
 * ask is in flight. Asks made in the same tick go out as one request; `onReady` is called
 * once per answered batch so the canvas redraws.
 */
export function createFormatPreview({ url, headers = {}, fetchImpl = (...args) => fetch(...args), onReady = () => {} }) {
    const cache = new Map();
    const pending = new Map();
    let scheduled = false;

    const keyOf = (value, spec) => JSON.stringify([value, spec.name, previewArgs(spec)]);

    async function flush() {
        scheduled = false;

        const batch = [...pending.entries()];
        pending.clear();

        if (!batch.length) {
            return;
        }

        try {
            const response = await fetchImpl(url, {
                method: 'POST',
                headers: { ...headers, 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ items: batch.map(([, item]) => item) }),
            });

            if (!response.ok) {
                throw new Error('format preview failed');
            }

            const { texts = [] } = await response.json();

            batch.forEach(([key, item], index) => cache.set(key, texts[index] ?? String(item.value ?? '')));
        } catch {
            // The raw value stays on screen; it is not worth an error in the canvas.
            batch.forEach(([key, item]) => cache.set(key, String(item.value ?? '')));
        }

        onReady();
    }

    return {
        text(value, spec) {
            const raw = String(value ?? '');

            if (!spec || !url || value === null || value === undefined || value === '') {
                return raw;
            }

            const key = keyOf(value, spec);

            if (cache.has(key)) {
                return cache.get(key);
            }

            if (!pending.has(key)) {
                pending.set(key, { value, format: spec.name, arguments: previewArgs(spec) });
            }

            if (!scheduled) {
                scheduled = true;
                queueMicrotask(flush);
            }

            return raw;
        },
    };
}
