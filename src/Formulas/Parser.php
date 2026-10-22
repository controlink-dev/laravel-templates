<?php

namespace Controlink\Templates\Formulas;

/**
 * Recursive-descent parser for the closed formula language (CGE-37).
 *
 * Why a closed, hand-written grammar instead of handing user text to Twig:
 * the Twig sandbox only polices tags, filters and functions, not operators, so
 * `**`, `matches`, `..` and friends would pass straight through it. Here the
 * formula text is parsed into a small AST and never reaches Twig or the PDF;
 * the evaluator walks that AST, so only what this file accepts can ever run.
 *
 * Precedence, loosest to tightest: comparison, `&`, `+ -`, `* /`, unary `-`.
 *
 * AST nodes: num{value}, str{value}, bool{value}, ref{key}, neg{expr},
 * bin{op,left,right}, call{name,args}; every node carries a `type`.
 */
final class Parser
{
    /**
     * Function name => [min args, max args|null for variadic]. TODAY is left out
     * on purpose: a reprint would give a different result and break reproducibility.
     *
     * @var array<string, array{0: int, 1: int|null}>
     */
    public const FUNCTIONS = [
        'IF' => [3, 3],
        'AND' => [1, null],
        'OR' => [1, null],
        'NOT' => [1, 1],
        'SUM' => [1, 1],
        'COUNT' => [1, 1],
        'ROUND' => [1, 2],
        'ABS' => [1, 1],
        'MIN' => [1, null],
        'MAX' => [1, null],
        'UPPER' => [1, 1],
        'LOWER' => [1, 1],
        'CONCAT' => [1, null],
        'LEFT' => [1, 2],
        'RIGHT' => [1, 2],
        'LEN' => [1, 1],
        'TRIM' => [1, 1],
        'YEAR' => [1, 1],
        'MONTH' => [1, 1],
        'DAY' => [1, 1],
        'DAYS' => [2, 2],
        'TEXT' => [2, 3],
    ];

    private const REFERENCE = '/^[a-z_][a-z0-9_]*(\.[a-z0-9_]+)+$/';

    private const COMPARISON = ['=', '<>', '<', '<=', '>', '>='];

    /** @var list<array{t: string, v: mixed}> */
    private array $tokens;

    private int $pos = 0;

    /**
     * @param  list<array{t: string, v: mixed}>  $tokens
     */
    private function __construct(array $tokens)
    {
        $this->tokens = $tokens;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws FormulaException syntax | unknown_function | arity
     */
    public static function parse(string $source): array
    {
        $parser = new self(Tokenizer::tokenize($source));
        $ast = $parser->comparison();

        if ($parser->peek() !== null) {
            throw new FormulaException('syntax', 'Unexpected token.');
        }

        return $ast;
    }

    private function comparison(): array
    {
        $left = $this->concat();

        while ($this->isOp(self::COMPARISON)) {
            $op = $this->next()['v'];
            $left = ['type' => 'bin', 'op' => $op, 'left' => $left, 'right' => $this->concat()];
        }

        return $left;
    }

    private function concat(): array
    {
        $left = $this->additive();

        while ($this->isOp(['&'])) {
            $this->next();
            $left = ['type' => 'bin', 'op' => '&', 'left' => $left, 'right' => $this->additive()];
        }

        return $left;
    }

    private function additive(): array
    {
        $left = $this->multiplicative();

        while ($this->isOp(['+', '-'])) {
            $op = $this->next()['v'];
            $left = ['type' => 'bin', 'op' => $op, 'left' => $left, 'right' => $this->multiplicative()];
        }

        return $left;
    }

    private function multiplicative(): array
    {
        $left = $this->unary();

        while ($this->isOp(['*', '/'])) {
            $op = $this->next()['v'];
            $left = ['type' => 'bin', 'op' => $op, 'left' => $left, 'right' => $this->unary()];
        }

        return $left;
    }

    private function unary(): array
    {
        if ($this->isOp(['-'])) {
            $this->next();

            return ['type' => 'neg', 'expr' => $this->unary()];
        }

        return $this->primary();
    }

    private function primary(): array
    {
        $token = $this->next();

        switch ($token['t'] ?? null) {
            case 'num':
                return ['type' => 'num', 'value' => $token['v']];
            case 'str':
                return ['type' => 'str', 'value' => $token['v']];
            case '(':
                $expr = $this->comparison();
                $this->expect(')');

                return $expr;
            case 'ident':
                return $this->identifier($token['v']);
        }

        throw new FormulaException('syntax', 'Unexpected token.');
    }

    private function identifier(string $name): array
    {
        if (($this->peek()['t'] ?? null) === '(') {
            return $this->call($name);
        }

        $upper = strtoupper($name);
        if ($upper === 'TRUE' || $upper === 'FALSE') {
            return ['type' => 'bool', 'value' => $upper === 'TRUE'];
        }

        // Keys are lowercase on purpose: they are catalog keys, not free text.
        if (preg_match(self::REFERENCE, $name) === 1) {
            return ['type' => 'ref', 'key' => $name];
        }

        throw new FormulaException('syntax', 'Unknown reference.');
    }

    private function call(string $name): array
    {
        // `a.b(1)` is not a function call, whatever the prefix looks like.
        if (str_contains($name, '.')) {
            throw new FormulaException('syntax', 'Invalid function name.');
        }

        $upper = strtoupper($name);
        if (! isset(self::FUNCTIONS[$upper])) {
            throw new FormulaException('unknown_function', "Unknown function {$upper}.");
        }

        $this->expect('(');
        $args = [];
        if (($this->peek()['t'] ?? null) !== ')') {
            do {
                $args[] = $this->comparison();
            } while ($this->accept(','));
        }
        $this->expect(')');

        [$min, $max] = self::FUNCTIONS[$upper];
        if (count($args) < $min || ($max !== null && count($args) > $max)) {
            throw new FormulaException('arity', "Wrong number of arguments for {$upper}.");
        }

        return ['type' => 'call', 'name' => $upper, 'args' => $args];
    }

    private function peek(): ?array
    {
        return $this->tokens[$this->pos] ?? null;
    }

    private function next(): array
    {
        return $this->tokens[$this->pos++] ?? [];
    }

    private function isOp(array $ops): bool
    {
        $token = $this->peek();

        return $token !== null && $token['t'] === 'op' && in_array($token['v'], $ops, true);
    }

    private function accept(string $type): bool
    {
        if (($this->peek()['t'] ?? null) === $type) {
            $this->pos++;

            return true;
        }

        return false;
    }

    private function expect(string $type): void
    {
        if (! $this->accept($type)) {
            throw new FormulaException('syntax', "Expected {$type}.");
        }
    }
}
