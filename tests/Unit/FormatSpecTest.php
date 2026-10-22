<?php

namespace Controlink\Templates\Tests\Unit;

use Controlink\Templates\Rendering\FormatSpec;
use Controlink\Templates\Tests\TestCase;
use InvalidArgumentException;

class FormatSpecTest extends TestCase
{
    public function test_expressions_match_golden_fixture(): void
    {
        $cases = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/format-expressions.json'), true);

        foreach ($cases as $case) {
            $spec = FormatSpec::fromAttributes($case['attributes']);

            $this->assertSame($case['expression'], $spec?->expression('x') ?? '{{ x }}', (string) json_encode($case['attributes']));
        }
    }

    public function test_a_column_reads_format_and_format_args(): void
    {
        $spec = FormatSpec::fromColumn(['format' => 'money', 'formatArgs' => ['decimals' => 2, 'symbol' => 'code']]);

        $this->assertSame("{{ linha.total|money(2, 'code') }}", $spec?->expression('linha.total'));
        $this->assertNull(FormatSpec::fromColumn(['format' => '']));
    }

    public function test_expressions_read_back_as_their_spec(): void
    {
        $cases = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/format-expressions.json'), true);

        foreach ($cases as $case) {
            $spec = FormatSpec::fromExpression($case['expression']);

            $this->assertSame($case['expression'], $spec?->expression('x') ?? '{{ x }}');
        }
    }

    public function test_a_non_canonical_expression_is_refused(): void
    {
        foreach (["{{ x|money(2,'code') }}", "{{ x|date(\"dd/MM/yyyy\") }}", '{{ x|number(1, 2) }}', '{{ x|date(foo) }}'] as $expression) {
            try {
                FormatSpec::fromExpression($expression);
                $this->fail("Accepted {$expression}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_rejects_unknown_pattern(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FormatSpec::fromAttributes(['data-tpl-format' => 'date', 'data-tpl-format-pattern' => 'Y']);
    }

    public function test_rejects_decimals_above_four(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FormatSpec::fromAttributes(['data-tpl-format' => 'number', 'data-tpl-format-decimals' => '5']);
    }

    public function test_rejects_unknown_symbol(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FormatSpec::fromAttributes(['data-tpl-format' => 'money', 'data-tpl-format-symbol' => 'left']);
    }

    public function test_rejects_arguments_on_a_format_that_takes_none(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FormatSpec::fromAttributes(['data-tpl-format' => 'upper', 'data-tpl-format-pattern' => 'dd/MM/yyyy']);
    }
}
