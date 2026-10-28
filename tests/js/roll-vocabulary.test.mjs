import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { ROLL_CLASSES, rollProblems } from '../../resources/js/editor/roll-vocabulary.js';

/*
 * The receipt vocabulary, L-ESC (CGE-97): the editor's mirror of the server's V-21
 * (RollVocabulary.php). The same fixture feeds both tests, so the same layout gets the same
 * verdict on both sides. Plain node: no DOM.
 */

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/roll-vocabulary-cases.json', import.meta.url), 'utf8'));

for (const testCase of fixture.cases) {
    assert.deepEqual(
        rollProblems(testCase.html, testCase.css, testCase.columns)
            .map(({ code, key, componentId, subject }) => ({ code, key, componentId, subject })),
        testCase.expected.map((expected) => ({
            code: 'L-ESC',
            key: expected.key,
            componentId: expected.component_id,
            subject: expected.subject,
        })),
        testCase.name,
    );
}

// The text row fragment (what Task 7's `text-row` writes) is in the vocabulary.
assert.deepEqual(rollProblems(`<div class="page">${fixture.text_row}</div>`, '', 40), []);

// The allowed classes are exactly the `esc-*` classes print.css ships (Task 3).
const printCss = readFileSync(new URL('../../resources/css/print.css', import.meta.url), 'utf8');
const shipped = [...new Set([...printCss.matchAll(/\.(esc-[a-z0-9-]+)/g)].map((match) => match[1]))].sort();

assert.deepEqual([...ROLL_CLASSES].sort(), shipped);

// Nothing to judge is nothing wrong.
assert.deepEqual(rollProblems('', '', 40), []);

console.log('roll-vocabulary: ok');
