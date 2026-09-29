<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Tests\Fixtures\Models\GuardedVersion;
use Controlink\Templates\Tests\Feature\Concerns\BuildsPdfLayouts;
use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Testing\AssertsReproducibility;
use Controlink\Templates\Types\TemplateType;
use Controlink\Templates\Versions\VersionState;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\ExecutableFinder;

/** Proves the conformance-kit against a test-only guarded model and the facade. */
class ReproducibilityKitTest extends TestCase
{
    use AssertsReproducibility;
    use BuildsPdfLayouts;

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
                Schema::dropIfExists('guarded_versions');
                Schema::create('guarded_versions', function ($t): void {
                    $t->id();
                    $t->string('template_id')->nullable();
                    $t->unsignedInteger('number')->default(1);
                    $t->string('state');
                    $t->json('project_data')->nullable();
                    $t->text('html')->nullable();
                    $t->text('css')->nullable();
                    $t->string('hash')->nullable();
                    $t->timestamps();
                });

                return GuardedVersion::create([
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
