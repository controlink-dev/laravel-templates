/**
 * "Download ZPL" (CGE-98): the labels of the Amostra's articles as the file a Zebra
 * printer takes. Downloaded, never previewed — no thermal preview in the editor, the same
 * rule the receipt follows (CGE-97).
 */

/**
 * The file name the host gave in `Content-Disposition`, the RFC 5987 `filename*` first.
 * Only the last path segment: a name is never a place to write to.
 */
export function filenameFromDisposition(header, fallback) {
    const value = String(header ?? '');
    let name = null;

    const extended = /filename\*\s*=\s*([^']*)'[^']*'([^;]+)/i.exec(value);

    if (extended) {
        try {
            name = decodeURIComponent(extended[2].trim().replace(/^"|"$/g, ''));
        } catch {
            name = null;
        }
    }

    if (!name) {
        const plain = /filename\s*=\s*("([^"]*)"|[^;]+)/i.exec(value);

        name = plain ? (plain[2] ?? plain[1]).trim() : null;
    }

    const base = name ? name.split(/[\/]/).pop() : '';

    return base || fallback;
}

/**
 * What the status line says when the ZPL is refused. A batch whose barcode cannot be
 * printed (422) is refused whole, and names the articles at fault by code and name, so
 * the user knows which to fix; any other failure is the host's message, or the fallback.
 */
export function zplErrorMessage(payload, fallback) {
    const message = payload?.message || fallback;
    const articles = Array.isArray(payload?.articles) ? payload.articles : [];

    if (articles.length === 0) {
        return message;
    }

    const names = articles
        .map((article) => (article?.name ? `${article.code} (${article.name})` : String(article?.code ?? '')))
        .filter((entry) => entry !== '')
        .join('; ');

    return names === '' ? message : `${message} ${names}`;
}

/**
 * What the status line says when the host printed the batch short (`X-Labels-Missing: n`):
 * some chosen articles no longer exist. Null when none is missing.
 */
export function missingLabelsNotice(headers, template) {
    const missing = Number.parseInt(headers?.get?.('X-Labels-Missing') ?? '', 10);

    if (!Number.isFinite(missing) || missing <= 0) {
        return null;
    }

    return String(template || ':n chosen article(s) no longer exist and were left out.').replace(':n', String(missing));
}

/**
 * POST the Amostra's selection (none: the host uses the fixture) and save the answer as
 * a file. Throws with the message to show when the host refuses it. Answers the file's
 * name and, when some chosen articles were left out, the notice that says so.
 */
export async function downloadZpl({ url, selection, headers, fallbackName, failedMessage, missingMessage }) {
    const response = await fetch(url, {
        method: 'POST',
        headers: selection ? { ...headers, 'Content-Type': 'application/json' } : headers,
        ...(selection ? { body: JSON.stringify({ selection }) } : {}),
    });

    if (!response.ok) {
        const payload = await response.json().catch(() => ({}));

        throw new Error(zplErrorMessage(payload, failedMessage));
    }

    const name = filenameFromDisposition(response.headers.get('Content-Disposition'), fallbackName);
    const href = URL.createObjectURL(await response.blob());
    const link = document.createElement('a');

    link.href = href;
    link.download = name;
    link.hidden = true;
    document.body.append(link);
    link.click();
    link.remove();

    // After the click has handed the blob to the download, not before.
    setTimeout(() => URL.revokeObjectURL(href), 0);

    return { name, notice: missingLabelsNotice(response.headers, missingMessage) };
}
