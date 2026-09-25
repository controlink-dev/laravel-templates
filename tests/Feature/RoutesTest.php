<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Models\Template;
use Controlink\Templates\Tests\TestCase;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

class RoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('templates.http.middleware', ['web']);
    }

    public function test_routes_are_registered_under_the_configured_prefix(): void
    {
        $this->assertSame('templates/catalogue/{type}', Route::getRoutes()->getByName('templates.catalogue')->uri());
        $this->assertSame('templates/templates/{template}/versions/{version}/activate', Route::getRoutes()->getByName('templates.versions.activate')->uri());
    }

    public function test_without_a_policy_everything_is_forbidden(): void
    {
        $this->actingAs(new User)->getJson(route('templates.catalogue', 'invoice'))->assertForbidden();
        $this->actingAs(new User)->postJson(route('templates.store'), ['type_code' => 'invoice', 'name' => 'x'])->assertForbidden();
    }

    public function test_a_host_policy_opens_the_door(): void
    {
        Gate::policy(Template::class, AllowEverythingPolicy::class);

        $this->actingAs(new User)->getJson(route('templates.catalogue', 'invoice'))
            ->assertOk()
            ->assertJsonStructure(['bindings', 'nodes', 'formats', 'code_types', 'visibility_limits', 'rule_widths', 'image_max_bytes', 'fixture', 'zones']);
    }

    public function test_the_stylesheet_is_served_raw_with_a_content_hash_etag(): void
    {
        Gate::policy(Template::class, AllowEverythingPolicy::class);

        $this->actingAs(new User)->get(route('templates.stylesheet'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/css; charset=utf-8')
            ->assertHeader('ETag');
    }

    public function test_a_declared_font_is_served_with_its_mime_type(): void
    {
        $this->actingAs(new User)->get(route('templates.font', ['family' => 'LiberationSans', 'weight' => 400]))
            ->assertOk()
            ->assertHeader('Content-Type', 'font/ttf');
    }

    public function test_an_undeclared_font_family_is_a_404(): void
    {
        $this->actingAs(new User)->get(route('templates.font', ['family' => 'Nonexistent', 'weight' => 400]))
            ->assertNotFound();
    }

    /**
     * `{weight}` is constrained to digits (`whereNumber`). Without the constraint, a
     * non-numeric segment reaches the controller's `int $weight` parameter and PHP's
     * type coercion throws a `TypeError`, surfacing as a 500 instead of a 404.
     */
    public function test_a_non_numeric_font_weight_is_a_404_not_a_server_error(): void
    {
        $this->actingAs(new User)
            ->get('/'.config('templates.http.prefix').'/fonts/LiberationSans/abc')
            ->assertNotFound();
    }

    /** A path traversal attempt simply fails to match the two-segment route: 404, no exception. */
    public function test_a_traversal_segment_in_the_font_url_is_a_404(): void
    {
        $this->actingAs(new User)
            ->get('/'.config('templates.http.prefix').'/fonts/../../composer.json/400')
            ->assertNotFound();
    }
}

class AllowEverythingPolicy
{
    public function __call(string $ability, array $arguments): bool
    {
        return true;
    }
}
