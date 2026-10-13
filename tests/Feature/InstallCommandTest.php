<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * `templates:install` scaffolds the host side into the host's own paths. The command is
 * the friendly front door to everything docs/INTEGRATION.md describes, so it has to put
 * parseable, correctly-namespaced files where the host expects them, and never clobber
 * work on a second run.
 */
class InstallCommandTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = sys_get_temp_dir().'/tpl-install-'.uniqid();
        File::ensureDirectoryExists($this->base);
        // getNamespace() reads the base path's composer.json; give it a known root.
        File::put($this->base.'/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]]));

        $this->app->setBasePath($this->base);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);

        parent::tearDown();
    }

    public function test_it_scaffolds_the_host_side(): void
    {
        $this->artisan('templates:install')->assertSuccessful();

        foreach ([
            'app/Models/Template.php',
            'app/Models/TemplateVersion.php',
            'app/Policies/TemplatePolicy.php',
            'app/Http/Controllers/TemplateEditorController.php',
            'routes/templates.php',
        ] as $relative) {
            $this->assertFileExists($this->base.'/'.$relative);
        }

        $migrations = File::glob($this->base.'/database/migrations/*_create_templates_table.php');
        $this->assertCount(1, $migrations, 'The migration was not created exactly once.');
    }

    public function test_it_substitutes_the_host_namespace(): void
    {
        $this->artisan('templates:install')->assertSuccessful();

        $controller = File::get($this->base.'/app/Http/Controllers/TemplateEditorController.php');

        $this->assertStringContainsString('namespace App\Http\Controllers;', $controller);
        $this->assertStringContainsString('use App\Models\Template;', $controller);
        $this->assertStringNotContainsString('{{ namespace }}', $controller);
    }

    public function test_the_generated_files_parse(): void
    {
        $this->artisan('templates:install')->assertSuccessful();

        $files = array_merge(
            [
                $this->base.'/app/Models/Template.php',
                $this->base.'/app/Models/TemplateVersion.php',
                $this->base.'/app/Policies/TemplatePolicy.php',
                $this->base.'/app/Http/Controllers/TemplateEditorController.php',
                $this->base.'/routes/templates.php',
            ],
            File::glob($this->base.'/database/migrations/*_create_templates_table.php'),
        );

        foreach ($files as $file) {
            $process = new Process([PHP_BINARY, '-l', $file]);
            $process->run();

            $this->assertTrue($process->isSuccessful(), "Generated file does not parse: {$file}\n".$process->getOutput().$process->getErrorOutput());
        }
    }

    public function test_a_second_run_does_not_clobber_edited_files(): void
    {
        $this->artisan('templates:install')->assertSuccessful();

        $controller = $this->base.'/app/Http/Controllers/TemplateEditorController.php';
        File::put($controller, '<?php // my edits');

        $this->artisan('templates:install')->assertSuccessful();
        $this->assertSame('<?php // my edits', File::get($controller), 'A second run overwrote an existing file.');

        // And a single migration, not a second stacked one.
        $this->assertCount(1, File::glob($this->base.'/database/migrations/*_create_templates_table.php'));

        $this->artisan('templates:install', ['--force' => true])->assertSuccessful();
        $this->assertStringContainsString('TemplateEditorController', File::get($controller), '--force did not restore the file.');
    }
}
