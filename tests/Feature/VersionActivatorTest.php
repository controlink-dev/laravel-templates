<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Contracts\Auditor;
use Controlink\Templates\Exceptions\ImmutableVersionException;
use Controlink\Templates\Models\Template;
use Controlink\Templates\Models\TemplateVersion;
use Controlink\Templates\Output\Pdf\RenderValidator;
use Controlink\Templates\Tests\Concerns\BuildsLayouts;
use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Types\TemplateType;
use Controlink\Templates\Versions\CanonicalHash;
use Controlink\Templates\Versions\VersionActivator;
use Controlink\Templates\Versions\VersionState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

/**
 * Phase F5: activation, the state machine, immutability and the audit trail
 * (spec 4.1, 10).
 *
 * The render rules themselves are tested against stored PDFs in RenderValidatorTest.
 * Here the validator is stubbed on purpose, because what is under test is what happens
 * around it — that a pass promotes the version and archives the one it replaces, that a
 * failure changes nothing, and that both leave a record. A refused activation nobody
 * can explain later is as bad as a wrong one.
 *
 * Ported from the host's original activation test (10 methods, pre-extraction).
 * Kept: the four
 * activation cases and the three model immutability cases (the immutability ones
 * overlap TemplateVersionTest but exercise the same guard against the activation
 * fixtures used here). Dropped, all host-specific and out of this package's scope:
 * permission enforcement (no HTTP layer or RBAC exists yet — Task 12), the
 * save-diff/`Auditable` assertion (the package carries no model auditing trait), and
 * the `config('audit.entities')` registration check (host-only config).
 */
class VersionActivatorTest extends TestCase
{
    use BuildsLayouts;
    use RefreshDatabase;

    private const DOCUMENT_TYPE = 'invoice';

    /**
     * @var object{events: array<int, array{event: string, subject: Model, context: array<string, mixed>}>}
     */
    private object $audit;

    protected function setUp(): void
    {
        parent::setUp();

        // GotenbergEngine throws before ever reaching the (faked) HTTP call when this
        // is empty.
        config(['templates.pdf.gotenberg.url' => 'http://localhost:3100']);

        $this->audit = new class implements Auditor
        {
            /** @var array<int, array{event: string, subject: Model, context: array<string, mixed>}> */
            public array $events = [];

            public function record(string $event, Model $subject, array $context = []): void
            {
                $this->events[] = ['event' => $event, 'subject' => $subject, 'context' => $context];
            }
        };

        $this->app->instance(Auditor::class, $this->audit);
    }

    /* -----------------------------------------------------------------------
     * Activation
     * -------------------------------------------------------------------- */

    public function test_activating_promotes_the_version_and_records_it(): void
    {
        [$template, $version] = $this->createTemplate();

        $this->fakeRenderValidator([]);

        $problems = app(VersionActivator::class)->activate($template, $version);

        $this->assertSame([], $problems);

        $activated = $version->fresh();

        $this->assertSame(VersionState::Active, $activated->state);
        // No authenticated user in this test: `auth()->id()` is null in the testbench.
        $this->assertNull($activated->activated_by);
        $this->assertNotNull($activated->activated_at);

        // The template points at it: without this there is no way to say which layout
        // a document should be issued with.
        $this->assertSame($version->id, $template->fresh()->active_version_id);

        $entry = $this->firstEvent('templates.activated');

        $this->assertNotNull($entry, 'Activation left no audit record (RA-01).');
        $this->assertSame($version->id, $entry['subject']->getKey());

        // The hash of the PDF the validation actually passed, so a regenerated
        // document has something known-good to be compared against (RA-05).
        $this->assertArrayHasKey('pdf_hash', $entry['context']);
        $this->assertArrayHasKey('project_hash', $entry['context']);

        // The layout itself never travels in the audit context — only its hash.
        $this->assertArrayNotHasKey('project_data', $entry['context']);
        $this->assertArrayNotHasKey('html', $entry['context']);
    }

    /**
     * `activated_by` is a string column (users live in the host's own database, not
     * this one — see the migration's docblock), so `auth()->id()` has to be cast, not
     * stored as whatever type the host's auth guard happens to hand back.
     */
    public function test_activating_records_who_activated_it(): void
    {
        [$template, $version] = $this->createTemplate();

        $this->fakeRenderValidator([]);

        $user = new \Illuminate\Foundation\Auth\User;
        $user->forceFill(['id' => 7, 'name' => 'Ana']);

        $this->actingAs($user);

        $problems = app(VersionActivator::class)->activate($template, $version);

        $this->assertSame([], $problems);

        $activated = $version->fresh();

        $this->assertSame('7', $activated->activated_by);
        $this->assertIsString($activated->activated_by);
        $this->assertSame('Ana', $activated->activated_by_name);
    }

    /** Only one version is active at a time, so activating archives the incumbent. */
    public function test_activating_archives_the_previous_version(): void
    {
        [$template, $first] = $this->createTemplate();

        $this->fakeRenderValidator([]);

        app(VersionActivator::class)->activate($template, $first);

        $second = $this->addDraft($template, 2);

        app(VersionActivator::class)->activate($template, $second);

        $this->assertSame(VersionState::Archived, $first->fresh()->state);
        $this->assertSame(VersionState::Active, $second->fresh()->state);
        $this->assertSame($second->id, $template->fresh()->active_version_id);

        $archived = $this->firstEvent('templates.archived');

        $this->assertNotNull($archived, 'Archiving left no audit record (RA-01).');
        $this->assertSame($second->id, $archived['context']['replaced_by']);
    }

    /**
     * A failing render validation changes nothing, and the record says which rule and
     * which page — which is the whole point of recording it (spec 8.2, last line).
     */
    public function test_a_failing_render_validation_activates_nothing_and_is_recorded(): void
    {
        [$template, $version] = $this->createTemplate();

        $this->fakeRenderValidator([
            ['code' => 'R-03', 'page' => 2, 'message' => 'ATCUD falta na página 2.'],
        ]);

        $problems = app(VersionActivator::class)->activate($template, $version);

        $this->assertSame('R-03', $problems[0]['code']);
        $this->assertSame(2, $problems[0]['page']);

        $this->assertSame(VersionState::Draft, $version->fresh()->state);
        $this->assertNull($template->fresh()->active_version_id);

        $entry = $this->firstEvent('templates.render_failed');

        $this->assertNotNull($entry, 'A refused activation left no audit record.');
        $this->assertSame('R-03', $entry['context']['problems'][0]['code']);
        $this->assertSame(2, $entry['context']['problems'][0]['page']);
    }

    /**
     * Activating an already active version would silently rewrite history.
     *
     * The validator is faked and no compose/render/audit call is allowed to happen at
     * all: without that, a broken `isEditable()` guard would fall through to
     * `compose()`/`render()`, hit the (here, unreachable) engine, and the caught
     * `Throwable` would land on the exact same `R-01` code — passing the assertion for
     * the wrong reason. Asserting `$this->audit->events` stays empty is what actually
     * proves the guard fired before any of that ran: a fall-through would have recorded
     * `templates.render_failed`.
     */
    public function test_an_immutable_version_cannot_be_activated_again(): void
    {
        [$template, $version] = $this->createTemplate();

        $version->forceFill(['state' => VersionState::Active])->save();

        $this->fakeRenderValidator([]);

        $problems = app(VersionActivator::class)->activate($template, $version);

        $this->assertNotSame([], $problems);
        $this->assertSame('R-01', $problems[0]['code']);
        $this->assertSame(__('templates::editor.errors.version_immutable'), $problems[0]['message']);

        $this->assertSame([], $this->audit->events, 'The immutability guard should refuse before any render or audit call.');
    }

    /* -----------------------------------------------------------------------
     * Immutability (RA-03)
     * -------------------------------------------------------------------- */

    public function test_the_model_refuses_to_change_an_active_version_layout(): void
    {
        [, $version] = $this->createTemplate();

        $version->forceFill(['state' => VersionState::Active])->save();

        $this->expectException(ImmutableVersionException::class);

        $version->forceFill(['html' => '<body><div class="page"></div></body>'])->save();
    }

    public function test_the_model_refuses_to_change_an_archived_version_layout(): void
    {
        [, $version] = $this->createTemplate();

        $version->forceFill(['state' => VersionState::Archived])->save();

        $this->expectException(ImmutableVersionException::class);

        $version->forceFill(['project_data' => ['pages' => []]])->save();
    }

    /**
     * The state and the activation metadata are explicitly not frozen: activation and
     * archiving are exactly what has to be able to write them.
     */
    public function test_an_immutable_version_can_still_change_state(): void
    {
        [, $version] = $this->createTemplate();

        $version->forceFill(['state' => VersionState::Active])->save();
        $version->forceFill(['state' => VersionState::Archived])->save();

        $this->assertSame(VersionState::Archived, $version->fresh()->state);
    }

    /* -----------------------------------------------------------------------
     * Helpers
     * -------------------------------------------------------------------- */

    /** @return array{event: string, subject: Model, context: array<string, mixed>}|null */
    private function firstEvent(string $event): ?array
    {
        foreach ($this->audit->events as $entry) {
            if ($entry['event'] === $event) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{code: string, page: int|null, message: string}>  $problems
     */
    private function fakeRenderValidator(array $problems): void
    {
        $this->app->instance(RenderValidator::class, new class($problems) extends RenderValidator
        {
            /** @param array<int, array{code: string, page: int|null, message: string}> $problems */
            public function __construct(private readonly array $problems) {}

            public function validate(
                string $pdf,
                TemplateType $type,
                array $dataset,
                float $seconds,
                array $codeSlotBoxes = [],
            ): array {
                return $this->problems;
            }
        });

        // The engine is faked too: what is under test is the state machine around the
        // validation, not the rendering.
        Http::fake([
            '*/forms/chromium/convert/html' => Http::response('%PDF-1.4 fake', 200),
        ]);
    }

    private function addDraft(Template $template, int $number): TemplateVersion
    {
        ['project_data' => $projectData, 'html' => $html, 'css' => $css] = $this->validLayout(self::DOCUMENT_TYPE);

        return $template->versions()->create([
            'number'       => $number,
            'state'        => VersionState::Draft,
            'project_data' => $projectData,
            'html'         => $html,
            'css'          => $css,
            'hash'         => CanonicalHash::of($projectData),
        ]);
    }

    /** @return array{0: Template, 1: TemplateVersion} */
    private function createTemplate(): array
    {
        $template = Template::create([
            'type_code' => self::DOCUMENT_TYPE,
            'name'      => 'Fatura A4',
        ]);

        return [$template, $this->addDraft($template, 1)];
    }
}
