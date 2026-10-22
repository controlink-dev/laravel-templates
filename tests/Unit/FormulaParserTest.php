<?php

namespace Controlink\Templates\Tests\Unit;

use Controlink\Templates\Formulas\Formula;
use Controlink\Templates\Formulas\FormulaException;
use Controlink\Templates\Formulas\Parser;
use Controlink\Templates\Tests\TestCase;

class FormulaParserTest extends TestCase
{
    public function test_parses_spreadsheet_operators(): void
    {
        // a.b * 2 + 1 => (a.b * 2) + 1
        $this->assertSame([
            'type' => 'bin', 'op' => '+',
            'left' => [
                'type' => 'bin', 'op' => '*',
                'left' => ['type' => 'ref', 'key' => 'a.b'],
                'right' => ['type' => 'num', 'value' => 2],
            ],
            'right' => ['type' => 'num', 'value' => 1],
        ], Parser::parse('a.b * 2 + 1'));

        // 1 + 2 * 3 => 1 + (2 * 3)
        $ast = Parser::parse('1 + 2 * 3');
        $this->assertSame('+', $ast['op']);
        $this->assertSame('*', $ast['right']['op']);

        $ast = Parser::parse('a.b <> "x"');
        $this->assertSame('bin', $ast['type']);
        $this->assertSame('<>', $ast['op']);
        $this->assertSame(['type' => 'str', 'value' => 'x'], $ast['right']);

        $ast = Parser::parse('"a" & b.c');
        $this->assertSame('&', $ast['op']);
        $this->assertSame(['type' => 'ref', 'key' => 'b.c'], $ast['right']);

        // & binds looser than +, tighter than comparison
        $ast = Parser::parse('a.b & 1 + 2 = c.d');
        $this->assertSame('=', $ast['op']);
        $this->assertSame('&', $ast['left']['op']);
        $this->assertSame('+', $ast['left']['right']['op']);
    }

    public function test_operators_are_left_associative(): void
    {
        $ast = Parser::parse('10 - 3 - 2');
        $this->assertSame('-', $ast['op']);
        $this->assertSame('-', $ast['left']['op']);
        $this->assertSame(2, $ast['right']['value']);
    }

    public function test_literals_and_unary_minus(): void
    {
        $this->assertSame(['type' => 'num', 'value' => 0.23], Parser::parse('0.23'));
        $this->assertSame(['type' => 'str', 'value' => 'say "hi"'], Parser::parse('"say ""hi"""'));
        $this->assertSame(['type' => 'bool', 'value' => true], Parser::parse('true'));
        $this->assertSame(['type' => 'bool', 'value' => false], Parser::parse('FALSE'));
        $this->assertSame(
            ['type' => 'neg', 'expr' => ['type' => 'ref', 'key' => 'a.b']],
            Parser::parse('-a.b')
        );
        $this->assertSame('neg', Parser::parse('2 * -3')['right']['type']);
    }

    public function test_parentheses_override_precedence(): void
    {
        $ast = Parser::parse('(1 + 2) * 3');
        $this->assertSame('*', $ast['op']);
        $this->assertSame('+', $ast['left']['op']);
    }

    public function test_text_literals_may_contain_template_syntax(): void
    {
        // Literals are data, never code: the whitelist applies outside quotes only.
        $this->assertSame('{{ a.b }} {% x %} | ^ %', Parser::parse('"{{ a.b }} {% x %} | ^ %"')['value']);
    }

    public function test_text_literals_accept_utf8(): void
    {
        $this->assertSame('à ã € ç', Parser::parse('"à ã € ç"')['value']);

        $ast = Parser::parse('"Os artigos foram colocados à disposição em " & TEXT(d.date, "date")');
        $this->assertSame('&', $ast['op']);
        $this->assertSame('TEXT', $ast['right']['name']);
    }

    public function test_if_parses_as_call_with_three_args(): void
    {
        $ast = Parser::parse('IF(a.b > 1, "yes", "no")');

        $this->assertSame('call', $ast['type']);
        $this->assertSame('IF', $ast['name']);
        $this->assertCount(3, $ast['args']);
        $this->assertSame('>', $ast['args'][0]['op']);
    }

    public function test_function_names_case_insensitive(): void
    {
        $this->assertSame(
            ['type' => 'call', 'name' => 'SUM', 'args' => [['type' => 'ref', 'key' => 'lines.total']]],
            Parser::parse('sum(lines.total)')
        );
        $this->assertSame('SUM', Parser::parse('Sum(lines.total)')['name']);
    }

    public function test_optional_arguments_and_nested_calls(): void
    {
        $this->assertCount(1, Parser::parse('ROUND(a.b)')['args']);
        $this->assertCount(2, Parser::parse('ROUND(0,23)')['args']);
        $this->assertCount(1, Parser::parse('LEFT(a.b)')['args']);
        $this->assertCount(4, Parser::parse('MAX(1, 2, 3, ROUND(a.b, 2))')['args']);
        $this->assertSame('call', Parser::parse('ROUND(SUM(lines.total) * 0.23, 2)')['args'][0]['left']['type']);
    }

    public function test_every_listed_function_is_present_and_today_is_not(): void
    {
        $this->assertArrayNotHasKey('TODAY', Parser::FUNCTIONS);

        foreach (['IF', 'AND', 'OR', 'NOT', 'SUM', 'COUNT', 'ROUND', 'ABS', 'MIN', 'MAX', 'UPPER', 'LOWER',
            'CONCAT', 'LEFT', 'RIGHT', 'LEN', 'TRIM', 'YEAR', 'MONTH', 'DAY', 'DAYS', 'TEXT'] as $name) {
            $this->assertArrayHasKey($name, Parser::FUNCTIONS);
        }
    }

    /**
     * @dataProvider rejected
     */
    public function test_rejects(string $source, string $reason): void
    {
        try {
            Parser::parse($source);
            $this->fail("Expected FormulaException for: {$source}");
        } catch (FormulaException $e) {
            $this->assertSame($reason, $e->reason, $source);
        }
    }

    public static function rejected(): array
    {
        return [
            'power' => ['a.b ^ 2', 'syntax'],
            'double star' => ['a.b ** 2', 'syntax'],
            'range' => ['1..5', 'syntax'],
            'matches' => ['a.b matches "x"', 'syntax'],
            'twig open' => ['{{ a.b }}', 'syntax'],
            'twig close' => ['a.b }}', 'syntax'],
            'modulo' => ['a.b % 2', 'syntax'],
            'filter' => ['a.b | upper', 'syntax'],
            'bare comma' => ['0,23', 'syntax'],
            'empty' => ['', 'syntax'],
            'blank' => ['   ', 'syntax'],
            'bare identifier' => ['total', 'syntax'],
            'uppercase reference' => ['A.B + 1', 'syntax'],
            'trailing dot' => ['a. + 1', 'syntax'],
            'unterminated string' => ['"abc', 'syntax'],
            'unbalanced paren' => ['(1 + 2', 'syntax'],
            'trailing operator' => ['1 +', 'syntax'],
            'trailing comma' => ['SUM(a.b,)', 'syntax'],
            'number glued to name' => ['12abc', 'syntax'],
            'dotted function' => ['a.b(1)', 'syntax'],
            'unknown function' => ['EVAL(1)', 'unknown_function'],
            'today is closed out' => ['TODAY()', 'unknown_function'],
            'too few args' => ['IF(1,2)', 'arity'],
            'too many args' => ['NOT(1,2)', 'arity'],
            'no args' => ['SUM()', 'arity'],
            'round too many' => ['ROUND(1,2,3)', 'arity'],
            'vertical tab' => ["1\x0B+ 2", 'syntax'],
            'form feed' => ["1\f+ 2", 'syntax'],
            'accented letter' => ['é', 'syntax'],
            'nbsp' => ["1\u{00A0}+ 2", 'syntax'],
            'not equal bang' => ['a.b != 1', 'syntax'],
            'double equals' => ['a.b == 1', 'syntax'],
            'single quotes' => ["'x'", 'syntax'],
            'dollar' => ['$a', 'syntax'],
            'backslash' => ['\\', 'syntax'],
        ];
    }

    /**
     * Tab, CR and LF outside a text literal are whitespace: a user who presses Enter to
     * lay a long formula out must not get a syntax error. Inside a literal they are data.
     */
    public function test_tab_and_line_breaks_are_whitespace_outside_literals(): void
    {
        $expected = Parser::parse('1 + 2');

        $this->assertSame($expected, Parser::parse("1\t+ 2"));
        $this->assertSame($expected, Parser::parse("1\n+ 2"));
        $this->assertSame($expected, Parser::parse("1 +\r\n 2"));
        $this->assertSame($expected, Parser::parse("\n1 + 2\n"));
        $this->assertSame(['type' => 'str', 'value' => "a\nb\tc\r"], Parser::parse("\"a\nb\tc\r\""));
    }

    public function test_normalize_trims_the_source_every_consumer_reads(): void
    {
        $this->assertSame("1 +\n 2", Formula::normalize("\t 1 +\n 2 \r\n"));
        $this->assertSame('', Formula::normalize(" \n\t "));
        // Only ASCII whitespace: a non-breaking space stays, and the parser refuses it.
        $this->assertSame("\u{00A0}1", Formula::normalize("\u{00A0}1"));
    }

    /** Digits beyond PHP_INT_MAX are a float, never silently saturated to PHP_INT_MAX. */
    public function test_integer_literal_overflow_parses_as_float(): void
    {
        $this->assertSame(PHP_INT_MAX, Parser::parse('9223372036854775807')['value']);
        $this->assertSame(9223372036854775808.0, Parser::parse('9223372036854775808')['value']);
        $this->assertSame(7, Parser::parse('007')['value']);
        $this->assertSame(1.0E+20, Formula::evaluate('99999999999999999999 + 1', []));
    }

    public function test_rejects_source_longer_than_1000_characters(): void
    {
        $this->assertSame('num', Parser::parse(str_repeat(' ', 990).'1')['type']);

        try {
            Parser::parse(str_repeat('1+', 600).'1');
            $this->fail('Expected FormulaException');
        } catch (FormulaException $e) {
            $this->assertSame('syntax', $e->reason);
        }
    }

    public function test_references_lists_every_key_read_once_in_order(): void
    {
        $this->assertSame(
            ['totals.total', 'lines.qty', 'customer.name'],
            Formula::references('IF(totals.total > SUM(lines.qty), -totals.total, CONCAT(customer.name, "a.b"))'),
        );
        $this->assertSame([], Formula::references('totals.total +'));
        $this->assertSame([], Formula::references(''));
    }
}
