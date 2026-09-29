<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Exceptions\ImmutableVersionException;
use Controlink\Templates\Tests\Fixtures\Models\GuardedVersion;
use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Versions\VersionState;
use Illuminate\Support\Facades\Schema;

class GuardsIssuableVersionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('guarded_versions', function ($table): void {
            $table->id();
            $table->string('template_id')->nullable();
            $table->unsignedInteger('number')->default(1);
            $table->string('state');
            $table->json('project_data')->nullable();
            $table->text('html')->nullable();
            $table->text('css')->nullable();
            $table->string('hash')->nullable();
            $table->timestamps();
        });
    }

    private function make(VersionState $state): GuardedVersion
    {
        return GuardedVersion::create(['state' => $state, 'html' => '<div></div>', 'css' => '']);
    }

    public function test_a_draft_version_can_change_its_layout(): void
    {
        $version = $this->make(VersionState::Draft);
        $version->update(['html' => '<p>x</p>']);

        $this->assertSame('<p>x</p>', $version->fresh()->html);
    }

    public function test_an_issuable_version_refuses_a_frozen_field_mutation(): void
    {
        $version = $this->make(VersionState::Active);

        $this->expectException(ImmutableVersionException::class);

        $version->update(['html' => '<p>changed</p>']);
    }

    public function test_an_issuable_version_may_still_change_its_state(): void
    {
        $version = $this->make(VersionState::Active);
        $version->update(['state' => VersionState::Archived]);

        $this->assertSame(VersionState::Archived, $version->fresh()->state);
    }
}
