<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Contracts\TenantResolver;
use Controlink\Templates\Models\Template;
use Controlink\Templates\Tests\TestCase;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

/**
 * `StoreTemplateRequest`'s uniqueness rule, specifically in `column` tenancy mode.
 *
 * Kept separate from `VersionApiTest` rather than added as one more method there:
 * `defineEnvironment()` is class-wide, and both the migration and the validation rule
 * read `config('templates.tenancy.mode')` at migrate/request time. Setting `column`
 * mode there would switch every `VersionApiTest` test into column mode, not just the
 * ones about to be added here.
 */
class StoreTemplateColumnTenancyTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('templates.tenancy.mode', 'column');

        // A creatable type: `invoice` is seeded and cannot be created from scratch
        // (StoreTemplateRequest refuses it), so template creation needs a type of its
        // own — the same fixture VersionApiTest declares for the same reason.
        $app['config']->set('templates.types.quote', [
            'label'           => 'Quote',
            'output'          => 'pdf',
            'seeded'          => false,
            'layout_revision' => 1,
            'bindings'        => [],
            'nodes'           => [],
            'fixture'         => [],
            'initial_layout'  => [
                'html' => '<div class="page"></div>',
                'css'  => '',
            ],
            'pdf' => [
                'paper_format'      => 'A4',
                'margins'           => ['top' => 15.0, 'right' => 15.0, 'bottom' => 15.0, 'left' => 15.0],
                'visibility_limits' => [],
            ],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Gate::policy(Template::class, StoreTemplateColumnTenancyTestPolicy::class);
    }

    /**
     * The uniqueness rule is a raw query against the shared table, not an Eloquent
     * query that would pick up the model's own tenant scope — so without the fix, the
     * second tenant's identical name would be refused as a collision with the first
     * tenant's row it can never see or read back.
     */
    public function test_two_tenants_can_both_create_a_template_of_the_same_name(): void
    {
        $user = $this->user();

        $this->bindTenant('A');
        $this->actingAs($user)
            ->postJson(route('templates.store'), ['name' => 'Fatura', 'type_code' => 'quote'])
            ->assertCreated();

        $this->bindTenant('B');
        $this->actingAs($user)
            ->postJson(route('templates.store'), ['name' => 'Fatura', 'type_code' => 'quote'])
            ->assertCreated();

        $this->assertSame(
            2,
            Template::withoutGlobalScopes()->where('name', 'Fatura')->count(),
        );
    }

    /** The rule still refuses a second template of the same name for the SAME tenant. */
    public function test_the_same_tenant_still_cannot_repeat_a_name(): void
    {
        $user = $this->user();

        $this->bindTenant('A');

        $this->actingAs($user)
            ->postJson(route('templates.store'), ['name' => 'Fatura', 'type_code' => 'quote'])
            ->assertCreated();

        $this->actingAs($user)
            ->postJson(route('templates.store'), ['name' => 'Fatura', 'type_code' => 'quote'])
            ->assertJsonValidationErrors('name');
    }

    private function bindTenant(string $id): void
    {
        $this->app->bind(TenantResolver::class, fn () => new class($id) implements TenantResolver
        {
            public function __construct(private readonly string $id) {}

            public function currentId(): ?string
            {
                return $this->id;
            }

            public function all(): iterable
            {
                return [];
            }

            public function runAs(string $tenantId, callable $callback): mixed
            {
                return $callback();
            }
        });
    }

    private function user(): User
    {
        $user = new User;
        $user->forceFill(['id' => 1, 'name' => 'Ana']);

        return $user;
    }
}

/** Allows everything: this class is about the uniqueness rule, not authorisation. */
class StoreTemplateColumnTenancyTestPolicy
{
    public function __call(string $ability, array $arguments): bool
    {
        return true;
    }
}
