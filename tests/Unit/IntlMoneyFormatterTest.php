<?php

namespace Controlink\Templates\Tests\Unit;

use Controlink\Templates\Contracts\MoneyFormatter;
use Controlink\Templates\Support\IntlMoneyFormatter;
use Controlink\Templates\Tests\TestCase;

class IntlMoneyFormatterTest extends TestCase
{
    public function test_it_is_the_default_binding(): void
    {
        $this->assertInstanceOf(IntlMoneyFormatter::class, app(MoneyFormatter::class));
    }

    public function test_it_formats_in_the_configured_currency_and_locale(): void
    {
        app()->setLocale('pt_PT');
        config(['templates.money.currency' => 'EUR']);

        $formatted = app(MoneyFormatter::class)->format(1229999.98);

        // ICU: non-breaking spaces as thousands separators, comma as decimal, symbol after.
        $this->assertStringContainsString('1', $formatted);
        $this->assertStringContainsString(',98', $formatted);
        $this->assertStringContainsString('€', $formatted);
    }

    public function test_a_host_binding_wins(): void
    {
        app()->bind(MoneyFormatter::class, fn () => new class implements MoneyFormatter {
            public function format(float|string|null $value): string { return 'HOST '.$value; }
        });

        $this->assertSame('HOST 5', app(MoneyFormatter::class)->format(5));
    }

    public function test_decimals_and_symbol_options(): void
    {
        app()->setLocale('pt_PT');
        config(['templates.money.currency' => 'EUR']);
        $formatter = new IntlMoneyFormatter;
        $plain = fn (string $value): string => str_replace(["\u{202F}", "\u{A0}"], ' ', $value);

        $this->assertSame('1 230 000', $plain($formatter->format(1229999.98, 0, 'none')));
        $this->assertSame('1 229 999,98 EUR', $plain($formatter->format(1229999.98, 2, 'code')));
        $this->assertSame('€ 1 229 999,98', $plain($formatter->format(1229999.98, 2, 'before')));
        $this->assertSame('1 229 999,98 €', $plain($formatter->format(1229999.98, 2, 'after')));
        $this->assertSame($formatter->format(1229999.98), $formatter->format(1229999.98, null, null));
    }

    public function test_non_numeric_values_format_to_an_empty_string(): void
    {
        $this->assertSame('', app(MoneyFormatter::class)->format(null));
        $this->assertSame('', app(MoneyFormatter::class)->format('abc'));
    }
}
