<?php

namespace Controlink\Templates\Tests\Unit;

use Controlink\Templates\Formulas\Formula;
use Controlink\Templates\Formulas\FormulaException;
use Controlink\Templates\Rendering\TemplateRenderer;
use Controlink\Templates\Tests\TestCase;

class FormulaEvaluatorTest extends TestCase
{
    private function lines(): array
    {
        return [
            'lines' => [
                ['quantity' => 2, 'unit_price' => '1.5', 'total' => 10],
                ['quantity' => 1, 'unit_price' => 4, 'total' => ''],
                ['quantity' => 3, 'unit_price' => 2, 'total' => '2.5'],
            ],
        ];
    }

    public function test_if_only_evaluates_the_chosen_branch(): void
    {
        $f = 'IF(q.qty = 0, 0, q.total / q.qty)';

        $this->assertSame(0, Formula::evaluate($f, ['q.qty' => 0, 'q.total' => 10]));
        $this->assertSame(2.5, Formula::evaluate($f, ['q.qty' => 4, 'q.total' => 10]));
    }

    public function test_division_by_zero_gives_empty_string(): void
    {
        $this->assertSame('', Formula::evaluate('q.total / q.qty', ['q.qty' => 0, 'q.total' => 10]));
    }

    public function test_sum_treats_blank_cells_as_zero_and_count_counts_rows(): void
    {
        $this->assertSame(12.5, Formula::evaluate('SUM(lines.total)', $this->lines()));
        $this->assertSame(3, Formula::evaluate('COUNT(lines.total)', $this->lines()));
    }

    public function test_round_and_text_functions(): void
    {
        $this->assertSame(3.33, Formula::evaluate('ROUND(10 / 3, 2)', []));
        $this->assertSame(3.0, Formula::evaluate('ROUND(2.5)', []));
        $this->assertSame(-3.0, Formula::evaluate('ROUND(-2.5)', []));
        $this->assertSame('ACME - x', Formula::evaluate('UPPER(c.name) & " - " & LOWER("X")', ['c.name' => 'acme']));
        $this->assertSame('çã', Formula::evaluate('LEFT(c.name, 2)', ['c.name' => 'çãoXY']));
        $this->assertSame('XY', Formula::evaluate('RIGHT(c.name, 2)', ['c.name' => 'çãoXY']));
        $this->assertSame('ç', Formula::evaluate('LEFT(c.name)', ['c.name' => 'çãoXY']));
        $this->assertSame(5, Formula::evaluate('LEN(c.name)', ['c.name' => 'çãoXY']));
        $this->assertSame('a b', Formula::evaluate('TRIM(c.name)', ['c.name' => "  a b \u{00A0}"]));
    }

    public function test_concatenation_drops_trailing_zero_of_numbers(): void
    {
        $this->assertSame('3', Formula::evaluate('"" & 6 / 2', []));
        $this->assertSame('x2.5', Formula::evaluate('CONCAT("x", 5 / 2)', []));
    }

    public function test_dates(): void
    {
        $data = ['d.due' => '2026-10-31', 'd.date' => '2026-10-01'];

        $this->assertSame(30, Formula::evaluate('DAYS(d.due, d.date)', $data));
        $this->assertSame(-30, Formula::evaluate('DAYS(d.date, d.due)', $data));
        $this->assertSame(2026, Formula::evaluate('YEAR(d.date)', $data));
        $this->assertSame(10, Formula::evaluate('MONTH(d.date)', $data));
        $this->assertSame(1, Formula::evaluate('DAY(d.date)', $data));
        // Relative words would make a reprint differ: only real dates are accepted.
        $this->assertSame('', Formula::evaluate('YEAR(d.x)', ['d.x' => 'tomorrow']));
        $this->assertSame('', Formula::evaluate('YEAR(d.x)', ['d.x' => '']));
    }

    public function test_dates_must_be_whole_real_calendar_dates(): void
    {
        // The prefix alone is not enough: trailing text is not a date.
        $this->assertSame('', Formula::evaluate('YEAR(d.x)', ['d.x' => '2026-10-01abc']));
        $this->assertSame('', Formula::evaluate('YEAR(d.x)', ['d.x' => '2026-10-01 tomorrow']));
        // Carbon would roll an impossible day over into the next month.
        $this->assertSame('', Formula::evaluate('YEAR(d.x)', ['d.x' => '2026-02-30']));
        $this->assertSame('', Formula::evaluate('MONTH(d.x)', ['d.x' => '2026-13-01']));
        // Real date-times in the usual shapes still read.
        $this->assertSame(2024, Formula::evaluate('YEAR(d.x)', ['d.x' => '2024-02-29']));
        $this->assertSame(10, Formula::evaluate('MONTH(d.x)', ['d.x' => '2026-10-01 12:30']));
        $this->assertSame(1, Formula::evaluate('DAY(d.x)', ['d.x' => '2026-10-01T12:30:45.123Z']));
        $this->assertSame(1, Formula::evaluate('DAY(d.x)', ['d.x' => '2026-10-01T12:30:45+01:00']));
    }

    public function test_numeric_equality_tolerates_float_noise(): void
    {
        $this->assertSame('TRUE', Formula::evaluate('0.1 + 0.2 = 0.3', []));
        $this->assertSame('FALSE', Formula::evaluate('0.1 + 0.2 <> 0.3', []));
        $this->assertSame('TRUE', Formula::evaluate('0.1 + 0.2 <= 0.3', []));
        $this->assertSame('FALSE', Formula::evaluate('0.1 + 0.2 > 0.3', []));
        // Real differences are still differences.
        $this->assertSame('FALSE', Formula::evaluate('0.3 = 0.30001', []));
        // A cent on ten million is a real difference, not noise.
        $this->assertSame('FALSE', Formula::evaluate('10000000 = 10000000.01', []));
        $this->assertSame('TRUE', Formula::evaluate('10000000 < 10000000.01', []));
        $this->assertSame('TRUE', Formula::evaluate('a.b = 1229999.98', ['a.b' => 999999.99 + 229999.99]));
    }

    public function test_check_reports_any_failure_while_walking_as_syntax(): void
    {
        // A malformed catalogue (columns not a list) must not escape as a TypeError.
        $error = Formula::check('SUM(lines.qty)', [], ['lines' => 'not-a-list']);

        $this->assertInstanceOf(FormulaException::class, $error);
        $this->assertSame('syntax', $error->reason);
    }

    public function test_bad_types_and_missing_references_give_empty_string(): void
    {
        $this->assertSame('', Formula::evaluate('"abc" * 2', []));
        $this->assertSame('', Formula::evaluate('a.missing', []));
        // A missing value is blank, and blank is 0 in arithmetic.
        $this->assertSame(1, Formula::evaluate('a.missing + 1', []));
        $this->assertSame('', Formula::evaluate('0,23', []));
        $this->assertSame('', Formula::evaluate('{{ 7*7 }}', []));
        $this->assertSame('', Formula::evaluate('a.b', ['a.b' => ['nested']]));
    }

    public function test_coercion(): void
    {
        $this->assertSame(1.5, Formula::evaluate('a.b + 1', ['a.b' => '0.5']));
        $this->assertSame(1, Formula::evaluate('a.b + 1', ['a.b' => '']));
        $this->assertSame(1, Formula::evaluate('a.b + 1', ['a.b' => null]));
        $this->assertSame(-3, Formula::evaluate('-a.b', ['a.b' => 3]));
    }

    public function test_comparisons(): void
    {
        $this->assertSame('TRUE', Formula::evaluate('a.b = 12', ['a.b' => '12.0']));
        $this->assertSame('TRUE', Formula::evaluate('a.b < 10', ['a.b' => '9']));
        $this->assertSame('FALSE', Formula::evaluate('"a" = "A"', []));
        $this->assertSame('TRUE', Formula::evaluate('"a" <> "A"', []));
        $this->assertSame('TRUE', Formula::evaluate('"abc" < "abd"', []));
        $this->assertSame('TRUE', Formula::evaluate('a.b = 0', ['a.b' => '']));
        $this->assertSame('TRUE', Formula::evaluate('a.b = ""', ['a.b' => null]));
    }

    public function test_logic_functions_short_circuit(): void
    {
        $this->assertSame('FALSE', Formula::evaluate('AND(FALSE, 1 / 0)', []));
        $this->assertSame('TRUE', Formula::evaluate('OR(TRUE, 1 / 0)', []));
        $this->assertSame('TRUE', Formula::evaluate('NOT(a.b > 1)', ['a.b' => 0]));
        $this->assertSame('yes', Formula::evaluate('IF(AND(a.b > 1, a.b < 5), "yes", "no")', ['a.b' => 3]));
        $this->assertSame('', Formula::evaluate('IF("maybe", 1, 2)', []));
    }

    public function test_min_max_abs(): void
    {
        $this->assertSame(1, Formula::evaluate('MIN(3, 1, "2")', []));
        $this->assertSame(3, Formula::evaluate('MAX(3, 1, "2")', []));
        $this->assertSame(4, Formula::evaluate('ABS(-4)', []));
    }

    public function test_row_scope_reads_current_row_and_sum_reads_whole_collection(): void
    {
        $row = ['quantity' => 2, 'unit_price' => '1.5'];

        $this->assertSame(3.0, Formula::evaluate('lines.quantity * lines.unit_price', $this->lines(), $row, 'lines'));
        $this->assertSame(6, Formula::evaluate('SUM(lines.quantity)', $this->lines(), $row, 'lines'));
        $this->assertSame(
            '2/6',
            Formula::evaluate('lines.quantity & "/" & SUM(lines.quantity)', $this->lines(), $row, 'lines')
        );
    }

    public function test_dotted_column_keys(): void
    {
        $data = ['lines' => [['entity.price' => 2], ['entity.price' => 3]]];

        $this->assertSame(5, Formula::evaluate('SUM(lines.entity.price)', $data));
    }

    public function test_evaluate_never_throws(): void
    {
        $this->assertSame('', Formula::evaluate('SUM(', []));
        $this->assertSame('', Formula::evaluate('SUM(a.b)', ['a.b' => 1]));
        $this->assertSame('', Formula::evaluate('SUM(1 + 2)', []));
        $this->assertSame('', Formula::evaluate('SUM(lines.x)', ['lines' => 'not-a-list']));
        $this->assertSame('', Formula::evaluate(str_repeat('(', 5000), []));
        $this->assertSame('', Formula::evaluate("\xFF\xFE", []));
    }

    public function test_text_formats_through_the_injected_formatter(): void
    {
        $calls = [];
        $fake = function (mixed $value, string $format, array $args) use (&$calls): string {
            $calls[] = [$value, $format, $args];

            return "<{$format}:{$value}>";
        };
        $data = ['d.date' => '2026-10-01'];

        $this->assertSame(
            'Os artigos foram colocados à disposição em <date:2026-10-01>',
            Formula::evaluate('"Os artigos foram colocados à disposição em " & TEXT(d.date, "date")', $data, null, null, $fake)
        );
        $this->assertSame('<number:2.5>', Formula::evaluate('TEXT(5 / 2, "number")', [], null, null, $fake));
        $this->assertSame([2.5, 'number', []], $calls[1]);

        Formula::evaluate('TEXT(d.date, "date", "d MMM")', $data, null, null, $fake);
        $this->assertSame(['2026-10-01', 'date', ['d MMM']], $calls[2]);

        // Unknown format or a failing formatter: empty, never an exception.
        $this->assertSame('', Formula::evaluate('TEXT(1, "upper")', [], null, null, $fake));
        $this->assertSame('', Formula::evaluate('TEXT(1, a.fmt)', ['a.fmt' => 'date'], null, null, $fake));
        $this->assertSame('', Formula::evaluate('TEXT(1, "money")', [], null, null, function () {
            throw new \RuntimeException('boom');
        }));
    }

    public function test_text_through_the_real_renderer(): void
    {
        $this->assertSame(app(TemplateRenderer::class)->formatValue(2.5, 'number'), Formula::evaluate('TEXT(5 / 2, "number")', []));
        $this->assertNotSame('', Formula::evaluate('TEXT(d.date, "date")', ['d.date' => '2026-10-01']));
        $this->assertSame('2026-10-01', Formula::evaluate('TEXT(d.date, "date", "yyyy-MM-dd")', ['d.date' => '2026-10-01']));
    }

    public function test_check_validates_text_format_literal(): void
    {
        $this->assertNull($this->check('TEXT(customer.name, "date")'));
        $this->assertNull($this->check('TEXT(totals.total_due, "money", 2)'));
        $this->assertSame('syntax', $this->check('TEXT(customer.name, "upper")')->reason);
        $this->assertSame('syntax', $this->check('TEXT(customer.name, "")')->reason);
    }

    /** A literal third argument is held to FormatSpec's lists: decimals for money/number, a pattern for date. */
    public function test_check_validates_a_literal_text_argument(): void
    {
        foreach ([
            'TEXT(totals.total_due, "money", 0)',
            'TEXT(totals.total_due, "money", 4)',
            'TEXT(totals.total_due, "number", "2")',
            'TEXT(customer.name, "date", "dd/MM/yyyy")',
            'TEXT(customer.name, "date", "dd MMM yyyy")',
            // Not a literal: left to the formatter at print time, as before.
            'TEXT(totals.total_due, "money", totals.total_due)',
        ] as $valid) {
            $this->assertNull($this->check($valid), $valid);
        }

        foreach ([
            'TEXT(totals.total_due, "money", 5)',
            'TEXT(totals.total_due, "money", 1.5)',
            'TEXT(totals.total_due, "money", -1)',
            'TEXT(totals.total_due, "number", "two")',
            'TEXT(totals.total_due, "number", TRUE)',
            'TEXT(customer.name, "date", "d MMM")',
            'TEXT(customer.name, "date", 2)',
            'TEXT(customer.name, "date", "")',
        ] as $invalid) {
            $this->assertSame('syntax', $this->check($invalid)?->reason, $invalid);
        }
    }

    public function test_functions_lists_names_for_autocomplete(): void
    {
        $names = Formula::functions();

        $this->assertContains('SUM', $names);
        $this->assertContains('DAYS', $names);
        $this->assertNotContains('TODAY', $names);
    }

    private function check(string $source, ?string $rowCollection = null): ?FormulaException
    {
        return Formula::check(
            $source,
            ['customer.name', 'totals.total_due'],
            ['lines' => ['quantity', 'total', 'entity.name'], 'items' => ['qty']],
            $rowCollection
        );
    }

    public function test_check_accepts_valid_formulas(): void
    {
        $this->assertNull($this->check('SUM(lines.total)'));
        $this->assertNull($this->check('COUNT(lines.total) + totals.total_due'));
        $this->assertNull($this->check('SUM(lines.entity.name)'));
        $this->assertNull($this->check('lines.quantity * 2', 'lines'));
        $this->assertNull($this->check('lines.quantity * SUM(lines.total)', 'lines'));
        $this->assertNull($this->check('UPPER(customer.name)'));
    }

    /**
     * @dataProvider invalid
     */
    public function test_check_rejects(string $source, ?string $rowCollection, string $reason): void
    {
        $error = $this->check($source, $rowCollection);

        $this->assertInstanceOf(FormulaException::class, $error, $source);
        $this->assertSame($reason, $error->reason, $source);
    }

    public static function invalid(): array
    {
        return [
            'unknown key' => ['x.unknown', null, 'unknown_reference'],
            'formula chaining' => ['formula.f1', null, 'unknown_reference'],
            'unknown key inside call' => ['UPPER(x.unknown)', null, 'unknown_reference'],
            'unknown column' => ['SUM(lines.nope)', null, 'unknown_reference'],
            'unknown column row scope' => ['lines.nope', 'lines', 'unknown_reference'],
            'collection column in single field' => ['lines.total', null, 'scope'],
            'collection column nested in arithmetic' => ['lines.total + 1', null, 'scope'],
            'other collection in row scope' => ['items.qty', 'lines', 'scope'],
            'sum of non reference' => ['SUM(1 + 2)', null, 'scope'],
            'sum of singular reference' => ['SUM(customer.name)', null, 'scope'],
            'count of literal' => ['COUNT("x")', null, 'scope'],
            'syntax' => ['1 +', null, 'syntax'],
            'unknown function' => ['EVAL(1)', null, 'unknown_function'],
            'arity' => ['IF(1,2)', null, 'arity'],
        ];
    }
}
