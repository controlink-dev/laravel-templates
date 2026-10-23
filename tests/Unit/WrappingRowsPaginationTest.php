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

        $dataset['linhas'] = array_map(
            fn (array $row): array => ['descricao' => mb_substr((string) $row['descricao'], 0, 12)] + $row,
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
