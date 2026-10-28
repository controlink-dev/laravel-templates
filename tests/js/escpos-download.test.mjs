import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    bytesFromBase64,
    escPosButtonVisible,
    escPosErrorMessage,
    safeFileName,
} from '../../resources/js/editor/zpl-download.js';

/*
 * CGE-98: "Download thermal receipt" shows only when the host serves the receipt and the
 * format on screen is a roll.
 */
test('the receipt button shows only on a roll with a URL', () => {
    assert.equal(escPosButtonVisible('/v/1/escpos-preview', { medium: 'roll', width_mm: 80 }), true);
    assert.equal(escPosButtonVisible('/v/1/escpos-preview', { medium: 'sheet', paper_format: 'A4' }), false);
    assert.equal(escPosButtonVisible('/v/1/escpos-preview', { medium: 'label' }), false);
    assert.equal(escPosButtonVisible('/v/1/escpos-preview', null), false);
    assert.equal(escPosButtonVisible('', { medium: 'roll' }), false);
    assert.equal(escPosButtonVisible(undefined, { medium: 'roll' }), false);
});

/* A refused receipt (422) lists the problems' messages after the host's: five, then "+N". */
test('a refused receipt names its problems, five at most', () => {
    const problems = (n) => Array.from({ length: n }, (_, i) => ({ code: 'V-21', message: `P${i + 1}` }));

    assert.equal(escPosErrorMessage({ message: 'O talão tem problemas.', problems: problems(2) }, 'Falhou.'), 'O talão tem problemas. P1; P2');
    assert.equal(escPosErrorMessage({ message: 'O talão tem problemas.', problems: problems(5) }, 'Falhou.'), 'O talão tem problemas. P1; P2; P3; P4; P5');
    assert.equal(escPosErrorMessage({ message: 'O talão tem problemas.', problems: problems(8) }, 'Falhou.'), 'O talão tem problemas. P1; P2; P3; P4; P5 +3');
    assert.equal(escPosErrorMessage({ message: 'Não é um rolo.' }, 'Falhou.'), 'Não é um rolo.');
    assert.equal(escPosErrorMessage({ problems: [{ code: 'X' }] }, 'Falhou.'), 'Falhou.');
    assert.equal(escPosErrorMessage(null, 'Falhou.'), 'Falhou.');
});

/* The bytes arrive base64 in the JSON; ESC/POS is binary, so every byte must survive. */
test('the base64 bytes decode byte for byte', () => {
    const raw = [0x1b, 0x40, 0x00, 0xff, 0x80, 0x0a];
    const encoded = Buffer.from(raw).toString('base64');

    assert.deepEqual(Array.from(bytesFromBase64(encoded)), raw);
    assert.equal(bytesFromBase64('').length, 0);
});

/* The host's file name is used, its last segment only. */
test('the file name is the host one, last segment only', () => {
    assert.equal(safeFileName('talao-fatura-v3.bin', 'talao.bin'), 'talao-fatura-v3.bin');
    assert.equal(safeFileName('../../x.bin', 'talao.bin'), 'x.bin');
    assert.equal(safeFileName('a\\b\\x.bin', 'talao.bin'), 'x.bin');
    assert.equal(safeFileName('', 'talao.bin'), 'talao.bin');
    assert.equal(safeFileName(undefined, 'talao.bin'), 'talao.bin');
    assert.equal(safeFileName('dir/', 'talao.bin'), 'talao.bin');
});
