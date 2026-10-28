import assert from 'node:assert/strict';
import { barcodeHint, maxChars, slotCanvasTag } from '../../resources/js/editor/barcode-fit.js';

/*
 * CGE-98: how many characters a Code 128 (subset B) fits in a slot at the narrowest
 * module the head prints (2 dots at 8 dots/mm) — the very formula of the package's
 * `Code128Fit::maxChars`, so the editor's hint and the print check agree.
 */
{
    assert.equal(maxChars(60), 16);
    assert.equal(maxChars(100), 31);
    // Too narrow for even one character: 0, never negative.
    assert.equal(maxChars(5), 0);
    assert.equal(maxChars(0), 0);
    assert.equal(maxChars(Number.NaN), 0);
}

/* The slot's hint, from its rendered width in mm. */
{
    const strings = { 'code_slot.barcode_hint': 'Código de barras — cabem até :n caracteres' };

    assert.equal(barcodeHint(60, strings), 'Código de barras — cabem até 16 caracteres');
    assert.equal(barcodeHint(60, {}), 'Barcode — fits up to 16 characters');
}

console.log('barcode-fit: ok');

/*
 * A barcode slot read back as an `<img>` (seeded or saved layout) is drawn as a box in the
 * canvas, so its hint can show; a QR slot keeps its tag.
 */
{
    assert.equal(slotCanvasTag('img', true), 'div');
    assert.equal(slotCanvasTag('div', true), 'div');
    assert.equal(slotCanvasTag('img', false), 'img');
    assert.equal(slotCanvasTag('div', false), 'div');
}
