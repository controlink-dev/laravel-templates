<?php

namespace Controlink\Templates\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * Scaffold the host-side pieces the toolkit deliberately does not own: the two Eloquent
 * models and their migration, the reference controller for the seven editor endpoints,
 * the routes file and an authorization policy. Everything it writes is the host's code,
 * copied once and then free to change — see the package's docs/INTEGRATION.md.
 */
class InstallCommand extends Command
{
    protected $signature = 'templates:install {--force : Overwrite files that already exist}';

    protected $description = 'Scaffold the host-side models, migration, controller, routes and policy for the template editor';

    public function handle(Filesystem $files): int
    {
        $namespace = rtrim($this->laravel->getNamespace(), '\\'); // e.g. "App"
        $stubs     = __DIR__.'/../../stubs';
        $force     = (bool) $this->option('force');

        $targets = [
            'Template.php.stub'                 => app_path('Models/Template.php'),
            'TemplateVersion.php.stub'          => app_path('Models/TemplateVersion.php'),
            'TemplatePolicy.php.stub'           => app_path('Policies/TemplatePolicy.php'),
            'TemplateEditorController.php.stub'  => app_path('Http/Controllers/TemplateEditorController.php'),
            'templates-routes.stub'             => base_path('routes/templates.php'),
        ];

        foreach ($targets as $stub => $target) {
            $this->copyStub($files, $stubs.'/'.$stub, $target, $namespace, $force);
        }

        $this->copyMigration($files, $stubs, $force);

        // The editor mounts its published bundle; without it `Editor::assetUrl()` serves
        // a "missing" URL. The config is deliberately NOT published: the package's merged
        // defaults already apply, and the shipped default font path is resolved relative
        // to the package's own config file — publishing a copy into the host's config/
        // would break that path. The host declares its types by merging a partial config
        // (or publishes and fixes the font path itself).
        $this->callSilently('vendor:publish', ['--tag' => 'templates-assets', '--force' => $force]);

        $this->newLine();
        $this->components->info('Template editor scaffolding installed.');
        $this->line('  Next steps:');
        $this->line('    1. Register the routes — add to <comment>routes/web.php</comment>:');
        $this->line('         <comment>require __DIR__.\'/templates.php\';</comment>');
        $this->line('    2. Grant abilities in <comment>app/Policies/TemplatePolicy.php</comment> (it denies everything by default).');
        $this->line('    3. Declare a type in <comment>config/templates.php</comment> — a partial config is merged over the');
        $this->line('       package defaults (see the package README, "Declaring a type").');
        $this->line('    4. Run <comment>php artisan migrate</comment>.');

        return self::SUCCESS;
    }

    private function copyStub(Filesystem $files, string $stub, string $target, string $namespace, bool $force): void
    {
        if ($files->exists($target) && ! $force) {
            $this->components->twoColumnDetail($this->relative($target), '<fg=yellow>exists, skipped</>');

            return;
        }

        $files->ensureDirectoryExists(dirname($target));
        $files->put($target, str_replace('{{ namespace }}', $namespace, $files->get($stub)));
        $this->components->twoColumnDetail($this->relative($target), '<fg=green>created</>');
    }

    /**
     * The migration carries a timestamp in its name, so it is matched by its suffix
     * rather than an exact path: a second run skips it instead of stacking a duplicate.
     */
    private function copyMigration(Filesystem $files, string $stubs, bool $force): void
    {
        $existing = $files->glob(database_path('migrations/*_create_templates_table.php'));

        if ($existing !== [] && ! $force) {
            $this->components->twoColumnDetail($this->relative($existing[0]), '<fg=yellow>exists, skipped</>');

            return;
        }

        $target = $existing[0] ?? database_path('migrations/'.date('Y_m_d_His').'_create_templates_table.php');

        $files->ensureDirectoryExists(dirname($target));
        $files->put($target, $files->get($stubs.'/create_templates_table.php.stub'));
        $this->components->twoColumnDetail($this->relative($target), '<fg=green>created</>');
    }

    private function relative(string $path): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
    }
}
