import assert from 'node:assert/strict';
import { overlappingPairs, isOpaqueColour } from '../../resources/js/editor/overlap.js';

/*
 * The live overlap check (CGE-90, L-OVER) mirrors the server's V-20 exactly — same boxes
 * from the stated styles, same content rule, same tolerance — so the warning seen while
 * dragging is the one the save asks about, never stricter.
 */

const block = (id, type, style, extra = {}) => ({ id, type, zone: 'header', ancestors: [], style, ...extra });
const text = (id, style, extra) => block(id, 'static-text', style, extra);
const pairs = (blocks) => overlappingPairs(blocks).map(([under, over]) => `${under.id}<${over.id}`);

// Text over text, the later one on top.
assert.deepEqual(pairs([
    text('a', { left: '90mm', top: '0mm', width: '40mm', height: '4mm' }),
    text('b', { left: '100mm', top: '1mm', width: '60mm', height: '6mm' }),
]), ['a<b']);

// Only touching (overlap within 0.5mm): none.
assert.deepEqual(pairs([
    text('a', { left: '90mm', top: '0mm', width: '40mm', height: '4mm' }),
    text('b', { left: '90mm', top: '3.7mm', width: '40mm', height: '4mm' }),
]), []);

// The same numbers in another zone are elsewhere on the page.
assert.deepEqual(pairs([
    text('a', { left: '90mm', top: '0mm', width: '40mm', height: '4mm' }),
    text('b', { left: '90mm', top: '0mm', width: '40mm', height: '4mm' }, { zone: 'footer' }),
]), []);

// Lines and rectangles are never content: an underline or a frame is design.
assert.deepEqual(pairs([
    text('a', { left: '90mm', top: '0mm', width: '40mm', height: '4mm' }),
    block('r', 'rule', { left: '88mm', top: '-1mm', width: '50mm', height: '6mm' }),
]), []);

// No height on text: its em box (8pt ≈ 2.82mm). No width: not checked, as on the server.
assert.deepEqual(pairs([
    text('a', { left: '0mm', top: '0mm', width: '40mm', 'font-size': '8pt' }),
    text('b', { left: '0mm', top: '2.2mm', width: '40mm', height: '4mm' }),
]), ['a<b'], '2.82 - 2.2 = 0.62mm > 0.5mm');
assert.deepEqual(pairs([
    text('a', { left: '0mm', top: '0mm', width: '40mm', 'font-size': '8pt' }),
    text('b', { left: '0mm', top: '2.4mm', width: '40mm', height: '4mm' }),
]), [], '0.42mm is within the tolerance');
assert.deepEqual(pairs([
    text('a', { left: '0mm', top: '0mm' }),
    text('b', { left: '0mm', top: '0mm', width: '40mm', height: '4mm' }),
]), []);

// Pixels, as GrapesJS writes while dragging, are millimetres all the same.
assert.deepEqual(pairs([
    text('a', { left: '0mm', top: '0mm', width: '40mm', height: '4mm' }),
    text('b', { left: '37.795px', top: '7.559px', width: '40mm', height: '4mm' }),
]), ['a<b']);

// Opaque over a field is occlusion (V-15/V-18), not also an overlap; over static text it is.
const image = (id, style) => block(id, 'image', style, { src: 'data:image/png;base64,AAAA' });
assert.deepEqual(pairs([
    block('f', 'data-field', { left: '0mm', top: '0mm', width: '40mm', height: '4mm' }),
    image('i', { left: '0mm', top: '0mm', width: '40mm', height: '4mm' }),
]), []);
assert.deepEqual(pairs([
    text('t', { left: '0mm', top: '0mm', width: '40mm', height: '4mm' }),
    image('i', { left: '0mm', top: '0mm', width: '40mm', height: '4mm' }),
]), ['t<i']);
assert.deepEqual(pairs([
    block('f', 'data-field', { left: '0mm', top: '0mm', width: '40mm', height: '4mm' }),
    text('t', { left: '0mm', top: '0mm', width: '40mm', height: '4mm', 'background-color': 'rgba(255, 255, 255, 0.5)' }),
]), ['f<t'], 'a translucent background hides nothing: plain overlap');

// An image without a file is the editor's placeholder: no content.
assert.deepEqual(pairs([
    text('t', { left: '0mm', top: '0mm', width: '40mm', height: '4mm' }),
    block('i', 'image', { left: '0mm', top: '0mm', width: '40mm', height: '4mm' }, { src: '' }),
]), []);

// A block inside another is not overlapping it.
assert.deepEqual(pairs([
    text('a', { left: '0mm', top: '0mm', width: '40mm', height: '4mm' }),
    text('b', { left: '0mm', top: '0mm', width: '40mm', height: '4mm' }, { ancestors: ['a'] }),
]), []);

// What overlaps is the printed text: the canvas measures it (`inkWidth`, in mm) and the
// block's alignment places it in its box — as the server measures the sample value.
const label = text('l', { left: '0mm', top: '0mm', width: '30mm', height: '4mm' }, { inkWidth: 13 });
const value = (inkWidth, align = 'right') => text('v', { left: '10mm', top: '0mm', width: '40mm', height: '4mm', 'text-align': align }, { inkWidth });
assert.deepEqual(pairs([label, value(25)]), [], 'right-aligned: prints from 25mm, clear of the label ending at 13mm');
assert.deepEqual(pairs([label, value(38)]), ['l<v'], 'long enough to run back over the label');
assert.deepEqual(pairs([label, value(4, 'center')]), [], 'centred in its box');
assert.deepEqual(pairs([label, value(25, 'left')]), ['l<v']);
assert.deepEqual(pairs([label, { ...value(25), wrap: true }]), ['l<v'], 'a wrapped block keeps its whole box');
assert.deepEqual(pairs([label, value(null)]), ['l<v'], 'unmeasured: the whole box');

// Colours, as Colour::parse reads them.
assert.equal(isOpaqueColour('#000'), true);
assert.equal(isOpaqueColour('#000000cc'), false);
assert.equal(isOpaqueColour('rgb(0, 0, 0)'), true);
assert.equal(isOpaqueColour('rgba(0, 0, 0, 0.4)'), false);
assert.equal(isOpaqueColour('transparent'), false);
assert.equal(isOpaqueColour('white'), true);
assert.equal(isOpaqueColour('red'), false, 'unknown names are not read, as on the server');
assert.equal(isOpaqueColour(undefined), false);
assert.equal(isOpaqueColour('rgb(0)'), false, 'fewer than three channels is not a colour');

console.log('overlap: ok');
