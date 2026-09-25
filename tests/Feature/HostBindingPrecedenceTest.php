<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Contracts\MoneyFormatter;
use Controlink\Templates\TemplatesServiceProvider;
use Controlink\Templates\Tests\Fixtures\HostBindingsProvider;
use Controlink\Templates\Tests\TestCase;

/**
 * `bindIf` in the package provider must yield to a binding the host already
 * registered — proven here by registering the host provider before the package's.
 */
class HostBindingPrecedenceTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [HostBindingsProvider::class, TemplatesServiceProvider::class];
    }

    public function test_a_host_binding_registered_before_the_package_provider_wins(): void
    {
        $this->assertSame('HOST 5', app(MoneyFormatter::class)->format(5));
    }
}
