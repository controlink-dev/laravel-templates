<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Formulas\FormulaSet;
use Controlink\Templates\Output\Pdf\DocumentComposer;
use Controlink\Templates\Output\Pdf\PageStructure;
use Controlink\Templates\Rendering\TemplateRenderer;
use Controlink\Templates\Tests\Concerns\BuildsFixtureDataset;
use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Types\TemplateType;

/**
 * Formulas that read a page value (CGE-79), in the composed document.
 *
 * Every other formula is evaluated once over the whole document before the sheets are
 * decided. One that reads `page.*` is evaluated again for each sheet, still over the
 * whole document, with that sheet's page values — which is what lets the bottom total
 * read "carried forward" on a middle page and the document total on the last.
 */
class CarriedTotalsRenderTest extends TestCase
{
    use BuildsFixtureDataset;

    public function test_a_page_formula_switches_between_carried_and_total(): void
    {
        $html = $this->withField('tpl-f-total', 'formula.carry01', 'IF(page.is_last, totais.total_a_pagar, page.carried_out)', 'money');

        $printed = $this->printed($this->compose($html), 'tpl-f-total');
        $pages   = $this->pages($html);

        $this->assertGreaterThan(2, count($pages));
        $this->assertCount(count($pages), $printed);

        foreach (array_slice($pages, 0, -1) as $index => $page) {
            $this->assertSame($this->format($page['dataset'][PageStructure::CARRIED_OUT], 'money'), $printed[$index], "page {$index}");
        }

        $this->assertSame($this->format(1229999.98, 'money'), end($printed));
    }

    public function test_a_page_formula_label_switches(): void
    {
        $html = $this->withField('tpl-f-label', 'formula.label01', 'IF(page.is_last, "TOTAL", "A TRANSPORTAR")');

        $printed = $this->printed($this->compose($html), 'tpl-f-label');
        $last    = array_pop($printed);

        $this->assertSame('TOTAL', $last);
        $this->assertNotEmpty($printed);

        foreach ($printed as $value) {
            $this->assertSame('A TRANSPORTAR', $value);
        }
    }

    public function test_sum_in_a_page_formula_is_still_the_whole_document(): void
    {
        $html = $this->withField('tpl-f-sum', 'formula.sum001', 'IF(page.is_last, SUM(linhas.valor), SUM(linhas.valor))', 'money');

        $printed = $this->printed($this->compose($html), 'tpl-f-sum');

        $this->assertGreaterThan(2, count($printed));

        foreach ($printed as $value) {
            $this->assertSame($this->format(1200.0, 'money'), $value);
        }
    }

    public function test_formulas_without_page_keys_are_evaluated_once(): void
    {
        $html = $this->withField('tpl-f-plain', 'formula.plain1', 'totais.total_a_pagar * 2', 'money');
        $html = $this->withField('tpl-f-page', 'formula.page01', 'page.carried_in', 'money', $html);

        $this->assertSame(['formula.page01'], FormulaSet::fromHtml($html)->pageFields());

        $printed = $this->printed($this->compose($html), 'tpl-f-plain');

        // Kept on the middle pages? No: a field that reads no page value prints empty there.
        $this->assertSame($this->format(1229999.98 * 2, 'money'), end($printed));
        $this->assertSame('', $printed[0]);
    }

    /** The test invoice, carrying the line value across pages. */
    private function type(): TemplateType
    {
        $config = (require __DIR__.'/../Fixtures/types/invoice.php')['invoice'];

        $config['pdf']['carry'] = ['collection' => 'linhas', 'column' => 'valor'];

        return TemplateType::fromConfig('invoice', $config);
    }

    /** @return array<string, mixed> the fixture with 120 lines worth 10 each */
    private function dataset(): array
    {
        $dataset = $this->fixtureDataset($this->type(), 1);

        $dataset['linhas'] = array_fill(0, 120, [
            'codigo' => 'A', 'descricao' => 'x', 'quantidade' => 1, 'unidade' => 'UN',
            'preco_unitario' => 10, 'taxa_iva' => '23%', 'valor' => 10,
        ]);

        return $dataset;
    }

    /** A formula data field, as the editor exports it, at the top of the body. */
    private function withField(string $id, string $binding, string $source, string $format = '', ?string $html = null): string
    {
        $html     ??= $this->type()->initialLayout['html'];
        $expression = $format !== '' ? '{{ '.$binding.'|'.$format.' }}' : '{{ '.$binding.' }}';
        $field      = '<div class="block block--field" data-tpl-type="data-field"'
            .' data-tpl-binding="'.$binding.'"'
            .' data-tpl-format="'.$format.'"'
            .' data-tpl-formula="'.htmlspecialchars($source, ENT_QUOTES).'"'
            .' id="'.$id.'">'.$expression.'</div>';

        $opening = '<div class="zone zone--body" data-tpl-zone="body">';
        $this->assertStringContainsString($opening, $html);

        return str_replace($opening, $opening.$field, $html);
    }

    private function compose(string $html): string
    {
        $type = $this->type();

        return app(DocumentComposer::class)->compose($html, $type->initialLayout['css'], $type, $this->dataset());
    }

    /** @return array<int, array{template: string, dataset: array<string, mixed>}> */
    private function pages(string $html): array
    {
        $type = $this->type();

        return (new PageStructure)->build(FormulaSet::fromHtml($html)->strippedHtml(), $type, $this->dataset(), $type->initialLayout['css'])['pages'];
    }

    /** @return list<string> the printed content of every copy of a block, one per sheet */
    private function printed(string $document, string $id): array
    {
        preg_match_all('/id="'.preg_quote($id, '/').'"[^>]*>(.*?)<\/div>/s', $document, $matches);

        return $matches[1];
    }

    private function format(mixed $value, string $format): string
    {
        return htmlspecialchars(app(TemplateRenderer::class)->formatValue($value, $format), ENT_QUOTES | ENT_SUBSTITUTE);
    }
}
