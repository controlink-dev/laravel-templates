import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { exampleLine, filterLibrary, functionGroups } from '../../resources/js/editor/formula-library.js';

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
