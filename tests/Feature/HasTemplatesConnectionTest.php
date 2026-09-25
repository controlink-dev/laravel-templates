<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Models\Template;
use Controlink\Templates\Models\TemplateVersion;
use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Versions\VersionState;
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

    /**
     * `HasTemplatesConnection`'s global scope and `creating` hook are generic over any
     * model that uses the trait — both `Template` and `TemplateVersion` do — so both
     * tables have to carry the `tenant_id` column the scope filters on, not only
     * `tpl_templates`. This proves the versions table gets it too, and that a version
     * is scoped by tenant exactly as its template is.
     */
    public function test_mode_column_scopes_versions_too(): void
    {
        config(['templates.tenancy.mode' => 'column']);
        $this->artisan('migrate')->run();

        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('tpl_template_versions', 'tenant_id'));

        app()->bind(\Controlink\Templates\Contracts\TenantResolver::class, fn () => new class extends \Controlink\Templates\Support\NullTenantResolver {
            public function currentId(): ?string { return 'A'; }
        });

        $template = Template::create(['type_code' => 'invoice', 'name' => 'Mine']);
        $template->versions()->create(['number' => 1, 'state' => VersionState::Draft, 'project_data' => []]);

        app()->bind(\Controlink\Templates\Contracts\TenantResolver::class, fn () => new class extends \Controlink\Templates\Support\NullTenantResolver {
            public function currentId(): ?string { return 'B'; }
        });

        $this->assertSame(0, TemplateVersion::count());

        app()->bind(\Controlink\Templates\Contracts\TenantResolver::class, fn () => new class extends \Controlink\Templates\Support\NullTenantResolver {
            public function currentId(): ?string { return 'A'; }
        });

        $this->assertSame(1, TemplateVersion::count());
    }
}
