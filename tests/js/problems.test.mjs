import assert from 'node:assert/strict';
import { mergeProblems, LOCAL_CHECK_CODES } from '../../resources/js/editor/problems.js';

/*
 * The problems panel merges the server's last-save verdict with the live local checks.
 * For the codes the browser decides itself (V-10/11/13/16), the live result is
 * authoritative: a server copy of such a code must not be kept beside it, or the panel
 * double-lists it while the field is bad and keeps showing it after the field is fixed.
 */

// 1. A locally-decidable code reported by BOTH is listed once, from the live check.
{
    const server = [{ code: 'V-11', componentId: 'a', message: 'server copy' }];
    const local = [{ code: 'V-11', componentId: 'a', message: 'live copy' }];

    const merged = mergeProblems(server, local);

    assert.equal(merged.filter((p) => p.code === 'V-11').length, 1, 'V-11 must appear once, not twice');
    assert.equal(merged[0].message, 'live copy', 'the kept V-11 must be the live one');
}

// 2. A locally-decidable server problem does NOT linger once the live check clears it.
{
    const server = [{ code: 'V-11', componentId: 'a', message: 'stale' }];
    const local = []; // user fixed the font size, so the live check reports nothing

    const merged = mergeProblems(server, local);

    assert.equal(merged.length, 0, 'a fixed, locally-decidable problem must not remain');
}

// 3. A server-only code (contrast, occlusion, structure) the browser cannot decide stays.
{
    const server = [{ code: 'V-12', componentId: 'b', message: 'contrast too low' }];
    const local = [];

    const merged = mergeProblems(server, local);

    assert.deepEqual(merged, server, 'server-only problems must be kept');
}

// 4. Server-only problems come before the live ones (preserves the existing ordering).
{
    const server = [{ code: 'V-12', componentId: 'b', message: 'contrast' }];
    const local = [{ code: 'V-11', componentId: 'a', message: 'font' }];

    const merged = mergeProblems(server, local);

    assert.deepEqual(merged.map((p) => p.code), ['V-12', 'V-11']);
}

// The owned set is exactly what checkLegalFieldsLocally can produce.
assert.ok(LOCAL_CHECK_CODES.has('V-10') && LOCAL_CHECK_CODES.has('V-11') && LOCAL_CHECK_CODES.has('V-13') && LOCAL_CHECK_CODES.has('V-16'));

console.log('problems.test.mjs: all assertions passed');

// 4. The live "missing table" check (CGE-52) has its own code: a server V-02 for a single
//    mandatory field stays listed beside it.
{
    const server = [{ code: 'V-02', componentId: '', message: 'supply date missing' }];
    const local = [{ code: 'L-02', componentId: '', message: 'tax summary table missing' }];

    const merged = mergeProblems(server, local);

    assert.deepEqual(merged.map((p) => p.code), ['V-02', 'L-02']);
    assert.equal(LOCAL_CHECK_CODES.has('L-02'), false);
}
