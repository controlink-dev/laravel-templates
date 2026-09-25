<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Exceptions\ImmutableVersionException;
use Controlink\Templates\Models\Template;
use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Versions\CanonicalHash;
use Controlink\Templates\Versions\VersionState;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TemplateVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_hash_ignores_key_order(): void
    {
        $this->assertSame(
            CanonicalHash::of(['b' => 1, 'a' => ['y' => 2, 'x' => 3]]),
            CanonicalHash::of(['a' => ['x' => 3, 'y' => 2], 'b' => 1]),
        );
    }

    public function test_an_active_version_refuses_layout_changes_but_accepts_state_changes(): void
    {
        $template = Template::create(['type_code' => 'invoice', 'name' => 'Fatura']);
        $version  = $template->versions()->create([
            'number' => 1, 'state' => VersionState::Active, 'project_data' => ['pages' => []], 'html' => '<div class="page"></div>', 'css' => '',
        ]);

        $version->forceFill(['state' => VersionState::Archived])->save();
        $this->assertSame(VersionState::Archived, $version->fresh()->state);

        $this->expectException(ImmutableVersionException::class);
        $version->forceFill(['html' => 'changed'])->save();
    }

    public function test_version_numbers_are_unique_among_existing_versions(): void
    {
        $template = Template::create(['type_code' => 'invoice', 'name' => 'Fatura']);
        $template->versions()->create(['number' => 1, 'state' => VersionState::Draft, 'project_data' => []]);
        $template->versions()->create(['number' => 2, 'state' => VersionState::Draft, 'project_data' => []]);
        $template->versions()->where('number', 2)->delete();

        $this->assertSame(2, $template->nextVersionNumber());
    }
}
