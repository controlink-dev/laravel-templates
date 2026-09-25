<?php

namespace Controlink\Templates\Tests\Fixtures;

use Controlink\Templates\Contracts\MoneyFormatter;
use Illuminate\Support\ServiceProvider;

/**
 * Registers a binding before the package provider does, so tests can prove `bindIf`
 * yields to a host binding registered earlier in the provider list.
 */
class HostBindingsProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MoneyFormatter::class, fn () => new class implements MoneyFormatter {
            public function format(float|string|null $value): string
            {
                return 'HOST '.$value;
            }
        });
    }
}
