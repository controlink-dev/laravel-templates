<?php

namespace Controlink\Templates\Tests\Unit;

use Controlink\Templates\Contracts\TenantResolver;
use Controlink\Templates\Tests\TestCase;

class NullTenantResolverTest extends TestCase
{
    public function test_run_as_simply_runs_the_callback(): void
    {
        $this->assertNull(app(TenantResolver::class)->currentId());
        $this->assertSame(42, app(TenantResolver::class)->runAs('x', fn () => 42));
    }
}
