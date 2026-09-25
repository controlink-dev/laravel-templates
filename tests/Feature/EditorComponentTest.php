<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Contracts\TenantResolver;
use Controlink\Templates\Models\Template;
use Controlink\Templates\Models\TemplateVersion;
use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Versions\VersionState;
use Controlink\Templates\View\Components\Editor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use InvalidArgumentException;
use RuntimeException;

/**
 * The editor as an embeddable component (`<x-templates::editor>`), ported from the
 * host's `PrintFormEditorComponentTest`.
 *
 * What is asserted here is the contract the JavaScript bundle depends on: that the
 * markup carries its hooks, that every setting arrives as data rather than as a global
 * (the bundle loads none of a host's JavaScript, so it has no access to a translation
 * layer either), and that the component refuses a combination that would load the wrong
 * layout or a mode phase 1 never built.
 */
class EditorComponentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The hooks the pane behaviour hangs off. Each one is load-bearing: without the
     * root, every lookup would fall back to the document and find another editor's
     * button; without the marker, closing a tab would throw the layout away in silence.
     */
    public function test_the_component_renders_the_hooks_the_pane_behaviour_needs(): void
    {
        [$template, $version] = $this->createTemplate();

        $html = $this->render($template, $version);

        $this->assertStringContainsString('data-tpl-root', $html);
        $this->assertStringContainsString('data-tpl-editor', $html);
        $this->assertStringContainsString('data-templates-dirty', $html);
    }

    /**
     * Configuration travels as data, not as a global — including the interface
     * strings, since the bundle loads none of a host's JavaScript.
     */
    public function test_every_setting_arrives_as_a_data_attribute(): void
    {
        [$template, $version] = $this->createTemplate();

        $html = $this->render($template, $version);

        foreach ([
            'data-load-url', 'data-save-url', 'data-preview-url', 'data-activate-url',
            'data-catalogue-url', 'data-print-stylesheet', 'data-font-family', 'data-font-faces',
            'data-paper-format', 'data-margins', 'data-editable', 'data-initial-html',
            'data-initial-css', 'data-strings', 'data-tenant-header', 'data-tenant',
            'data-csrf-token',
        ] as $attribute) {
            $this->assertStringContainsString($attribute, $html, "Missing {$attribute}.");
        }

        // The strings are rendered, not key names left for the browser to resolve.
        $strings = (new Editor($template, $version))->strings();

        $this->assertNotSame([], $strings);
        $this->assertSame(
            [],
            array_filter($strings, fn (string $value): bool => str_starts_with($value, 'templates::editor.')),
            'Some editor strings reached the browser as translation keys.',
        );
    }

    /** The height is the author's choice, and the widget fills its width. */
    public function test_the_height_is_configurable(): void
    {
        [$template, $version] = $this->createTemplate();

        $html = Blade::render(
            '<x-templates::editor :template="$template" :version="$version" height="42vh" />',
            ['template' => $template, 'version' => $version],
        );

        $this->assertStringContainsString('height: 42vh', $html);
    }

    /**
     * A version from another template would load someone else's layout into this
     * template's editor, and the first save would write it there.
     */
    public function test_the_component_refuses_a_version_from_another_template(): void
    {
        [, $version] = $this->createTemplate();

        $other = Template::create(['type_code' => 'invoice', 'name' => 'Outro modelo']);

        $this->expectException(RuntimeException::class);

        new Editor($other, $version);
    }

    /** An immutable version opens read-only: no save, no activate, and it says why. */
    public function test_an_immutable_version_renders_without_the_writing_actions(): void
    {
        [$template, $version] = $this->createTemplate();

        $version->forceFill(['state' => VersionState::Active])->save();

        $html = $this->render($template, $version->fresh());

        $this->assertStringContainsString('data-editable="0"', $html);
        $this->assertStringNotContainsString('data-tpl-save', $html);
        $this->assertStringNotContainsString('data-tpl-activate', $html);
        $this->assertStringContainsString(__('templates::editor.editor.read_only'), $html);
    }

    /**
     * The legal field notice is drawn by the bundle, and the bundle loads no host
     * JavaScript: it can call neither a host's alert component nor `Lang.get()`. The
     * text has to reach it already translated, like every other string the editor
     * shows.
     */
    public function test_the_legal_notice_text_reaches_the_bundle_translated(): void
    {
        [$template, $version] = $this->createTemplate();

        $strings = (new Editor($template, $version))->strings();

        $this->assertArrayHasKey('notice.legal_locked', $strings);
        $this->assertStringContainsString(':field', $strings['notice.legal_locked']);
        $this->assertStringNotContainsString('templates::editor.', $strings['notice.legal_locked']);
    }

    /**
     * The two chained selects (data source, code source) are labelled from the strings
     * pipeline like everything else in the bundle: it loads no host JavaScript, so it
     * can reach neither `Lang.get()` nor a host's own components, and every word it
     * shows has to arrive already translated.
     */
    public function test_the_chained_select_labels_reach_the_bundle_translated(): void
    {
        [$template, $version] = $this->createTemplate();

        $strings = (new Editor($template, $version))->strings();

        foreach (['trait.node', 'trait.field_source', 'trait.code_source', 'code_source.dynamic', 'code_source.fixed'] as $key) {
            $this->assertArrayHasKey($key, $strings);
            $this->assertStringNotContainsString('templates::editor.', $strings[$key], "{$key} reached the browser as a translation key.");
        }

        // `check.code_incomplete` is optional on the JavaScript side (it falls back to
        // an empty string), but the lang files carry it and `Editor::strings()` should
        // not silently drop it.
        $this->assertArrayHasKey('check.code_incomplete', $strings);
        $this->assertStringNotContainsString('templates::editor.', $strings['check.code_incomplete']);
    }

    /**
     * The editor's assets are its own entry point, published under
     * `public/vendor/templates` — never folded into a host's own bundle.
     */
    public function test_the_component_pulls_its_own_asset_entry_point(): void
    {
        [$template, $version] = $this->createTemplate();

        $html = $this->render($template, $version);

        $this->assertStringContainsString('vendor/templates/editor.css', $html);
        $this->assertStringContainsString('vendor/templates/editor.js', $html);
    }

    /**
     * The font URL is cache-busted by the file's own content hash — the same
     * convention `assetUrl()` uses — so a host that swaps a font's file gets a new
     * URL rather than a browser serving the stale one from cache under an unchanged
     * name.
     */
    public function test_the_font_url_is_cache_busted_by_its_own_content_hash(): void
    {
        [$template, $version] = $this->createTemplate();

        $faces = (new Editor($template, $version))->font()['faces'];
        $path  = config('templates.fonts.LiberationSans.weights.400.path');

        $this->assertSame(400, $faces[0]['weight']);
        $this->assertStringEndsWith(
            '?v='.substr(hash_file('sha256', $path), 0, 12),
            $faces[0]['url'],
        );
    }

    /** Phase 1 supports only a print (PDF) layout. */
    public function test_an_unsupported_mode_is_refused(): void
    {
        [$template, $version] = $this->createTemplate();

        $this->expectException(InvalidArgumentException::class);

        new Editor($template, $version, mode: 'email');
    }

    /**
     * The tenant id travels as data too, under whichever header name the host
     * configures — the bundle sends it back on every request it makes.
     */
    public function test_the_tenant_header_and_id_arrive_as_data_attributes(): void
    {
        config(['templates.http.tenant_header' => 'X-License-Id']);

        $this->app->bind(TenantResolver::class, fn () => new class implements TenantResolver
        {
            public function currentId(): ?string
            {
                return 'L1';
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

        [$template, $version] = $this->createTemplate();

        $html = $this->render($template, $version);

        $this->assertStringContainsString('data-tenant-header="X-License-Id"', $html);
        $this->assertStringContainsString('data-tenant="L1"', $html);
    }

    private function render(Template $template, TemplateVersion $version): string
    {
        return Blade::render(
            '<x-templates::editor :template="$template" :version="$version" />',
            ['template' => $template, 'version' => $version],
        );
    }

    /** @return array{0: Template, 1: TemplateVersion} */
    private function createTemplate(): array
    {
        $template = Template::create(['type_code' => 'invoice', 'name' => 'Fatura A4']);

        $version = $template->versions()->create([
            'number'       => 1,
            'state'        => VersionState::Draft,
            'project_data' => ['pages' => []],
            'html'         => '<div class="page"></div>',
            'css'          => '',
        ]);

        return [$template, $version];
    }
}
