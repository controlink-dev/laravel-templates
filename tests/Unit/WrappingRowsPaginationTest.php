<?php

namespace Controlink\Templates\Tests\Unit;

use Controlink\Templates\Output\Pdf\PageStructure;
use Controlink\Templates\Templates;
use Controlink\Templates\Tests\Concerns\BuildsFixtureDataset;
use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Types\TemplateType;

/**
 * A line table whose text columns wrap paginates by the sum of its row heights (CGE-89).
 *
 * The first guard is a golden captured before the feature: with every description short
 * enough for its column, `build()` is byte-for-byte what it always was. The rest is the
 * new behaviour: tall rows close the page earlier, a tall row that no longer fits moves
 * whole to the next page, and the CSS lets only the text columns wrap.
 */
class WrappingRowsPaginationTest extends TestCase
{
    use BuildsFixtureDataset;

    public function test_short_rows_paginate_exactly_as_before(): void
    {
        $path = $this->shortGoldenPath();

        $this->assertFileExists($path, 'The short-rows golden must be captured before the engine is edited.');

        /** @var array<string, mixed> $golden */
        $golden = unserialize((string) file_get_contents($path), ['allowed_classes' => false]);

        foreach ($this->shortRowScenarios() as $name => $scenario) {
            $this->assertSame(
                $golden[$name],
                (new PageStructure)->build($scenario['editorHtml'], $scenario['type'], $scenario['dataset']),
                "scenario '{$name}' drifted from the golden",
            );
        }
    }

    public function test_the_fixture_descriptions_wrap(): void
    {
        // Why the retrocompat golden moved with CGE-89: the fixture's ~75-character
        // descriptions in a 62 mm column, at the default 12 pt, take more than one line.
        $invoice = Templates::type('invoice');
        $result  = (new PageStructure)->build($invoice->initialLayout['html'], $invoice, $this->fixtureDataset($invoice));

        $this->assertStringContainsString('#tpl-linhas td:nth-child(2){white-space:pre-line', $result['css']);
    }

    public function test_tall_rows_close_the_page_earlier(): void
    {
        $type = Templates::type('invoice');
        $rows = [];

        for ($i = 1; $i <= 60; $i++) {
            $rows[] = ['c' => $i % 5 === 0 ? $this->linesOfText(4) : 'curta'];
        }

        $pages = (new PageStructure)->build($this->body([['a', 0, false]]), $type, ['a' => $rows])['pages'];

        $structure = new PageStructure;
        $this->assertLessThan($structure->rowsPerPage($type), count($pages[0]['dataset']['a']));

        // Every row printed once, in order.
        $printed = array_merge(...array_map(fn (array $page): array => $page['dataset']['a'], $pages));
        $this->assertSame($rows, $printed);
    }

    public function test_a_tall_row_that_does_not_fit_moves_to_the_next_page(): void
    {
        $type     = Templates::type('invoice');
        $capacity = (new PageStructure)->rowsPerPage($type);

        // One row short of a full page, then a row five lines tall.
        $rows   = array_fill(0, $capacity - 1, ['c' => 'curta']);
        $rows[] = ['c' => $this->linesOfText(5)];

        $pages = (new PageStructure)->build($this->body([['a', 0, false]]), $type, ['a' => $rows])['pages'];

        $this->assertCount(2, $pages);
        $this->assertCount($capacity - 1, $pages[0]['dataset']['a']);
        $this->assertSame([$rows[$capacity - 1]], $pages[1]['dataset']['a']);
    }

    public function test_a_row_taller_than_the_page_is_placed_alone(): void
    {
        $type = Templates::type('invoice');
        $rows = [['c' => 'curta'], ['c' => str_repeat('palavra ', 2000)], ['c' => 'curta']];

        $pages = (new PageStructure)->build($this->body([['a', 0, false]]), $type, ['a' => $rows])['pages'];

        $this->assertSame([[$rows[0]], [$rows[1]], [$rows[2]]], array_map(fn (array $page): array => $page['dataset']['a'], $pages));
    }

    public function test_the_css_lets_only_text_columns_wrap(): void
    {
        $invoice = Templates::type('invoice');
        $css     = (new PageStructure)->build($invoice->initialLayout['html'], $invoice, $this->fixtureDataset($invoice))['css'];

        // codigo, descricao, unidade, taxa_iva are text; quantidade, preço, valor are formatted.
        foreach ([1, 2, 4, 6] as $text) {
            $this->assertStringContainsString("#tpl-linhas td:nth-child({$text}){white-space:pre-line;overflow-wrap:anywhere;text-overflow:clip;line-height:1.2}", $css);
        }

        foreach ([3, 5, 7] as $formatted) {
            $this->assertStringNotContainsString("td:nth-child({$formatted})", $css);
        }
    }

    public function test_a_fixed_last_page_table_that_grows_falls_back_to_the_flow(): void
    {
        $type = Templates::type('invoice');
        $top  = (int) ceil($this->reserveTop($type));

        // Two short rows fit the reserve; one of them eight lines tall does not.
        $body = $this->body([['a', 0, false], ['b', $top, true]]);

        $fits = (new PageStructure)->build($body, $type, ['a' => [['c' => 'x']], 'b' => [['c' => 'y'], ['c' => 'z']]]);
        $this->assertStringContainsString('top:'.$top.'mm', end($fits['pages'])['template']);

        // 8 lines: header 4.5 + 4.5 + (4.5 + 7 × 3.39) mm is past the 32 mm reserve.
        $grows = (new PageStructure)->build($body, $type, ['a' => [['c' => 'x']], 'b' => [['c' => 'y'], ['c' => $this->linesOfText(8)]]]);
        $this->assertStringNotContainsString('top:'.$top.'mm', end($grows['pages'])['template']);
    }

    /** Text that takes exactly `$lines` lines in the 40 mm test column at 8 pt. */
    private function linesOfText(int $lines): string
    {
        return implode("\n", array_fill(0, $lines, 'linha'));
    }

    private function reserveTop(TemplateType $type): float
    {
        $margins = $type->pdf['margins'] ?? [];
        $zones   = $type->initialLayout['zones'] ?? [];

        return 297.0
            - (float) ($margins['top'] ?? 15)
            - (float) ($margins['bottom'] ?? 15)
            - (float) ($zones['header_height'] ?? 35)
            - (float) ($zones['footer_height'] ?? 20)
            - (float) ($type->pdf['body_reserve_mm'] ?? 0);
    }

    /**
     * Line tables with one 40 mm text column at 8 pt, plus a totals block.
     *
     * @param  list<array{0: string, 1: int, 2: bool}>  $tables  collection, top, fixed on the last page
     */
    private function body(array $tables): string
    {
        $columns = htmlspecialchars((string) json_encode([['key' => 'c', 'label' => 'C', 'width' => '40mm', 'align' => 'left', 'format' => '']]));
        $body    = '';

        foreach ($tables as [$collection, $top, $fixed]) {
            $body .= '<div class="block block--lines" data-tpl-type="line-table" data-tpl-collection="'.$collection.'"'
                .($fixed ? ' data-tpl-anchor="last-page"' : '')
                .' data-tpl-columns="'.$columns.'"'
                .' id="tpl-'.$collection.'" style="top:'.$top.'mm;width:100mm;font-size:8pt">'
                .'<table class="lines"><thead><tr><th>C</th></tr></thead><tbody>'
                .'{% for linha in '.$collection.' %}<tr><td>{{ linha.c }}</td></tr>{% endfor %}'
                .'</tbody></table></div>';
        }

        $body .= '<div class="block" data-tpl-type="data-field" data-tpl-binding="totais.total" id="tpl-totals">{{ totais.total }}</div>';

        return '<div class="page">'
            .'<div class="zone zone--header" data-tpl-zone="header"></div>'
            .'<div class="zone zone--body" data-tpl-zone="body">'.$body.'</div>'
            .'<div class="zone zone--footer" data-tpl-zone="footer"></div>'
            .'</div>';
    }

    /**
     * The fiscal fixture with every description cut short: nothing wraps.
     *
     * @return array<string, array{editorHtml: string, type: TemplateType, dataset: array<string, mixed>}>
     */
    protected function shortRowScenarios(): array
    {
        $invoice = Templates::type('invoice');
        $html    = $invoice->initialLayout['html'];
        $dataset = $this->fixtureDataset($invoice);

        // Both text columns that overflow at the layout's default 12 pt: the description
        // and the 14-character article code.
        $dataset['linhas'] = array_map(
            fn (array $row): array => [
                'codigo'    => mb_substr((string) $row['codigo'], -4),
                'descricao' => mb_substr((string) $row['descricao'], 0, 12),
            ] + $row,
            $dataset['linhas'],
        );

        return [
            'invoice_short'       => ['editorHtml' => $html, 'type' => $invoice, 'dataset' => $dataset],
            'invoice_short_top20' => [
                'editorHtml' => str_replace('style="top:0mm"', 'style="top:20mm"', $html),
                'type'       => $invoice,
                'dataset'    => $dataset,
            ],
        ];
    }

    protected function shortGoldenPath(): string
    {
        return __DIR__.'/../Fixtures/golden/short-rows-pagination.ser';
    }
}
