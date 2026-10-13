<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Tests\TestCase;

class ProviderTest extends TestCase
{
    public function test_the_default_config_is_merged(): void
    {
        $this->assertSame(['date', 'number', 'money', 'upper'], config('templates.formats'));
        // The package owns no HTTP layer and no tenancy mode: those keys never existed.
        $this->assertNull(config('templates.http'));
        $this->assertNull(config('templates.tenancy'));
        $this->assertNull(config('templates.editor.tenant_header'));
        $this->assertSame('EUR', config('templates.money.currency'));
        $this->assertArrayHasKey('invoice', config('templates.types'));
        $this->assertArrayHasKey('LiberationSans', config('templates.fonts'));
    }

    public function test_the_lang_namespace_is_registered(): void
    {
        $this->assertSame('templates::editor.nothing', __('templates::editor.nothing'));
        $this->assertTrue(app('translator')->getLoader()->namespaces() !== []);
    }
}
