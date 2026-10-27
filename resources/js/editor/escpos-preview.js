import { openDialog } from './dialog.js';

/*
 * Thermal receipt preview (CGE-97).
 *
 * On a roll the host can also answer with the receipt's native ESC/POS bytes. The server
 * returns a self-contained preview of exactly those bytes (`preview_html`, built and
 * escaped by the package's ReceiptPreview) and the bytes themselves, which the user
 * downloads to send to a printer. Kept free of GrapesJS; only `showEscposDialog` touches
 * the DOM (tests/js/escpos-preview.test.mjs).
 */

/** @returns {boolean} whether the preview button applies: a roll, and a host endpoint. */
export function shouldShowEscposButton(config) {
    return config?.geometry?.medium === 'roll' && Boolean(config?.escposPreviewUrl);
}

/** @returns {Uint8Array} the bytes a base64 string stands for. */
export function bytesFromBase64(b64) {
    const binary = atob(b64 ?? '');
    const bytes = new Uint8Array(binary.length);

    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }

    return bytes;
}

/**
 * POST the Amostra selection; resolves with the payload, rejects with an Error carrying
 * the server's `problems` on a refusal (422), like the activation does.
 *
 * @returns {Promise<{preview_html: string, filename: string, bytes_base64: string}>}
 */
export async function requestEscposPreview(config, headers, selection) {
    const response = await fetch(config.escposPreviewUrl, {
        method: 'POST',
        headers: selection ? { ...headers, 'Content-Type': 'application/json' } : headers,
        ...(selection ? { body: JSON.stringify({ selection }) } : {}),
    });

    if (!response.ok) {
        const payload = await response.json().catch(() => ({}));
        const error = new Error(payload.message || config.strings.previewFailed);
        error.problems = payload.problems ?? [];

        throw error;
    }

    return response.json();
}

/**
 * Show the receipt preview with a Download button for the bytes. Resolves when closed.
 *
 * `preview_html` is server-generated and escaped there, so it is inserted as HTML.
 */
export function showEscposDialog(payload, { root, strings }) {
    const title = document.createElement('p');
    title.className = 'tpl-dialog__message tpl-dialog__title';
    title.textContent = strings['escpos.title'];

    const body = document.createElement('div');
    body.className = 'tpl-escpos-preview';
    body.innerHTML = payload.preview_html;

    const download = () => {
        const url = URL.createObjectURL(new Blob([bytesFromBase64(payload.bytes_base64)], {
            type: 'application/octet-stream',
        }));
        const link = document.createElement('a');
        link.href = url;
        link.download = payload.filename;
        document.body.append(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    };

    return openDialog({
        root,
        content: [title, body],
        boxClass: 'tpl-dialog__box--escpos',
        actions: [
            { label: strings['dialog.close'], className: 'tpl-dialog__btn tpl-dialog__btn--ghost' },
            { label: strings['escpos.download'], onClick: download },
        ],
    });
}
