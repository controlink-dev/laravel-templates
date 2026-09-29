<?php

namespace Controlink\Templates;

use Controlink\Templates\Contracts\Auditor;
use Controlink\Templates\Contracts\EncryptedFieldMap;
use Controlink\Templates\Contracts\MoneyFormatter;
use Controlink\Templates\Contracts\PdfEngine;
use Controlink\Templates\Contracts\TenantResolver;
use Controlink\Templates\Rendering\FilterRegistry;
use Controlink\Templates\Support\EmptyEncryptedFieldMap;
use Controlink\Templates\Support\IntlMoneyFormatter;
use Controlink\Templates\Support\NullAuditor;
use Controlink\Templates\Support\NullTenantResolver;
use Controlink\Templates\Toolkit\Documents;
use Controlink\Templates\Types\TypeCatalogue;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class TemplatesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/templates.php', 'templates');

        // Host bindings override these because the host's provider boots later and
        // `bind()` replaces; the package never uses `singletonIf`.
        $this->app->bindIf(TenantResolver::class, NullTenantResolver::class);
        $this->app->bindIf(Auditor::class, NullAuditor::class);
        $this->app->bindIf(EncryptedFieldMap::class, EmptyEncryptedFieldMap::class);
        $this->app->bindIf(MoneyFormatter::class, IntlMoneyFormatter::class);
        $this->app->bindIf(PdfEngine::class, fn ($app) => $app->make(config('templates.pdf.engine')));

        $this->app->singleton(TypeCatalogue::class, fn () => new TypeCatalogue((array) config('templates.types')));
        $this->app->singleton(TemplatesManager::class);

        // Shared so a host's `FilterRegistry::register()` call, wherever it runs, is
        // visible to every `TemplateRenderer` the container later builds.
        $this->app->singleton(FilterRegistry::class);
        $this->app->singleton(Documents::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'templates');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'templates');

        Blade::componentNamespace('Controlink\\Templates\\View\\Components', 'templates');

        $this->publishes([__DIR__.'/../config/templates.php' => config_path('templates.php')], 'templates-config');
        $this->publishes([__DIR__.'/../dist' => public_path('vendor/templates')], 'templates-assets');
        $this->publishes([__DIR__.'/../resources/lang' => $this->app->langPath('vendor/templates')], 'templates-lang');

        // Structural validation only: no database access here (see spec, section 5).
        $this->app->make(TypeCatalogue::class)->validate();

        if (config('templates.http.routes')) {
            Route::middleware(config('templates.http.middleware'))
                ->prefix(config('templates.http.prefix'))
                ->name('templates.')
                ->group(__DIR__.'/../routes/api.php');
        }
    }
}
