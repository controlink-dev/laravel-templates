<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Models\Template;
use Controlink\Templates\Tests\TestCase;
use RuntimeException;

class HasTemplatesConnectionTest extends TestCase
{
    public function test_mode_none_uses_the_default_connection(): void
    {
        config(['templates.tenancy.mode' => 'none']);

        $this->assertSame('testing', (new Template)->getConnection()->getName());
    }

    public function test_mode_connection_uses_the_named_connection(): void
    {
        config([
            'templates.tenancy.mode'                    => 'connection',
            'templates.tenancy.connection'              => 'tenant',
            'database.connections.tenant'               => config('database.connections.testing'),
        ]);

        $this->assertSame('tenant', (new Template)->getConnection()->getName());
    }

    public function test_mode_connection_without_a_name_throws_instead_of_falling_back(): void
    {
        config(['templates.tenancy.mode' => 'connection', 'templates.tenancy.connection' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('templates.tenancy.connection');

        (new Template)->getConnection();
    }

    public function test_mode_column_adds_tenant_id_and_scopes_by_it(): void
    {
        config(['templates.tenancy.mode' => 'column']);
        $this->artisan('migrate')->run();

        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('tpl_templates', 'tenant_id'));

        app()->bind(\Controlink\Templates\Contracts\TenantResolver::class, fn () => new class extends \Controlink\Templates\Support\NullTenantResolver {
            public function currentId(): ?string { return 'A'; }
        });
        Template::create(['type_code' => 'invoice', 'name' => 'Mine']);
        Template::withoutGlobalScopes()->create(['type_code' => 'invoice', 'name' => 'Theirs', 'tenant_id' => 'B']);

        $this->assertSame(['Mine'], Template::query()->pluck('name')->all());
    }
}
