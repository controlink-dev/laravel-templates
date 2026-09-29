<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Models\Template;
use Controlink\Templates\Tests\Feature\Concerns\BuildsPdfLayouts;
use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Testing\AssertsReproducibility;
use Controlink\Templates\Types\TemplateType;
use Controlink\Templates\Versions\VersionState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\ExecutableFinder;

/** Proves the conformance-kit against the package's own model and facade. */
class ReproducibilityKitTest extends TestCase
{
    use AssertsReproducibility;
    use BuildsPdfLayouts;
    use RefreshDatabase;

    private function fields(): array
    {
        return [['key' => 'documento.atcud', 'label' => 'ATCUD', 'type' => 'text', 'mandatory' => true, 'legal' => true, 'scope' => 'every_page']];
    }

    public function test_input_hash_is_stable(): void
    {
        $this->assertInputHashStable(['b' => 1, 'a' => ['y' => 2]], ['paper_format' => 'A4']);
        $this->assertInputHashStable(['a' => 1]);
    }

    public function test_issuable_versions_are_immutable(): void
    {
        foreach ([VersionState::Active, VersionState::Archived] as $state) {
            $this->assertVersionImmutableWhenIssuable(function () use ($state) {
                $template = Template::create(['type_code' => 'invoice', 'name' => 'Fatura']);

                return $template->versions()->create([
                    'number' => 1, 'state' => $state, 'project_data' => ['pages' => []], 'html' => '<div class="page"></div>', 'css' => '',
                ]);
            });
        }
    }

    public function test_re_render_reproduces(): void
    {
        $finder = new ExecutableFinder;
        foreach (['pdfinfo', 'pdftotext', 'pdftoppm'] as $tool) {
            if (! $finder->find($tool)) {
                $this->markTestSkipped('poppler-utils not installed');
            }
        }

        $type = TemplateType::fromConfig('x', ['bindings' => $this->fields()]);
        $geo  = ['paper_format' => 'A4', 'margins' => ['top' => 15, 'right' => 15, 'bottom' => 15, 'left' => 15], 'zones' => ['header_height' => 40, 'footer_height' => 18]];

        $matched = $this->assertReRenderReproduces($this->validPdfLayout($type), ['documento.atcud' => 'JFT7-0000000001'], $geo, '', $this->fields());

        fwrite(STDERR, "\n[kit] pdfHash best-effort match: ".($matched ? 'yes' : 'no')."\n");
    }
}
