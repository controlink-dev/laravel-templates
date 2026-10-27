import assert from 'node:assert/strict';
import { bytesFromBase64, shouldShowEscposButton } from '../../resources/js/editor/escpos-preview.js';

/*
 * The thermal-receipt preview button shows only on a roll, and only when the host gave
 * an endpoint for it (CGE-97): on any other format the editor is exactly what it was.
 */
const url = '/escpos-preview';

assert.equal(shouldShowEscposButton({ geometry: { medium: 'roll' }, escposPreviewUrl: url }), true);
assert.equal(shouldShowEscposButton({ geometry: { medium: 'roll' }, escposPreviewUrl: '' }), false);
assert.equal(shouldShowEscposButton({ geometry: { medium: 'roll' } }), false);
assert.equal(shouldShowEscposButton({ geometry: { medium: 'sheet' }, escposPreviewUrl: url }), false);
assert.equal(shouldShowEscposButton({ geometry: null, escposPreviewUrl: url }), false);
assert.equal(shouldShowEscposButton({ escposPreviewUrl: url }), false);

const bytes = bytesFromBase64('G0A=');
assert.ok(bytes instanceof Uint8Array);
assert.deepEqual([...bytes], [0x1B, 0x40]);
assert.deepEqual([...bytesFromBase64('')], []);

console.log('escpos-preview: ok');
