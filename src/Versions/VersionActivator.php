<?php

namespace Controlink\Templates\Versions;

use Controlink\Templates\Contracts\Auditor;
use Controlink\Templates\Data\FixtureDataset;
use Controlink\Templates\Models\Template;
use Controlink\Templates\Models\TemplateVersion;
use Controlink\Templates\Output\Pdf\DocumentComposer;
use Controlink\Templates\Output\Pdf\LayoutValidator;
use Controlink\Templates\Output\Pdf\PdfRenderer;
use Controlink\Templates\Output\Pdf\RenderValidator;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Puts a version into production (spec 4.1, 8.2, 10).
 *
 * Activation is the act that binds a template to a layout: from here on, every
 * document issued points at this version, and the version can never change again. So
 * it is the one place where the render validation runs — layer 1 is the fast filter at
 * save time, this is the proof.
 *
 * Nothing is activated on a failure, and the failure is written to the audit trail
 * either way. A refused activation that leaves no record is a refusal nobody can
 * explain later.
 *
 * This class lives in `Versions\`, the core namespace, and legitimately depends on
 * `Output\Pdf\*`: it is the orchestration point that binds the render driver to the
 * version lifecycle, and its collaborators are the PDF pipeline.
 */
class VersionActivator
{
    public function __construct(
        private readonly DocumentComposer $composer,
        private readonly PdfRenderer $renderer,
        private readonly LayoutValidator $layout,
        private readonly RenderValidator $render,
        private readonly FixtureDataset $fixtures,
        private readonly Auditor $audit,
    ) {}

    /**
     * @return array<int, array{code: string, page: int|null, message: string}> empty when the version was activated
     */
    public function activate(Template $template, TemplateVersion $version): array
    {
        $type = $template->type();

        if (! $version->isEditable()) {
            // Already active, or archived. Both are immutable, and activating an
            // archived version again would silently rewrite history.
            return [[
                'code'    => 'R-01',
                'page'    => null,
                'message' => __('templates::editor.errors.version_immutable'),
            ]];
        }

        $dataset = $this->fixtures->for($type);

        try {
            $started  = microtime(true);
            $document = $this->composer->compose(
                (string) $version->html,
                (string) $version->css,
                $type,
                $dataset,
            );
            $rendered = $this->renderer->render($document, $type);
            $seconds  = microtime(true) - $started;
        } catch (Throwable $error) {
            // R-01: the render has to complete at all. A Twig policy violation or an
            // engine failure lands here, and neither is something the user can fix by
            // moving a block — so it is recorded verbatim.
            $problems = [[
                'code'    => 'R-01',
                'page'    => null,
                'message' => __('templates::editor.render.failed', ['reason' => $error->getMessage()]),
            ]];

            $this->recordFailure($version, $problems);

            return $problems;
        }

        $problems = $this->render->validate(
            $rendered['pdf'],
            $type,
            $dataset,
            $seconds,
            $this->layout->codeSlotBoxes((array) $version->project_data, $type),
        );

        if ($problems !== []) {
            $this->recordFailure($version, $problems);

            return $problems;
        }

        $this->promote($template, $version, $rendered['hash'], $seconds);

        return [];
    }

    /**
     * Activate this version and archive whichever one it replaces, in one transaction.
     *
     * Both halves or neither: a template with two active versions has no defined
     * layout, and a template with none cannot issue anything.
     */
    private function promote(Template $template, TemplateVersion $version, string $pdfHash, float $seconds): void
    {
        DB::connection($template->getConnectionName())->transaction(function () use ($template, $version, $pdfHash, $seconds): void {
            // Read through the relation query, not the `activeVersion` magic property:
            // a caller that activates twice on the same `$template` instance (a job, a
            // console command — anything but a fresh route-bound model per request)
            // would otherwise see Eloquent's cached relation value from before this
            // template had an active version at all, archive nothing, and leave two
            // versions active.
            $previous = $template->activeVersion()->first();

            if ($previous !== null && $previous->getKey() !== $version->getKey()) {
                $previous->forceFill(['state' => VersionState::Archived])->save();

                $this->audit->record(
                    event: 'templates.archived',
                    subject: $previous,
                    context: ['replaced_by' => $version->getKey(), 'number' => $previous->number],
                );
            }

            $userId = auth()->id();

            $version->forceFill([
                'state'             => VersionState::Active,
                'activated_by'      => $userId === null ? null : (string) $userId,
                'activated_by_name' => auth()->user()?->name,
                'activated_at'      => now(),
            ])->save();

            $template->forceFill(['active_version_id' => $version->getKey()])->save();

            $this->audit->record(
                event: 'templates.activated',
                subject: $version,
                context: [
                    'number'       => $version->number,
                    'project_hash' => $version->hash,
                    // The hash of the PDF the validation actually passed, so that a
                    // regenerated document can be compared against a known good one.
                    'pdf_hash'       => $pdfHash,
                    'render_seconds' => round($seconds, 3),
                    'previous'       => $previous?->getKey(),
                ],
            );
        });
    }

    /**
     * @param  array<int, array{code: string, page: int|null, message: string}>  $problems
     */
    private function recordFailure(TemplateVersion $version, array $problems): void
    {
        $this->audit->record(
            event: 'templates.render_failed',
            subject: $version,
            context: [
                'number' => $version->number,
                // The rule and the page, which is what makes the record worth keeping
                // (spec 8.2, last line).
                'problems' => array_map(
                    fn (array $problem): array => [
                        'code'    => $problem['code'],
                        'page'    => $problem['page'],
                        'message' => $problem['message'],
                    ],
                    $problems,
                ),
            ],
        );
    }
}
