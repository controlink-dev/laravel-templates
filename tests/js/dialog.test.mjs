import assert from 'node:assert/strict';
import { askHost } from '../../resources/js/editor/dialog.js';

/*
 * The editor asks the host to show its confirm/alert dialogs (CGE-57): it fires a
 * cancelable event, and a host that will answer claims it with preventDefault() and
 * replies through detail.respond. Unclaimed, askHost returns null and the editor shows
 * its own dialog — so a confirmation is never left waiting for a host that is not there.
 */

const claiming = (onEvent) => {
    const target = new EventTarget();
    const seen = [];

    target.addEventListener('templates:confirm', (event) => {
        event.preventDefault();
        seen.push(event.detail);
        onEvent(event.detail);
    });

    return { target, seen };
};

// 1. The host claims and confirms: the answer is true, and it saw the message.
{
    const { target, seen } = claiming((detail) => detail.respond(true));
    assert.equal(await askHost(target, 'templates:confirm', { message: 'm' }), true);
    assert.equal(seen[0].message, 'm');
}

// 2. The host claims and declines.
{
    const { target } = claiming((detail) => detail.respond(false));
    assert.equal(await askHost(target, 'templates:confirm', { message: 'm' }), false);
}

// 3. Answered twice: the first answer wins.
{
    const { target } = claiming((detail) => {
        detail.respond(false);
        detail.respond(true);
    });
    assert.equal(await askHost(target, 'templates:confirm', { message: 'm' }), false);
}

// 4. Nobody listening: null, so the caller falls back to its own dialog.
assert.equal(askHost(new EventTarget(), 'templates:confirm', { message: 'm' }), null);

// 5. A listener that does not claim the event counts as no host.
{
    const target = new EventTarget();
    target.addEventListener('templates:confirm', () => {});
    assert.equal(askHost(target, 'templates:confirm', { message: 'm' }), null);
}

// 6. A later answer (the user takes their time) is waited for.
{
    const { target } = claiming((detail) => setTimeout(() => detail.respond(true), 10));
    assert.equal(await askHost(target, 'templates:confirm', { message: 'm' }), true);
}

console.log('dialog: ok');

// 7. openDialog: the shared modal (CGE-97). A minimal fake DOM is enough for its wiring.
{
    const { openDialog, confirmDialog } = await import('../../resources/js/editor/dialog.js');

    const makeEl = (tag) => {
        const el = {
            tag, children: [], attrs: {}, listeners: {}, className: '', focused: false, removed: false,
            setAttribute(k, v) { this.attrs[k] = v; },
            append(...n) { this.children.push(...n); },
            addEventListener(t, f) { this.listeners[t] = f; },
            remove() { this.removed = true; },
            focus() { this.focused = true; },
            dispatchEvent() { return true; },
        };

        return el;
    };
    const keyListeners = [];
    globalThis.document = {
        body: makeEl('body'),
        activeElement: null,
        createElement: makeEl,
        addEventListener: (t, f) => keyListeners.push(f),
        removeEventListener: (t, f) => keyListeners.splice(keyListeners.indexOf(f), 1),
    };

    const find = (el, pred) => (pred(el) ? el : el.children.map((c) => find(c, pred)).find(Boolean));
    const buttons = (overlay) => {
        const out = [];
        const walk = (e) => { if (e.tag === 'button') out.push(e); e.children?.forEach(walk); };
        walk(overlay);

        return out;
    };

    // a button closes with its value; the last action is focused; listener is removed
    let host = makeEl('host');
    let pending = openDialog({ root: host, content: [makeEl('p')], actions: [{ label: 'a', value: 1 }, { label: 'b', value: 2 }] });
    let overlay = host.children[0];
    assert.equal(overlay.attrs.role, 'dialog');
    assert.equal(overlay.attrs['aria-modal'], 'true');
    assert.equal(buttons(overlay)[1].focused, true);
    buttons(overlay)[0].listeners.click();
    assert.equal(await pending, 1);
    assert.equal(overlay.removed, true);
    assert.equal(keyListeners.length, 0);

    // an onClick action keeps the dialog open; Escape then resolves escapeValue
    host = makeEl('host');
    let clicked = 0;
    pending = openDialog({ root: host, content: [], escapeValue: 'esc', actions: [{ label: 'x', onClick: () => { clicked++; } }] });
    overlay = host.children[0];
    buttons(overlay)[0].listeners.click();
    assert.equal(clicked, 1);
    assert.equal(overlay.removed, false);
    keyListeners[0]({ key: 'Escape', preventDefault() {} });
    assert.equal(await pending, 'esc');

    // backdrop click resolves escapeValue; Enter only when asked
    host = makeEl('host');
    pending = openDialog({ root: host, content: [], escapeValue: 'bd', actions: [{ label: 'x', value: 'v' }] });
    keyListeners[0]({ key: 'Enter', preventDefault() {} });
    assert.equal(host.children[0].removed, false);
    host.children[0].listeners.mousedown({ target: host.children[0] });
    assert.equal(await pending, 'bd');

    // confirmDialog keeps its contract: Enter confirms, Escape declines
    host = makeEl('host');
    pending = confirmDialog('m', { root: host });
    keyListeners[0]({ key: 'Enter', preventDefault() {} });
    assert.equal(await pending, true);
    host = makeEl('host');
    pending = confirmDialog('m', { root: host });
    keyListeners[0]({ key: 'Escape', preventDefault() {} });
    assert.equal(await pending, false);

    delete globalThis.document;
}
