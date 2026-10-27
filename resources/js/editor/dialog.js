/*
 * A small modal rendered inside the editor itself.
 *
 * The editor loads none of the host application's JavaScript (RF-E03), so it cannot use
 * the host's modal. These replace the browser's native `confirm()`/`alert()`, which are
 * unstyled, break the look of the editor, and — in an automated/embedded context — block
 * every later event. Both return a Promise, so the caller can `await` the answer.
 *
 * Styled by `tpl-dialog` rules in editor.css. No dependencies, no host globals.
 *
 * A host that would rather show these in its own style can (CGE-57): each dialog is
 * first announced as a cancelable `templates:confirm` / `templates:alert` event that
 * bubbles from the editor root. A host that will answer claims it with preventDefault()
 * and replies through `detail.respond`. Unclaimed, the editor's own dialog opens as
 * before. No flag is involved, unlike `templates:message`: a lost message is harmless,
 * but a lost confirmation would leave the editor waiting forever.
 */

/**
 * Hand a dialog to the host, if one claims it.
 *
 * @param {EventTarget} target  where the event is fired (it bubbles from there)
 * @param {string} name  `templates:confirm` or `templates:alert`
 * @param {object} detail  what the host needs to show; `respond` is added
 * @returns {Promise<any>|null}  the host's first answer, or null when no host claimed it
 */
export function askHost(target, name, detail) {
    let respond;
    const answer = new Promise((resolve) => {
        let answered = false;
        respond = (value) => {
            if (!answered) {
                answered = true;
                resolve(value);
            }
        };
    });

    const claimed = !target.dispatchEvent(new CustomEvent(name, {
        detail: { ...detail, respond },
        bubbles: true,
        cancelable: true,
    }));

    return claimed ? answer : null;
}

/**
 * The one modal every dialog is built on: overlay, aria, focus, Escape, backdrop.
 *
 * @param {object} options
 * @param {Element} [options.root]  where it mounts (default: document.body)
 * @param {Node[]} options.content  what goes in the box, above the buttons
 * @param {Array<{label: string, className?: string, value?: any, onClick?: function}>} options.actions
 *        a button closes the dialog with its `value`, unless it has an `onClick` (then it
 *        only runs that, and the dialog stays open)
 * @param {any} [options.escapeValue]  what Escape and a click on the backdrop resolve with
 * @param {{value: any}} [options.enter]  when set, Enter closes with `enter.value`
 * @param {string} [options.boxClass]  extra class on the box
 * @param {number} [options.focus]  index of the action to focus (default: the last)
 * @returns {Promise<any>}
 */
export function openDialog({ root, content, actions, escapeValue, enter, boxClass = '', focus }) {
    return new Promise((resolve) => {
        const host = root ?? document.body;
        const previousFocus = document.activeElement;

        const overlay = document.createElement('div');
        overlay.className = 'tpl-dialog';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');

        const box = document.createElement('div');
        box.className = ('tpl-dialog__box ' + boxClass).trim();

        const bar = document.createElement('div');
        bar.className = 'tpl-dialog__actions';

        let done = false;
        const close = (value) => {
            if (done) {
                return;
            }
            done = true;
            document.removeEventListener('keydown', onKey, true);
            overlay.remove();
            if (previousFocus && typeof previousFocus.focus === 'function') {
                previousFocus.focus();
            }
            resolve(value);
        };

        const buttons = actions.map((action) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = action.className ?? 'tpl-dialog__btn tpl-dialog__btn--primary';
            button.textContent = action.label;
            button.addEventListener('click', () => (action.onClick ? action.onClick() : close(action.value)));

            return button;
        });
        bar.append(...buttons);

        overlay.addEventListener('mousedown', (event) => {
            if (event.target === overlay) {
                close(escapeValue);
            }
        });

        const onKey = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                close(escapeValue);
            } else if (event.key === 'Enter' && enter) {
                event.preventDefault();
                close(enter.value);
            }
        };
        document.addEventListener('keydown', onKey, true);

        box.append(...content, bar);
        overlay.append(box);
        host.append(overlay);

        buttons[focus ?? buttons.length - 1]?.focus();
    });
}

/** A paragraph of dialog text. */
export function dialogMessage(message) {
    const text = document.createElement('p');
    text.className = 'tpl-dialog__message';
    text.textContent = message;

    return text;
}

/**
 * Ask the user to confirm. Resolves true on confirm, false on cancel / backdrop / Escape.
 *
 * @param {string} message
 * @param {{ root?: Element, confirmLabel?: string, cancelLabel?: string }} options
 * @returns {Promise<boolean>}
 */
export function confirmDialog(message, { root, confirmLabel = 'OK', cancelLabel = 'Cancel' } = {}) {
    const hosted = askHost(root ?? document, 'templates:confirm', { message, confirmLabel, cancelLabel });

    if (hosted) {
        return hosted.then((value) => value === true);
    }

    return openDialog({
        root,
        content: [dialogMessage(message)],
        actions: [
            { label: cancelLabel, className: 'tpl-dialog__btn tpl-dialog__btn--ghost', value: false },
            { label: confirmLabel, value: true },
        ],
        escapeValue: false,
        enter: { value: true },
    });
}

/**
 * Tell the user something. Resolves when dismissed.
 *
 * @param {string} message
 * @param {{ root?: Element, closeLabel?: string }} options
 * @returns {Promise<void>}
 */
export function alertDialog(message, { root, closeLabel = 'OK' } = {}) {
    const hosted = askHost(root ?? document, 'templates:alert', { message, closeLabel });

    if (hosted) {
        return hosted.then(() => undefined);
    }

    return openDialog({
        root,
        content: [dialogMessage(message)],
        actions: [{ label: closeLabel, value: undefined }],
        escapeValue: undefined,
        enter: { value: undefined },
    }).then(() => undefined);
}
