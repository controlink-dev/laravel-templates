import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { createFormulaLibrary, exampleLine, filterLibrary, functionGroups, groupExpanded } from '../../resources/js/editor/formula-library.js';

/*
 * The "Campos e funções" library (CGE-37, spec §6.3): the pure parts — what the search
 * keeps, and the functions grouped by kind in the reference's order.
 */

const catalogue = {
    groups: [
        { id: 'row', label: 'Esta linha', kind: 'row', items: [
            { key: 'lines.quantity', label: 'Quantidade', display: '[Quantidade]', insert: '[Quantidade]' },
            { key: 'lines.unit_price', label: 'Preço unitário', display: '[Preço unitário]', insert: '[Preço unitário]' },
        ] },
        { id: 'customer', label: 'Cliente', kind: 'singular', items: [
            { key: 'customer.name', label: 'Nome', display: '[Cliente · Nome]', insert: '[Cliente · Nome]' },
            { key: 'customer.tax_id', label: 'customer.tax_id', display: 'customer.tax_id', insert: 'customer.tax_id' },
        ] },
        { id: 'totals', label: 'Totais da tabela', kind: 'totals', items: [
            { key: 'lines.amount', label: 'Valor', display: '[Linhas · Valor]', insert: 'SUM([Linhas · Valor])' },
        ] },
    ],
    functions: [
        { name: 'IF', signature: 'IF(condição, se_verdadeiro, se_falso)', description: 'O segundo valor se a condição se verificar.', example: '', group: 'conditions' },
        { name: 'SUM', signature: 'SUM(coleção.coluna)', description: 'Soma a coluna.', example: '', group: 'totals' },
        { name: 'UPPER', signature: 'UPPER(texto)', description: 'O texto em maiúsculas.', example: '', group: 'text' },
        { name: 'ROUND', signature: 'ROUND(número[, decimais])', description: 'O número arredondado.', example: '', group: 'math' },
        { name: 'ABS', signature: 'ABS(número)', description: 'O número sem sinal.', example: '', group: 'math' },
        { name: 'YEAR', signature: 'YEAR(data)', description: 'O ano da data.', example: '', group: 'dates' },
        { name: 'NEWFN', signature: 'NEWFN()', description: 'Acrescentada depois.', example: '', group: 'other' },
    ],
};

const keys = (result) => result.groups.flatMap((group) => group.items.map((item) => item.key));
const names = (result) => result.functions.map((fn) => fn.name);

describe('filterLibrary', () => {
    it('keeps everything for an empty or blank query', () => {
        assert.deepEqual(filterLibrary(catalogue, '').groups, catalogue.groups);
        assert.deepEqual(filterLibrary(catalogue, '   ').functions, catalogue.functions);
        assert.deepEqual(filterLibrary(catalogue, undefined).groups, catalogue.groups);
    });

    it('matches field labels without case or accents', () => {
        assert.deepEqual(keys(filterLibrary(catalogue, 'PRECO')), ['lines.unit_price']);
        assert.deepEqual(keys(filterLibrary(catalogue, 'quant')), ['lines.quantity']);
    });

    it('matches a field by its technical key and by the name it is shown by', () => {
        assert.deepEqual(keys(filterLibrary(catalogue, 'tax_id')), ['customer.tax_id']);
        assert.deepEqual(keys(filterLibrary(catalogue, 'cliente · nome')), ['customer.name']);
    });

    it('drops the groups the query leaves empty', () => {
        const result = filterLibrary(catalogue, 'nome');

        assert.deepEqual(result.groups.map((group) => group.id), ['customer']);
    });

    it('matches functions by name, signature and description, without accents', () => {
        assert.deepEqual(names(filterLibrary(catalogue, 'up')), ['UPPER']);
        assert.deepEqual(names(filterLibrary(catalogue, 'maiusculas')), ['UPPER']);
        assert.deepEqual(names(filterLibrary(catalogue, 'decimais')), ['ROUND']);
    });

    it('does not lose the catalogue it was given', () => {
        filterLibrary(catalogue, 'nome');

        assert.equal(catalogue.groups.length, 3);
        assert.equal(catalogue.groups[1].items.length, 2);
    });
});

describe('functionGroups', () => {
    it('groups the functions in the reference order, with the unknown ones last', () => {
        const groups = functionGroups(catalogue.functions);

        assert.deepEqual(groups.map((group) => group.id), ['math', 'totals', 'conditions', 'text', 'dates', 'other']);
        assert.deepEqual(groups[0].functions.map((fn) => fn.name), ['ROUND', 'ABS']);
        assert.deepEqual(groups[5].functions.map((fn) => fn.name), ['NEWFN']);
    });

    it('leaves out the groups with no function (after a search)', () => {
        const groups = functionGroups(filterLibrary(catalogue, 'texto').functions);

        assert.deepEqual(groups.map((group) => group.id), ['text']);
    });

    it('places a function by its name when it carries no group', () => {
        const groups = functionGroups([{ name: 'MAX' }, { name: 'DAYS' }]);

        assert.deepEqual(groups.map((group) => group.id), ['math', 'dates']);
    });
});

describe('exampleLine', () => {
    it('fills the example in, leaving any $ pattern it holds as written', () => {
        assert.equal(exampleLine('ex.: :example', 'SUM([Linhas · Valor])'), 'ex.: SUM([Linhas · Valor])');
        assert.equal(exampleLine('ex.: :example', `"$&" & "$'" & "$1"`), `ex.: "$&" & "$'" & "$1"`);
        assert.equal(exampleLine(undefined, 'A'), 'A');
    });
});

/*
 * The library controller over a minimal stand-in DOM (no DOM in node): only what
 * `createFormulaLibrary` touches. A click on a field inserts into the box; a box drawn
 * again for the same owner is followed (`adopt`); a box gone with nothing in its place
 * closes the library instead of inserting into a textarea no one sees.
 */
describe('createFormulaLibrary', () => {
    class FakeElement {
        constructor(tag) {
            this.tagName = tag.toUpperCase();
            this.children = [];
            this.listeners = {};
            this.dataset = {};
            this.attributes = {};
            this.className = '';
            this.textContent = '';
            this.value = '';
            this.parent = null;
            const classes = new Set();
            this.classList = {
                add: (name) => classes.add(name),
                remove: (name) => classes.delete(name),
                toggle: (name, on) => (on ?? !classes.has(name) ? classes.add(name) : classes.delete(name)),
                contains: (name) => classes.has(name),
            };
        }

        setAttribute(name, value) { this.attributes[name] = String(value); }
        addEventListener(event, handler) { (this.listeners[event] ??= []).push(handler); }
        append(...nodes) { nodes.forEach((node) => { node.parent = this; this.children.push(node); }); }
        replaceChildren(...nodes) { this.children = []; this.append(...nodes); }
        remove() { if (this.parent) { this.parent.children = this.parent.children.filter((node) => node !== this); this.parent = null; } }
        contains(node) { return node === this || this.children.some((child) => child.contains(node)); }
        closest() { return null; }
        querySelectorAll() { return []; }
        find(className) { return this.className === className ? this : this.children.map((child) => child.find(className)).find(Boolean) ?? null; }
        fire(event) { (this.listeners[event] ?? []).forEach((handler) => handler({ preventDefault() {} })); }
    }

    const box = (owner, connected = true) => ({
        isConnected: connected,
        dataset: { formulaOwner: owner },
        value: '',
        selectionStart: 0,
        selectionEnd: 0,
        inputs: 0,
        setRangeText(text) { this.value += text; },
        focus() {},
        setSelectionRange(start, end) { this.selectionStart = start; this.selectionEnd = end; },
        dispatchEvent() { this.inputs++; },
    });

    const setUp = () => {
        globalThis.document = { createElement: (tag) => new FakeElement(tag) };
        const rail = new FakeElement('div');
        const config = {
            strings: {},
            bindings: [{ key: 'lines', label: 'Linhas', type: 'collection', columns: [{ key: 'quantity', label: 'Quantidade' }] }],
            nodes: [],
        };

        return { rail, library: createFormulaLibrary({ rail, config }) };
    };

    it('inserts into the box it was opened for', () => {
        const { rail, library } = setUp();
        const target = box('t1:formula_aaaaaaaa');

        library.open({ target, rowCollection: 'lines' });
        rail.find('tpl-formula-library__field').fire('click');

        assert.equal(target.value, '[Quantidade]');
        assert.equal(target.inputs, 1);
    });

    it('follows a box drawn again for the same owner, and only that', () => {
        const { rail, library } = setUp();
        const old = box('t1:formula_aaaaaaaa');
        const other = box('t2:formula_bbbbbbbb');
        const again = box('t1:formula_aaaaaaaa');

        library.open({ target: old, rowCollection: 'lines' });
        library.adopt(other);
        assert.equal(library.target(), old, 'another owner is not followed');

        old.selectionStart = 2;
        old.selectionEnd = 4;
        old.isConnected = false;
        library.adopt(again);
        assert.equal(library.target(), again);
        assert.deepEqual([again.selectionStart, again.selectionEnd], [2, 4], 'the caret where it was in the old box');

        rail.find('tpl-formula-library__field').fire('click');
        assert.equal(again.value, '[Quantidade]');
        assert.equal(old.value, '');
    });

    it('closes instead of inserting into a box that is gone', () => {
        const { rail, library } = setUp();
        const target = box('t1:formula_aaaaaaaa');

        library.open({ target, rowCollection: 'lines' });
        target.isConnected = false;
        rail.find('tpl-formula-library__field').fire('click');

        assert.equal(target.value, '');
        assert.equal(library.isOpen(), false);
        assert.equal(library.target(), null);
        assert.equal(rail.find('tpl-formula-library'), null, 'the library left the rail');
    });
});

describe('groupExpanded', () => {
    it('opens only the row group by default', () => {
        const state = new Map();

        assert.equal(groupExpanded(state, 'fields', 'row', '', true), true);
        assert.equal(groupExpanded(state, 'fields', 'customer', '', false), false);
        assert.equal(groupExpanded(state, 'functions', 'math', ''), false);
    });

    it('follows what the user chose, per tab', () => {
        const state = new Map([['fields:customer', true], ['fields:row', false]]);

        assert.equal(groupExpanded(state, 'fields', 'customer', '', false), true);
        assert.equal(groupExpanded(state, 'fields', 'row', '', true), false);
        assert.equal(groupExpanded(state, 'functions', 'customer', '', false), false, 'another tab is another group');
    });

    it('opens every group while a search is typed, without changing the choice', () => {
        const state = new Map([['fields:row', false]]);

        assert.equal(groupExpanded(state, 'fields', 'row', 'quant', true), true);
        assert.equal(groupExpanded(state, 'fields', 'customer', ' nome ', false), true);
        assert.equal(groupExpanded(state, 'fields', 'customer', '   ', false), false, 'a blank query is no search');
        assert.deepEqual([...state], [['fields:row', false]]);
    });
});

describe('createFormulaLibrary folding groups', () => {
    class FakeElement {
        constructor(tag) {
            this.tagName = tag.toUpperCase();
            this.children = [];
            this.listeners = {};
            this.dataset = {};
            this.attributes = {};
            this.className = '';
            this.textContent = '';
            this.value = '';
            this.hidden = false;
            this.parent = null;
            const classes = new Set();
            this.classList = {
                add: (name) => classes.add(name),
                remove: (name) => classes.delete(name),
                toggle: (name, on) => (on ?? !classes.has(name) ? classes.add(name) : classes.delete(name)),
                contains: (name) => classes.has(name),
            };
        }

        setAttribute(name, value) { this.attributes[name] = String(value); }
        addEventListener(event, handler) { (this.listeners[event] ??= []).push(handler); }
        append(...nodes) { nodes.forEach((node) => { node.parent = this; this.children.push(node); }); }
        replaceChildren(...nodes) { this.children = []; this.append(...nodes); }
        remove() { if (this.parent) { this.parent.children = this.parent.children.filter((node) => node !== this); this.parent = null; } }
        contains(node) { return node === this || this.children.some((child) => child.contains(node)); }
        closest() { return null; }
        querySelectorAll() { return []; }
        findAll(className) { return [...(this.className === className ? [this] : []), ...this.children.flatMap((child) => child.findAll(className))]; }
        fire(event) {
            let prevented = false;
            (this.listeners[event] ?? []).forEach((handler) => handler({ preventDefault() { prevented = true; } }));

            return prevented;
        }
    }

    const setUp = () => {
        globalThis.document = { createElement: (tag) => new FakeElement(tag) };
        const rail = new FakeElement('div');
        const config = {
            strings: {},
            bindings: [{ key: 'lines', label: 'Linhas', type: 'collection', columns: [{ key: 'quantity', label: 'Quantidade' }] }],
            nodes: [{ id: 'customer', label: 'Cliente', fields: [{ key: 'customer.name', label: 'Nome' }, { key: 'customer.city', label: 'Cidade' }] }],
        };
        const target = { isConnected: true, dataset: { formulaOwner: 'f1' }, value: '', setRangeText() {}, focus() {}, dispatchEvent() {} };

        return { rail, target, library: createFormulaLibrary({ rail, config }) };
    };

    const heads = (rail) => Object.fromEntries(rail.findAll('tpl-formula-library__group-head')
        .map((head) => [head.dataset.group, head]));
    const isOpen = (head) => head.attributes['aria-expanded'] === 'true' && head.parent.children[1].hidden === false;
    const search = (rail, text) => {
        const input = rail.findAll('tpl-formula-library__search')[0];

        input.value = text;
        input.fire('input');
    };

    it('starts with only the row group open, each heading with its count', () => {
        const { rail, target, library } = setUp();

        library.open({ target, rowCollection: 'lines' });
        const groups = heads(rail);

        assert.deepEqual(Object.keys(groups), ['row', 'customer', 'totals']);
        assert.equal(isOpen(groups.row), true);
        assert.equal(isOpen(groups.customer), false);
        assert.equal(isOpen(groups.totals), false);
        assert.equal(groups.customer.findAll('tpl-formula-library__group-count')[0].textContent, '· 2');
    });

    it('folds on click without taking the focus, and remembers it across close and open', () => {
        const { rail, target, library } = setUp();

        library.open({ target, rowCollection: 'lines' });
        assert.equal(heads(rail).customer.fire('mousedown'), true, 'mousedown is prevented');
        heads(rail).customer.fire('click');
        heads(rail).row.fire('click');
        assert.equal(isOpen(heads(rail).customer), true);
        assert.equal(isOpen(heads(rail).row), false);

        library.open({ target: { ...target }, rowCollection: 'lines' });
        assert.equal(isOpen(heads(rail).customer), true, 'kept across a retarget');

        library.close();
        library.open({ target, rowCollection: 'lines' });
        assert.equal(isOpen(heads(rail).customer), true, 'kept across close and open');
        assert.equal(isOpen(heads(rail).row), false);
    });

    it('opens every matching group while searching, and restores the choice after', () => {
        const { rail, target, library } = setUp();

        library.open({ target, rowCollection: 'lines' });
        search(rail, 'a');
        Object.values(heads(rail)).forEach((head) => assert.equal(isOpen(head), true, head.dataset.group));

        heads(rail).customer.fire('click');
        search(rail, '');
        assert.equal(isOpen(heads(rail).customer), false, 'a click while searching is not remembered');
        assert.equal(isOpen(heads(rail).row), true);
    });
});
