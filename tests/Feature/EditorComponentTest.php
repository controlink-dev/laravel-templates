<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Contracts\TenantResolver;
use Controlink\Templates\Formulas\Formula;
use Controlink\Templates\Formulas\Parser;
use Controlink\Templates\Tests\Fixtures\Models\EditorTemplate;
use Controlink\Templates\Tests\Fixtures\Models\GuardedVersion;
use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Versions\VersionState;
use Controlink\Templates\View\Components\Editor;
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
    /** The keys of the host's invoice type that the function examples are written with. */
    private const INVOICE_SINGULAR_KEYS = [
        'customer.name', 'customer.tax_id', 'customer.address',
        'document.designation', 'document.number', 'document.issue_date', 'document.supply_date',
        'totals.net_total', 'totals.tax_total', 'totals.total_due',
    ];

    private const INVOICE_COLLECTIONS = [
        'lines' => ['code', 'description', 'quantity', 'unit', 'unit_price', 'vat_rate', 'amount'],
    ];

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

        $other = new EditorTemplate(['id' => 'other', 'type_code' => 'invoice']);

        $this->expectException(RuntimeException::class);

        new Editor($other, $version);
    }

    /** An immutable version opens read-only: no save, no activate, and it says why. */
    public function test_an_immutable_version_renders_without_the_writing_actions(): void
    {
        [$template, $version] = $this->createTemplate();

        $version->forceFill(['state' => VersionState::Active]);

        $html = $this->render($template, $version);

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

    /** The formula mode of a data field (CGE-37) is labelled from the same pipeline. */
    public function test_the_formula_strings_reach_the_bundle_translated(): void
    {
        [$template, $version] = $this->createTemplate();

        $strings = (new Editor($template, $version))->strings();

        foreach (['label', 'toggle', 'legal_locked', 'source', 'placeholder', 'calculating', 'result', 'blank',
            'column_label', 'add_column', 'remove_column', 'column_calculated', 'column_placeholder', 'column_help', 'columns_overflow'] as $key) {
            $this->assertArrayHasKey('formula.'.$key, $strings);
            $this->assertStringNotContainsString('templates::editor.', $strings['formula.'.$key], "formula.{$key} reached the browser as a translation key.");
        }

        // Filled in by the bundle, so the placeholders must survive translation.
        $this->assertStringContainsString(':value', $strings['formula.result']);

        // The ƒx reference and its strings are gone: the "Campos e funções" library says it all.
        foreach (['functions', 'reference_toggle', 'reference_heading', 'reference_note'] as $key) {
            $this->assertArrayNotHasKey('formula.'.$key, $strings);
        }
    }

    /**
     * The function reference and the autocomplete (CGE-37) list exactly the functions the
     * parser accepts: the bundle reads the list from these keys, so the parser is the only
     * list there is, and every function carries a signature and a one-line description.
     */
    public function test_every_formula_function_reaches_the_bundle_with_a_signature_and_a_description(): void
    {
        [$template, $version] = $this->createTemplate();

        foreach (['en', 'pt_PT'] as $locale) {
            app()->setLocale($locale);

            $strings = (new Editor($template, $version))->strings();

            $listed = [];

            foreach (array_keys($strings) as $key) {
                if (str_starts_with($key, 'formula.signature.')) {
                    $listed[] = substr($key, strlen('formula.signature.'));
                }
            }

            $this->assertSame(Formula::functions(), $listed, "{$locale}: the bundle's function list is not the parser's.");
            $this->assertNotContains('TODAY', $listed);

            foreach (Formula::functions() as $name) {
                foreach (['signature', 'description', 'example'] as $kind) {
                    $value = $strings["formula.{$kind}.{$name}"] ?? '';
                    $this->assertNotSame('', $value);
                    $this->assertStringNotContainsString('templates::editor.', $value, "{$locale}: formula.{$kind}.{$name} reached the browser as a translation key.");
                }

                $this->assertStringStartsWith($name.'(', $strings["formula.signature.{$name}"]);

                // The example uses its own function and is a formula the parser accepts.
                $example = $strings["formula.example.{$name}"];
                $this->assertStringContainsString($name.'(', $example, "{$locale}: the example of {$name} does not use it.");
                Parser::parse($example);

                // And it is valid in a single data field of the invoice type it is written
                // for: singular keys, the table's columns only inside SUM/COUNT (V-19 scope).
                $this->assertNull(
                    Formula::check($example, self::INVOICE_SINGULAR_KEYS, self::INVOICE_COLLECTIONS),
                    "{$locale}: the example of {$name} is not valid in a data field.",
                );
            }

            // The data field's "Valor: Campo | Fórmula", its box and its quick examples.
            foreach (['value', 'value_field', 'value_formula', 'done', 'unknown_field',
                'quick.heading', 'quick.text', 'quick.text_source', 'quick.upper', 'quick.total'] as $key) {
                $this->assertNotSame('', $strings['formula.'.$key] ?? '', "{$locale}: formula.{$key}");
                $this->assertStringNotContainsString('templates::editor.', $strings['formula.'.$key], "{$locale}: formula.{$key}");
            }

            $this->assertStringContainsString(':name', $strings['formula.unknown_field']);

            // The line table's button (spec §6.2), word for word.
            $this->assertSame(['en' => '+ Calculated column', 'pt_PT' => '+ Coluna calculada'][$locale], $strings['formula.add_column']);
            $this->assertStringContainsString(':field', $strings['formula.quick.text_source']);

            // The text example, filled with a field, is a formula the parser accepts.
            $this->assertNull(Formula::check(
                str_replace(':field', 'customer.name', $strings['formula.quick.text_source']),
                self::INVOICE_SINGULAR_KEYS,
                self::INVOICE_COLLECTIONS,
            ));

            // The "Campos e funções" library: its labels, and one per group of functions.
            foreach (['heading', 'fields', 'functions', 'search_fields', 'search_functions', 'empty', 'example', 'this_row'] as $key) {
                $this->assertStringNotContainsString('templates::editor.', $strings['formula.library.'.$key] ?? 'templates::editor.', "{$locale}: formula.library.{$key}");
            }

            foreach (['math', 'totals', 'conditions', 'text', 'dates', 'other'] as $group) {
                $this->assertStringNotContainsString('templates::editor.', $strings['formula.group.'.$group] ?? 'templates::editor.', "{$locale}: formula.group.{$group}");
            }

            $this->assertStringContainsString(':example', $strings['formula.library.example']);

            $this->assertStringNotContainsString('templates::editor.', $strings['formula.functions_group']);
        }
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
        config(['templates.editor.tenant_header' => 'X-License-Id']);

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

    /**
     * The in-toolbar format `<select>` renders by default, but a host that drives format
     * switching from its own page chrome turns it off so the two controls never both show.
     */
    public function test_the_format_selector_renders_by_default_and_can_be_turned_off(): void
    {
        [$template, $version] = $this->createTemplate();

        $this->assertStringContainsString('tpl-tool--format', $this->render($template, $version));

        $off = Blade::render(
            '<x-templates::editor :template="$template" :version="$version" :format-selector="false" />',
            ['template' => $template, 'version' => $version],
        );

        $this->assertStringNotContainsString('tpl-tool--format', $off);
    }

    /**
     * By default the editor shows its own outcome messages (saved, refused, render failed)
     * in its status line. A host that listens for the bubbling `templates:message` events
     * and presents them in its own style passes `:host-notices="true"`; the editor then
     * stops writing them itself, which it learns from the `data-host-notices` flag.
     */
    public function test_host_notices_are_off_by_default_and_can_be_turned_on(): void
    {
        [$template, $version] = $this->createTemplate();

        $this->assertStringContainsString('data-host-notices=""', $this->render($template, $version));

        $on = Blade::render(
            '<x-templates::editor :template="$template" :version="$version" :host-notices="true" />',
            ['template' => $template, 'version' => $version],
        );

        $this->assertStringContainsString('data-host-notices="1"', $on);
    }

    /**
     * A host that owns the format lineage hands the editor a `format_switch` URL with the
     * `__FORMAT__` token; the editor substitutes the chosen format id and fetches that
     * format's own version in place.
     */
    public function test_the_format_switch_url_reaches_the_bundle_as_data(): void
    {
        [$template, $version] = $this->createTemplate();

        $html = Blade::render(
            '<x-templates::editor :template="$template" :version="$version" :urls="$urls" />',
            ['template' => $template, 'version' => $version, 'urls' => ['format_switch' => '/forms/tpl-1/format/__FORMAT__']],
        );

        $this->assertStringContainsString('data-format-switch-url="/forms/tpl-1/format/__FORMAT__"', $html);
        $this->assertSame(Editor::FORMAT_PLACEHOLDER, '__FORMAT__');
    }

    private function render(EditorTemplate $template, GuardedVersion $version): string
    {
        return Blade::render(
            '<x-templates::editor :template="$template" :version="$version" />',
            ['template' => $template, 'version' => $version],
        );
    }

    /** @return array{0: EditorTemplate, 1: GuardedVersion} */
    private function createTemplate(): array
    {
        $template = new EditorTemplate(['id' => 'tpl-1', 'type_code' => 'invoice', 'name' => 'Fatura A4']);

        $version = new GuardedVersion([
            'template_id' => 'tpl-1',
            'number'      => 1,
            'state'       => VersionState::Draft,
            'html'        => '<div class="page"></div>',
            'css'         => '',
        ]);

        return [$template, $version];
    }
}
