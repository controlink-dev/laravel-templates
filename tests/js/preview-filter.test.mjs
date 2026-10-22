import assert from 'node:assert/strict';
import { plainProjectData, refreshPreview } from '../../resources/js/editor/preview-filter.js';

/*
 * While GrapesJS loads another format's saved project data it drops the old page before
 * the new one exists, and `component:remove` fires in between with no wrapper at all.
 * The dirty check reads the project data on that event; walking an absent wrapper threw
 * "Cannot read properties of undefined (reading 'previewContent')" and aborted the switch
 * from a talão back to A4/A5.
 */
{
    const editor = { getWrapper: () => undefined, getProjectData: () => ({ pages: [] }) };

    assert.deepEqual(plainProjectData(editor), { pages: [] });
    assert.doesNotThrow(() => refreshPreview(editor));
}

console.log('preview-filter: ok');
