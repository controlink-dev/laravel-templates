<?php

namespace Controlink\Templates\Tests\Unit;

use Controlink\Templates\Rendering\FilterRegistry;
use Controlink\Templates\Support\DocumentNumbers;
use Controlink\Templates\Support\IntlMoneyFormatter;
use Controlink\Templates\Tests\TestCase;

/**
 * CGE-75: numbers and money in one document share its separators, whatever language the
 * user reading the editor has. `templates.numbers` names them; unset, ICU's for the app
 * locale, which is what every version rendered before.
 */
class DocumentNumbersTest extends TestCase
{
    private function portugueseDocuments(): void
    {
        config(['templates.numbers' => ['locale' => 'pt_PT', 'thousands' => '.', 'decimal' => ',']]);
        config(['templates.money.currency' => 'EUR']);
    }

    public function test_number_and_money_share_the_document_separators_in_an_english_session(): void
    {
        $this->portugueseDocuments();
        app()->setLocale('en');
        $filters = app(FilterRegistry::class)->filters();

        $this->assertSame('1.234,568', $filters['number'](1234.5678, 3));
        $this->assertSame('1.234,568', $filters['money'](1234.5678, 3, 'none'));
    }

    public function test_the_session_language_does_not_change_a_document(): void
    {
        $this->portugueseDocuments();
        $filters = app(FilterRegistry::class)->filters();

        app()->setLocale('en');
        $english = [$filters['number'](1234567.8, 2), $filters['money'](1234567.8)];

        app()->setLocale('pt_PT');
        $portuguese = [$filters['number'](1234567.8, 2), $filters['money'](1234567.8)];

        $this->assertSame($english, $portuguese);
        $this->assertSame('1.234.567,80', $english[0]);
    }

    public function test_money_keeps_the_symbol_with_the_document_separators(): void
    {
        $this->portugueseDocuments();
        app()->setLocale('en');
        $formatter = new IntlMoneyFormatter;
        $plain = fn (string $value): string => str_replace(["\u{202F}", "\u{A0}"], ' ', $value);

        $this->assertSame('1.234,56 €', $plain($formatter->format(1234.56)));
        $this->assertSame('€ 1.234,56', $plain($formatter->format(1234.56, 2, 'before')));
        $this->assertSame('1.234,56 EUR', $plain($formatter->format(1234.56, 2, 'code')));
    }

    public function test_unset_it_is_icu_for_the_app_locale(): void
    {
        config(['templates.numbers' => []]);
        app()->setLocale('en');

        $this->assertSame('1,234.57', DocumentNumbers::decimal(2)->format(1234.567));
    }
}
