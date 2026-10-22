<?php

namespace Controlink\Templates\Tests\Unit;

use Controlink\Templates\Contracts\MoneyFormatter;
use Controlink\Templates\Rendering\FilterRegistry;
use Controlink\Templates\Rendering\TemplateRenderer;
use Carbon\CarbonImmutable;
use Controlink\Templates\Tests\TestCase;
use NumberFormatter;
use RuntimeException;

class TemplateRendererTest extends TestCase
{
    public function test_it_renders_dotted_keys_as_nested_lookups_and_escapes_html(): void
    {
        $out = app(TemplateRenderer::class)->render('<p>{{ emitente.nome }}</p>', ['emitente.nome' => 'A & B <x>']);

        $this->assertSame('<p>A &amp; B &lt;x&gt;</p>', $out);
    }

    public function test_the_four_default_formats_work(): void
    {
        app()->setLocale('pt_PT');
        $renderer = app(TemplateRenderer::class);

        $this->assertSame('ABC', $renderer->formatValue('abc', 'upper'));
        $this->assertStringContainsString('31/12/2026', $renderer->formatValue('2026-12-31', 'date'));
        $this->assertStringContainsString('1234', str_replace(["\u{202F}", "\u{A0}", ' ', '.'], '', $renderer->formatValue(1234.5, 'number')));
        $this->assertStringContainsString('€', $renderer->formatValue(10, 'money'));
    }

    public function test_filters_without_arguments_keep_todays_output(): void
    {
        app()->setLocale('pt_PT');
        $renderer = app(TemplateRenderer::class);

        $this->assertSame(
            CarbonImmutable::parse('2026-12-31')->locale('pt_PT')->isoFormat('L'),
            $renderer->render('{{ v|date }}', ['v' => '2026-12-31']),
        );
        $this->assertSame(
            (string) (new NumberFormatter('pt_PT', NumberFormatter::DECIMAL))->format(1229999.98),
            html_entity_decode($renderer->render('{{ v|number }}', ['v' => 1229999.98])),
        );
        $this->assertSame(
            app(MoneyFormatter::class)->format(1229999.98),
            html_entity_decode($renderer->render('{{ v|money }}', ['v' => 1229999.98])),
        );
    }

    public function test_date_pattern_argument(): void
    {
        app()->setLocale('pt_PT');
        $renderer = app(TemplateRenderer::class);

        $this->assertSame('31/12/2026', $renderer->formatValue('2026-12-31', 'date', ['dd/MM/yyyy']));
        $this->assertSame('2026-12-31', $renderer->formatValue('2026-12-31', 'date', ['yyyy-MM-dd']));
        $this->assertSame('2026/12/31', $renderer->formatValue('2026-12-31', 'date', ['yyyy/MM/dd']));
        $this->assertSame('31-12-2026', $renderer->formatValue('2026-12-31', 'date', ['dd-MM-yyyy']));
        $this->assertSame(
            CarbonImmutable::parse('2026-12-31')->locale('pt_PT')->isoFormat('DD MMM YYYY'),
            $renderer->formatValue('2026-12-31', 'date', ['dd MMM yyyy']),
        );
        $this->assertSame('31/12/2026', $renderer->render("{{ v|date('dd/MM/yyyy') }}", ['v' => '2026-12-31']));
    }

    public function test_number_decimals_argument(): void
    {
        app()->setLocale('pt_PT');
        $renderer = app(TemplateRenderer::class);

        $this->assertSame('3', $renderer->formatValue(2.5, 'number', [0]));
        $this->assertSame('2,5000', $renderer->formatValue(2.5, 'number', [4]));
    }

    public function test_filter_rejects_out_of_list_argument(): void
    {
        $this->expectException(RuntimeException::class);

        app(TemplateRenderer::class)->render("{{ v|date('Y') }}", ['v' => '2026-12-31']);
    }

    public function test_money_goes_through_the_bound_formatter(): void
    {
        app()->bind(MoneyFormatter::class, fn () => new class implements MoneyFormatter {
            public function format(float|string|null $value): string { return 'X'.$value; }
        });

        $this->assertSame('X10', app(TemplateRenderer::class)->render('{{ v|money }}', ['v' => 10]));
    }

    public function test_a_filter_outside_the_list_is_a_sandbox_violation(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sandbox');

        app(TemplateRenderer::class)->render('{{ v|raw }}', ['v' => 'x']);
    }

    public function test_the_if_tag_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        app(TemplateRenderer::class)->render('{% if v %}x{% endif %}', ['v' => 1]);
    }

    public function test_a_missing_variable_is_an_error_not_a_blank(): void
    {
        $this->expectException(RuntimeException::class);

        app(TemplateRenderer::class)->render('{{ missing }}', []);
    }

    public function test_the_host_can_register_a_format(): void
    {
        config(['templates.formats' => ['date', 'number', 'money', 'upper', 'shout']]);
        app(FilterRegistry::class)->register('shout', fn ($v) => mb_strtoupper((string) $v).'!');

        $this->assertSame('HI!', app(TemplateRenderer::class)->render('{{ v|shout }}', ['v' => 'hi']));
    }

    public function test_autoescape_can_be_turned_off_for_plain_text_outputs(): void
    {
        $renderer = new TemplateRenderer(app(FilterRegistry::class), autoescape: 'false');

        $this->assertSame('A & B', $renderer->render('{{ v }}', ['v' => 'A & B']));
    }
}
