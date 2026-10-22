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
