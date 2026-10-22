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

function mount(root, { message, buttons }) {
    const host = root ?? document.body;

    const overlay = document.createElement('div');
    overlay.className = 'tpl-dialog';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');

    const box = document.createElement('div');
    box.className = 'tpl-dialog__box';

    const text = document.createElement('p');
    text.className = 'tpl-dialog__message';
    text.textContent = message;

    const actions = document.createElement('div');
    actions.className = 'tpl-dialog__actions';

    box.append(text, actions);
    overlay.append(box);
    host.append(overlay);

    return { overlay, box, actions };
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

    return new Promise((resolve) => {
        const { overlay, box, actions } = mount(root, { message, buttons: 2 });
        const previousFocus = document.activeElement;

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

        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'tpl-dialog__btn tpl-dialog__btn--ghost';
        cancel.textContent = cancelLabel;
        cancel.addEventListener('click', () => close(false));

        const confirm = document.createElement('button');
        confirm.type = 'button';
        confirm.className = 'tpl-dialog__btn tpl-dialog__btn--primary';
        confirm.textContent = confirmLabel;
        confirm.addEventListener('click', () => close(true));

        actions.append(cancel, confirm);

        overlay.addEventListener('mousedown', (event) => {
            if (event.target === overlay) {
                close(false);
            }
        });

        const onKey = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                close(false);
            } else if (event.key === 'Enter') {
                event.preventDefault();
                close(true);
            }
        };
        document.addEventListener('keydown', onKey, true);

        confirm.focus();
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

    return new Promise((resolve) => {
        const { overlay, actions } = mount(root, { message, buttons: 1 });
        const previousFocus = document.activeElement;

        let done = false;
        const close = () => {
            if (done) {
                return;
            }
            done = true;
            document.removeEventListener('keydown', onKey, true);
            overlay.remove();
            if (previousFocus && typeof previousFocus.focus === 'function') {
                previousFocus.focus();
            }
            resolve();
        };

        const ok = document.createElement('button');
        ok.type = 'button';
        ok.className = 'tpl-dialog__btn tpl-dialog__btn--primary';
        ok.textContent = closeLabel;
        ok.addEventListener('click', close);

        actions.append(ok);

        overlay.addEventListener('mousedown', (event) => {
            if (event.target === overlay) {
                close();
            }
        });

        const onKey = (event) => {
            if (event.key === 'Escape' || event.key === 'Enter') {
                event.preventDefault();
                close();
            }
        };
        document.addEventListener('keydown', onKey, true);

        ok.focus();
    });
}
