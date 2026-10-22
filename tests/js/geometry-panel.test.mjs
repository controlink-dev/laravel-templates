import assert from 'node:assert/strict';
import { applyGeometryChange } from '../../resources/js/editor/geometry-panel.js';

/*
 * A version's margins and header/footer heights (CGE-50): a change is rounded to the
 * tenth and refused when it leaves the body under 20 mm — the same rule the server
 * applies at save, so the editor never sends what will be refused.
 */

const a4 = { width: 210, height: 297 };
const geometry = { medium: 'sheet', paper_format: 'A4' };
const base = {
    margins: { top: 15, right: 15, bottom: 15, left: 15 },
    zones: { header_height: 55, footer_height: 35 },
};

{
    const { geometry: next, error } = applyGeometryChange(geometry, base, { zones: { footer_height: 70 } }, a4);

    assert.equal(error, null);
    assert.deepEqual(next.zones, { header_height: 55, footer_height: 70 });
    assert.deepEqual(next.margins, base.margins);
    assert.equal(next.paper_format, 'A4');
}

{
    const { geometry: next, error } = applyGeometryChange(geometry, base, { zones: { footer_height: 200 } }, a4);

    assert.equal(error, 'geometry.body_too_small');
    assert.equal(next, geometry);
}

{
    const { geometry: next } = applyGeometryChange(geometry, base, { margins: { left: 7.04 } }, a4);

    assert.deepEqual(next.margins, { top: 15, right: 15, bottom: 15, left: 7 });
}

{
    // Side margins that leave no width are refused too.
    const { error } = applyGeometryChange(geometry, base, { margins: { left: 105, right: 105 } }, a4);

    assert.equal(error, 'geometry.margins_too_wide');
}

{
    // A label has no bands: it only needs some body, not 20 mm.
    const label = { margins: { top: 5, right: 5, bottom: 5, left: 5 }, zones: { header_height: 0, footer_height: 0 } };
    const paper = { width: 50, height: 25 };

    assert.equal(applyGeometryChange({}, label, { margins: { top: 6 } }, paper).error, null);
    assert.equal(applyGeometryChange({}, label, { margins: { top: 20 } }, paper).error, 'geometry.body_too_small');
}

{
    // A negative value is a zero, as on the server.
    const { geometry: next } = applyGeometryChange(geometry, base, { margins: { top: -3 } }, a4);

    assert.equal(next.margins.top, 0);
}
