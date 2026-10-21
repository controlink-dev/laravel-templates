import assert from 'node:assert/strict';
import { saveConfirmingWarnings } from '../../resources/js/editor/save.js';

/*
 * A save can come back with warnings (a covered field the law does not require, CGE-27):
 * nothing was written, and the user is asked whether to save anyway. Confirmed, the same
 * save is sent again acknowledging them; declined, the save is cancelled — quietly, since
 * the user chose it. Blocking problems are never put to the user as a choice.
 */

const warning = { code: 'V-18', component_id: 'a', message: 'covered' };
const recorder = (responses) => {
    const calls = [];

    return {
        calls,
        send: async (acknowledge) => {
            calls.push(acknowledge);

            return responses.shift();
        },
    };
};

// 1. A clean save is sent once, unacknowledged, and its payload returned.
{
    const server = recorder([{ ok: true, payload: { message: 'saved' } }]);
    const asked = [];

    const result = await saveConfirmingWarnings(server.send, async (w) => { asked.push(w); return true; });

    assert.deepEqual(result, { message: 'saved' });
    assert.deepEqual(server.calls, [false]);
    assert.deepEqual(asked, []);
}

// 2. Warnings, confirmed: the user sees them, the save is re-sent acknowledged.
{
    const server = recorder([
        { ok: false, payload: { message: 'check', warnings: [warning] } },
        { ok: true, payload: { message: 'saved' } },
    ]);
    const asked = [];

    const result = await saveConfirmingWarnings(server.send, async (w) => { asked.push(w); return true; });

    assert.deepEqual(result, { message: 'saved' });
    assert.deepEqual(server.calls, [false, true]);
    assert.deepEqual(asked, [[warning]]);
}

// 3. Warnings, declined: nothing re-sent, a cancelled error is thrown.
{
    const server = recorder([{ ok: false, payload: { message: 'check', warnings: [warning] } }]);

    await assert.rejects(
        saveConfirmingWarnings(server.send, async () => false),
        (error) => error.cancelled === true,
    );
    assert.deepEqual(server.calls, [false]);
}

// 4. Blocking problems: not asked, thrown with the problems, even if warnings came too.
{
    const problems = [{ code: 'V-15', component_id: 'b', message: 'legal covered' }];
    const server = recorder([{ ok: false, payload: { message: 'refused', problems, warnings: [warning] } }]);
    const asked = [];

    await assert.rejects(
        saveConfirmingWarnings(server.send, async (w) => { asked.push(w); return true; }, 'fallback'),
        (error) => error.message === 'refused' && error.problems === problems && !error.cancelled,
    );
    assert.deepEqual(asked, []);
}

// 5. A failure with no message falls back to the given one.
{
    const server = recorder([{ ok: false, payload: {} }]);

    await assert.rejects(
        saveConfirmingWarnings(server.send, async () => true, 'Save failed'),
        (error) => error.message === 'Save failed' && Array.isArray(error.problems),
    );
}

console.log('save.test.mjs: ok');
