<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Tests\TestCase;

class ProviderTest extends TestCase
{
    public function test_the_default_config_is_merged(): void
    {
        $this->assertSame('none', config('templates.tenancy.mode'));
        $this->assertSame(['date', 'number', 'money', 'upper'], config('templates.formats'));
        $this->assertSame('templates', config('templates.http.prefix'));
        $this->assertSame('EUR', config('templates.money.currency'));
        $this->assertSame([], config('templates.types'));
        $this->assertArrayHasKey('LiberationSans', config('templates.fonts'));
    }

    public function test_the_lang_namespace_is_registered(): void
    {
        $this->assertSame('templates::editor.nothing', __('templates::editor.nothing'));
        $this->assertTrue(app('translator')->getLoader()->namespaces() !== []);
    }
}
