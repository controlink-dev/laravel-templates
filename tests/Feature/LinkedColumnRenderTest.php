<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Formulas\Formula;
use Controlink\Templates\Rendering\FilterRegistry;
use Controlink\Templates\Rendering\TemplateRenderer;
use Controlink\Templates\Tests\TestCase;

/**
 * Linked columns (CGE-85): the host sends them as flat dotted keys on each row, the
 * editor emits nested lookups, and Twig runs under strict_variables.
 */
class LinkedColumnRenderTest extends TestCase
{
    private const TEMPLATE = '{% for linha in articles %}{{ linha.family.name }}{% endfor %}';

    private function renderer(): TemplateRenderer
    {
        return new TemplateRenderer(app(FilterRegistry::class));
    }

    public function test_row_dotted_key_renders_nested_under_strict_variables(): void
    {
        $out = $this->renderer()->render(self::TEMPLATE, ['articles' => [['family.name' => 'Fixação']]]);

        $this->assertSame('Fixação', $out);
    }

    public function test_row_without_value_renders_empty_not_error(): void
    {
        $out = $this->renderer()->render(self::TEMPLATE, ['articles' => [['family.name' => '']]]);

        $this->assertSame('', $out);
    }

    public function test_flat_rows_without_dots_render_unchanged(): void
    {
        $out = $this->renderer()->render(
            '{% for linha in articles %}{{ linha.code }};{% endfor %}',
            ['articles' => [['code' => 'A1'], ['code' => 'B2']]],
        );

        $this->assertSame('A1;B2;', $out);
    }

    public function test_scalar_first_segment_is_not_nested_and_flat_key_stays(): void
    {
        $out = $this->renderer()->render(
            '{% for linha in articles %}{{ linha.family }}|{{ attribute(linha, "family.name") }}{% endfor %}',
            ['articles' => [['family' => 'F1', 'family.name' => 'Fixação']]],
        );

        $this->assertSame('F1|Fixação', $out);
    }

    public function test_formula_reads_linked_column_flat(): void
    {
        $dataset = ['articles' => [['unit.decimals' => 3], ['unit.decimals' => 5]]];

        $this->assertSame(3, Formula::evaluate('articles.unit.decimals', $dataset, $dataset['articles'][0], 'articles'));
        $this->assertSame(8, Formula::evaluate('SUM(articles.unit.decimals)', $dataset, $dataset['articles'][0], 'articles'));
    }
}
