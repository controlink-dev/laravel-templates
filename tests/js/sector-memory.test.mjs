import assert from 'node:assert/strict';
import { readSectors, writeSector } from '../../resources/js/editor/sector-memory.js';

/*
 * The style panel's sections stay as the user left them (CGE-53), in the browser only,
 * and a storage that is missing, broken or full never breaks the editor.
 */
const memory = () => {
    const map = new Map();

    return { getItem: (key) => map.get(key) ?? null, setItem: (key, value) => map.set(key, String(value)) };
};

const storage = memory();
writeSector(storage, 'tpl-position', true);
writeSector(storage, 'tpl-typography', false);
assert.deepEqual(readSectors(storage), { 'tpl-position': true, 'tpl-typography': false });

assert.deepEqual(readSectors(null), {});
assert.deepEqual(readSectors({ getItem: () => { throw new Error('blocked'); } }), {});
assert.deepEqual(readSectors({ getItem: () => '{not json' }), {});
assert.deepEqual(readSectors({ getItem: () => '[1,2]' }), {});
assert.doesNotThrow(() => writeSector({ getItem: () => null, setItem: () => { throw new Error('full'); } }, 'x', true));
assert.doesNotThrow(() => writeSector(null, 'x', true));

console.log('sector-memory: ok');
