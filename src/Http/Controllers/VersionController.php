<?php

namespace Controlink\Templates\Http\Controllers;

use Controlink\Templates\Data\FixtureDataset;
use Controlink\Templates\Exceptions\UnsafeTemplateException;
use Controlink\Templates\Http\Concerns\CreatesDrafts;
use Controlink\Templates\Http\Controllers\Concerns\ResolvesTemplates;
use Controlink\Templates\Http\Requests\UpdateVersionRequest;
use Controlink\Templates\Http\Resources\TemplateResource;
use Controlink\Templates\Http\Resources\TemplateVersionResource;
use Controlink\Templates\Models\TemplateVersion;
use Controlink\Templates\Output\Pdf\DocumentComposer;
use Controlink\Templates\Output\Pdf\LayoutValidator;
use Controlink\Templates\Output\Pdf\PdfRenderer;
use Controlink\Templates\Versions\CanonicalHash;
use Controlink\Templates\Versions\VersionActivator;
use Controlink\Templates\Versions\VersionState;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Load, save, preview and activate one version's layout.
 *
 * Every action resolves its `{template}`/`{version}` ids itself, through
 * `ResolvesTemplates` — there is no route model binding (see `routes/api.php`).
 */
class VersionController extends Controller
{
    use AuthorizesRequests;
    use CreatesDrafts;
    use ResolvesTemplates;

    /** Load a version's layout. */
    public function show(string $template, string $version): TemplateVersionResource
    {
        $template = $this->template($template);
        $version  = $this->version($template, $version);

        $this->authorize('view', $template);

        return new TemplateVersionResource($version);
    }

    /**
     * A new draft, from the active version if there is one, otherwise from the
     * document type's initial layout.
     */
    public function store(string $template): JsonResponse
    {
        $template = $this->template($template);

        $this->authorize('update', $template);

        $version = DB::connection($template->getConnectionName())->transaction(
            fn (): TemplateVersion => $this->createDraftFrom($template, $template->activeVersion),
        );

        return response()->json([
            'message' => __('templates::editor.messages.draft_created'),
            'version' => new TemplateVersionResource($version),
        ], HttpResponse::HTTP_CREATED);
    }

    /**
     * Save a version's layout.
     *
     * An immutable version is refused with 409 rather than 422: nothing the user could
     * retype would make it acceptable — the answer is a new draft.
     */
    public function update(UpdateVersionRequest $request, string $template, string $version): JsonResponse
    {
        $template = $this->template($template);
        $version  = $this->version($template, $version);

        $this->authorize('update', $template);

        if (! $version->isEditable()) {
            return response()->json([
                'message' => __('templates::editor.errors.version_immutable'),
            ], HttpResponse::HTTP_CONFLICT);
        }

        $projectData = $request->projectData();
        $html        = $request->validated('html');
        $css         = (string) $request->validated('css');

        /*
         * Layer 1. Nothing is written when it fails: a version on disk always means a
         * layout that passed the static rules.
         */
        $problems = app(LayoutValidator::class)->validate(
            $projectData,
            $template->type(),
            $html,
            $css,
        );

        if ($problems !== []) {
            return response()->json([
                'message'  => __('templates::editor.errors.validation_failed'),
                'problems' => $problems,
            ], HttpResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $version->update([
            'project_data' => $projectData,
            'html'         => $html,
            'css'          => $css,
            'hash'         => CanonicalHash::of($projectData),
            // A layout that passed the static rules is `validated`; only the render
            // pass can take it further.
            'state' => VersionState::Validated,
        ]);

        return response()->json([
            'message' => __('templates::editor.messages.saved'),
            'version' => new TemplateVersionResource($version->fresh()),
        ]);
    }

    /**
     * Render the version through the production pipeline and return the PDF.
     *
     * The preview uses the derived HTML and CSS the editor last saved, not a freshly
     * exported one, so that what is previewed is what is stored.
     */
    public function preview(string $template, string $version): Response|JsonResponse
    {
        $template = $this->template($template);
        $version  = $this->version($template, $version);

        $this->authorize('view', $template);

        if ($version->html === null) {
            return response()->json([
                'message' => __('templates::editor.errors.nothing_to_preview'),
            ], HttpResponse::HTTP_CONFLICT);
        }

        $type     = $template->type();
        $composer = app(DocumentComposer::class);

        try {
            $document = $composer->compose(
                (string) $version->html,
                (string) $version->css,
                $type,
                // The preview renders the fixture, whose values are the longest
                // plausible ones and whose lines are repeated until the document
                // paginates.
                app(FixtureDataset::class)->for($type),
            );

            $rendered = app(PdfRenderer::class)->render($document, $type);
        } catch (UnsafeTemplateException) {
            return response()->json([
                'message' => __('templates::editor.errors.unsafe_template'),
            ], HttpResponse::HTTP_UNPROCESSABLE_ENTITY);
        } catch (RuntimeException $exception) {
            report($exception);

            return response()->json([
                'message' => __('templates::editor.errors.render_failed'),
            ], HttpResponse::HTTP_BAD_GATEWAY);
        }

        return response($rendered['pdf'], HttpResponse::HTTP_OK, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.Str::slug($template->name).'-v'.$version->number.'.pdf"',
            'X-Pdf-Hash'          => $rendered['hash'],
        ]);
    }

    /**
     * Put a version into production.
     *
     * `activate` is its own permission: drawing a layout and binding the host to it are
     * different decisions, and this is the act that makes every document issued
     * afterwards point at this version.
     */
    public function activate(string $template, string $version): JsonResponse
    {
        $template = $this->template($template);
        $version  = $this->version($template, $version);

        $this->authorize('activate', $template);

        $problems = app(VersionActivator::class)->activate($template, $version);

        if ($problems !== []) {
            return response()->json([
                'message'  => __('templates::editor.errors.activation_failed'),
                'problems' => $problems,
            ], HttpResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'message'  => __('templates::editor.messages.activated'),
            'version'  => new TemplateVersionResource($version->fresh()),
            'template' => new TemplateResource($template->fresh()),
        ]);
    }
}
