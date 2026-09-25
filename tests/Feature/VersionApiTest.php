<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Exceptions\UnsafeTemplateException;
use Controlink\Templates\Models\Template;
use Controlink\Templates\Models\TemplateVersion;
use Controlink\Templates\Output\Pdf\DocumentComposer;
use Controlink\Templates\Output\Pdf\PdfHash;
use Controlink\Templates\Templates;
use Controlink\Templates\Tests\Concerns\BuildsLayouts;
use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Versions\CanonicalHash;
use Controlink\Templates\Versions\VersionState;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The JSON endpoints that back the editor (Http\Controllers\{Template,Version}Controller),
 * ported from the host's PrintFormEditorTest.
 *
 * What is asserted here is the half of the fidelity claim a browser cannot prove: that a
 * layout survives the round trip through the database unchanged, that an immutable
 * version cannot be written, and that the document handed to the PDF engine carries the
 * shared stylesheet with every asset embedded and nothing executable.
 */
class VersionApiTest extends TestCase
{
    use BuildsLayouts;
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // A creatable type: `invoice` is seeded and cannot be created from scratch, so
        // the creation test needs a type of its own — the package's equivalent of the
        // host's `registerNonFiscalDocumentType()`. Added here, before the app boots,
        // because `TypeCatalogue` is a singleton snapshotted at boot time.
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

        // GotenbergEngine throws before ever reaching the (faked) HTTP call when this
        // is empty.
        config(['templates.pdf.gotenberg.url' => 'http://localhost:3100']);

        VersionApiTestPolicy::$denied = [];
        Gate::policy(Template::class, VersionApiTestPolicy::class);
    }

    /**
     * A template is useless without something to open, so creating one creates its
     * first draft from the document type's initial layout.
     */
    public function test_creating_a_print_form_creates_a_draft_from_the_initial_layout(): void
    {
        $user = $this->user();

        $response = $this->actingAs($user)
            ->postJson(route('templates.store'), [
                'name'      => 'Orçamento A4',
                'type_code' => 'quote',
            ])
            ->assertCreated()
            ->assertJsonPath('template.name', 'Orçamento A4')
            ->assertJsonPath('template.type_code', 'quote');

        $template = Template::query()->findOrFail($response->json('template.id'));

        $this->assertCount(1, $template->versions);

        $version = $template->versions->first();

        $this->assertSame(1, $version->number);
        $this->assertSame(VersionState::Draft, $version->state);
        $this->assertSame(
            config('templates.types.quote.initial_layout.html'),
            $version->html,
        );

        // No `edit_url`: the package does not know the host's editor route. The
        // response carries both ids instead, so the host can build it.
        $this->assertNotEmpty($response->json('version.id'));
        $this->assertArrayNotHasKey('edit_url', $response->json());
    }

    /**
     * The F1 acceptance criterion: saved, reopened, nothing lost.
     */
    public function test_a_saved_layout_is_read_back_unchanged(): void
    {
        [$user, $template, $version] = $this->createTemplate();

        ['project_data' => $projectData, 'html' => $html, 'css' => $css] = $this->validLayout('invoice');

        $this->actingAs($user)
            ->putJson($this->versionRoute('versions.update', $template, $version), [
                'project_data' => $projectData,
                'html'         => $html,
                'css'          => $css,
            ])
            ->assertOk()
            ->assertJsonPath('version.state', VersionState::Validated->value);

        $reloaded = $this->actingAs($user)
            ->getJson($this->versionRoute('versions.show', $template, $version))
            ->assertOk();

        // Not "equivalent": identical. A layout that comes back reordered or with a
        // value coerced is a layout the user did not draw.
        $this->assertSame($projectData, $reloaded->json('data.project_data'));
        $this->assertSame(CanonicalHash::of($projectData), $reloaded->json('data.hash'));
    }

    /**
     * The hash describes the layout, not the order the editor happened to emit its
     * properties in — otherwise the audit trail would record a change on every save.
     */
    public function test_the_layout_hash_ignores_key_order(): void
    {
        $a = ['pages' => [['name' => 'Page 1']], 'styles' => [], 'assets' => []];
        $b = ['assets' => [], 'styles' => [], 'pages' => [['name' => 'Page 1']]];

        $this->assertSame(CanonicalHash::of($a), CanonicalHash::of($b));

        $this->assertNotSame(
            CanonicalHash::of($a),
            CanonicalHash::of(['pages' => [['name' => 'Page 2']]]),
        );
    }

    /** An issued document points at a version; that version can never change again. */
    public function test_an_immutable_version_refuses_to_be_saved(): void
    {
        [$user, $template, $version] = $this->createTemplate();

        $version->update(['state' => VersionState::Active]);

        $this->actingAs($user)
            ->putJson($this->versionRoute('versions.update', $template, $version), [
                'project_data' => ['pages' => [['name' => 'Page 1']]],
                'html'         => '<body><div class="page"></div></body>',
                'css'          => '',
            ])
            ->assertConflict();

        $this->assertSame(
            Templates::type('invoice')->initialLayout['html'],
            $version->fresh()->html,
        );
    }

    /**
     * The preview goes through the production pipeline. What matters here is the
     * document handed to the engine, so the engine itself is faked and the request
     * body inspected.
     */
    public function test_the_preview_sends_a_self_contained_document_to_the_engine(): void
    {
        [$user, $template, $version] = $this->createTemplate();

        ['html' => $html, 'css' => $css] = $this->validLayout('invoice');

        $version->update(['html' => $html, 'css' => $css]);

        Http::fake([
            '*/forms/chromium/convert/html' => Http::response('%PDF-1.4 fake', 200, [
                'Content-Type' => 'application/pdf',
            ]),
        ]);

        $response = $this->actingAs($user)
            ->post($this->versionRoute('versions.preview', $template, $version))
            ->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertNotEmpty($response->headers->get('X-Pdf-Hash'));

        $document = $this->capturedDocument();

        // The shared stylesheet, the flags that make @page the only source of page
        // geometry, and the font embedded rather than linked.
        $this->assertStringContainsString('@page', $document);
        $this->assertStringContainsString('width: 210mm', $document);
        $this->assertStringContainsString('data:font/ttf;base64,', $document);
        $this->assertStringContainsString('--margin-left:15mm', $document);

        // Nothing reaches outside the document: the engine has no network.
        $this->assertDoesNotMatchRegularExpression('/url\(\s*[\'"]?(?!data:)[a-z]/i', $document);
    }

    /** The engine failing is a bad gateway, not a validation error on the layout. */
    public function test_an_engine_failure_is_reported_as_such(): void
    {
        [$user, $template, $version] = $this->createTemplate();

        ['html' => $html, 'css' => $css] = $this->validLayout('invoice');

        $version->update(['html' => $html, 'css' => $css]);

        Http::fake(['*/forms/chromium/convert/html' => Http::response('boom', 500)]);

        $this->actingAs($user)
            ->postJson($this->versionRoute('versions.preview', $template, $version))
            ->assertStatus(502);
    }

    /**
     * The editor offers no way to write these, so their presence means the payload was
     * tampered with. Refusing is not a message for the user to act on.
     */
    #[DataProvider('tamperedPayloads')]
    public function test_the_compositor_refuses_a_tampered_layout(string $html, string $css): void
    {
        $this->expectException(UnsafeTemplateException::class);

        app(DocumentComposer::class)->compose($html, $css, Templates::type('invoice'));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function tamperedPayloads(): array
    {
        return [
            'script tag'      => ['<body><script>fetch("/")</script></body>', ''],
            'inline handler'  => ['<body><div onclick="alert(1)"></div></body>', ''],
            'javascript href' => ['<body><a href="javascript:alert(1)">x</a></body>', ''],
            'external image'  => ['<body><div class="page"></div></body>', '.page{background:url(https://example.com/x.png)}'],
            'external font'   => ['<body><div class="page"></div></body>', '@font-face{src:url(/fonts/x.ttf)}'],
        ];
    }

    /**
     * The canvas and the engine must read one stylesheet, not two. The route exists so
     * that the browser gets the same bytes the compositor does, with no build step in
     * between.
     */
    public function test_the_canvas_is_served_the_same_stylesheet_the_compositor_reads(): void
    {
        $user = $this->user();

        $response = $this->actingAs($user)
            ->get(route('templates.stylesheet'))
            ->assertOk();

        $this->assertSame(
            app(DocumentComposer::class)->printStylesheet(),
            $response->getContent(),
        );
    }

    /** The volatile metadata the engine writes must not reach the stored hash. */
    public function test_the_pdf_hash_ignores_the_engine_timestamp(): void
    {
        $first  = "%PDF-1.4\n/CreationDate (D:20260902144006+01'00')\n/ModDate (D:20260902144006+01'00')\nbody";
        $second = "%PDF-1.4\n/CreationDate (D:20260902144007+01'00')\n/ModDate (D:20260902144007+01'00')\nbody";

        $this->assertSame(PdfHash::of($first), PdfHash::of($second));
        $this->assertNotSame(PdfHash::of($first), PdfHash::of($first.'x'));
    }

    /** Drawing a layout and putting it into production are separate permissions. */
    public function test_activation_is_a_permission_of_its_own(): void
    {
        [$user, $template] = $this->createTemplate();

        $this->assertTrue($user->can('update', $template));
        $this->assertTrue($user->can('activate', $template));

        VersionApiTestPolicy::$denied = ['activate'];

        $this->assertTrue($user->can('update', $template));
        $this->assertFalse($user->can('activate', $template));
    }

    /** A version reached through the wrong template is a 404: the URL describes something that does not exist. */
    public function test_a_version_of_another_template_is_a_404(): void
    {
        [$user, , $version] = $this->createTemplate();
        [, $otherTemplate] = $this->createTemplate();

        $this->actingAs($user)
            ->getJson($this->versionRoute('versions.show', $otherTemplate, $version))
            ->assertNotFound();
    }

    /** The body of the request the faked engine received. */
    private function capturedDocument(): string
    {
        $request = Http::recorded()[0][0] ?? null;

        $this->assertNotNull($request, 'The PDF engine was never called.');

        return (string) $request->body();
    }

    private function versionRoute(string $name, Template $template, TemplateVersion $version): string
    {
        return route('templates.'.$name, ['template' => $template->id, 'version' => $version->id]);
    }

    private function user(): User
    {
        $user = new User;
        $user->forceFill(['id' => 1, 'name' => 'Ana']);

        return $user;
    }

    /** @return array{0: User, 1: Template, 2: TemplateVersion} */
    private function createTemplate(): array
    {
        $template = Template::create([
            'type_code' => 'invoice',
            'name'      => 'Fatura A4',
        ]);

        $version = $template->versions()->create([
            'number'       => 1,
            'state'        => VersionState::Draft,
            'project_data' => [],
            'html'         => Templates::type('invoice')->initialLayout['html'],
            'css'          => '',
        ]);

        return [$this->user(), $template, $version];
    }
}

/** A policy that denies only the abilities named in `$denied`, so a test can isolate one. */
class VersionApiTestPolicy
{
    /** @var array<int, string> */
    public static array $denied = [];

    public function __call(string $ability, array $arguments): bool
    {
        return ! in_array($ability, self::$denied, true);
    }
}
