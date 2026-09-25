<?php

namespace Controlink\Templates\Http\Controllers;

use Controlink\Templates\Http\Concerns\CreatesDrafts;
use Controlink\Templates\Http\Controllers\Concerns\ResolvesTemplates;
use Controlink\Templates\Http\Requests\StoreTemplateRequest;
use Controlink\Templates\Http\Resources\TemplateResource;
use Controlink\Templates\Http\Resources\TemplateVersionResource;
use Controlink\Templates\Models\Template;
use Controlink\Templates\Versions\CanonicalHash;
use Controlink\Templates\Versions\VersionState;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class TemplateController extends Controller
{
    use AuthorizesRequests;
    use CreatesDrafts;
    use ResolvesTemplates;

    /**
     * Creates the template together with its first draft, from the type's initial
     * layout. A template with no version would be a dead end: there would be nothing to
     * open, and the editor has no way to conjure a layout of its own.
     */
    public function store(StoreTemplateRequest $request): JsonResponse
    {
        $model = config('templates.models.template');

        /** @var Template $template */
        $template = DB::connection((new $model)->getConnectionName())->transaction(function () use ($request, $model): Template {
            $template = $model::create($request->validated());

            $this->createDraftFrom($template, null);

            return $template->fresh();
        });

        return response()->json([
            'message'  => __('templates::editor.messages.created'),
            'template' => new TemplateResource($template),
            'version'  => new TemplateVersionResource($this->editableVersionFor($template)),
        ], Response::HTTP_CREATED);
    }

    /**
     * A copy of an existing template, in draft.
     *
     * The copy takes the layout of the version **in production**, or the
     * highest-numbered one when nothing is active — the one describing what the host
     * prints today. It does not take the state, the history, the version number or the
     * `layout_revision`: with that null, the copy receives no future catalogue revision.
     *
     * A disabled (soft-deleted) template can still be duplicated — `ResolvesTemplates`
     * resolves with `withTrashed()` and there is no active check here — and the copy is
     * born active. Disabling a template says "do not offer this for new documents", not
     * "do not use this as a starting point".
     */
    public function duplicate(string $template): JsonResponse
    {
        $this->authorize('create', config('templates.models.template'));

        $template = $this->template($template);
        $model    = config('templates.models.template');

        /** @var Template $copy */
        $copy = DB::connection($template->getConnectionName())->transaction(function () use ($template, $model): Template {
            // The version in production, or the highest-numbered one when nothing is
            // active. `versions()` is ordered ascending, so the fallback needs its own
            // descending order — falling back to the oldest version would copy the
            // wrong layout.
            $source = $template->activeVersion
                ?? $template->versions()->reorder()->orderByDesc('number')->first();

            $copy = $model::create([
                'type_code' => $template->type_code,
                'name'      => $this->copyNameFor($template->name),
            ]);

            $projectData = $source?->project_data ?? [];

            $copy->versions()->create([
                'number'          => 1,
                'state'           => VersionState::Draft,
                'project_data'    => $projectData,
                'html'            => $source?->html ?? '',
                'css'             => $source?->css ?? '',
                'hash'            => $projectData === [] ? null : CanonicalHash::of($projectData),
                'layout_revision' => null,
                'created_by'      => auth()->id() !== null ? (string) auth()->id() : null,
                'created_by_name' => auth()->user()?->name,
            ]);

            return $copy->fresh();
        });

        return response()->json([
            'message'  => __('templates::editor.messages.duplicated'),
            'template' => new TemplateResource($copy),
            'version'  => new TemplateVersionResource($this->editableVersionFor($copy)),
        ], Response::HTTP_CREATED);
    }

    /**
     * `"<name> (cópia)"`, then `"(cópia 2)"`, `"(cópia 3)"` … when that is taken.
     *
     * There is no unique index on the name column — the uniqueness rule lives only in
     * `StoreTemplateRequest` — so a second duplication would otherwise produce two rows
     * a user cannot tell apart in a list.
     */
    private function copyNameFor(string $name): string
    {
        $model = config('templates.models.template');

        $maxLength  = 150;
        $maxCounter = 99;

        $reserve     = mb_strlen(__('templates::editor.messages.copy_name_numbered', ['name' => '', 'number' => $maxCounter]));
        $trimmedName = rtrim(mb_substr($name, 0, $maxLength - $reserve));

        $candidate = __('templates::editor.messages.copy_name', ['name' => $trimmedName]);
        $counter   = 1;

        // Bounded: past 99 copies the counter stops advancing and the candidate is
        // returned regardless of collisions — there is no unique index, so a repeated
        // name breaks nothing.
        while ($counter < $maxCounter && $model::withTrashed()->where('name', $candidate)->exists()) {
            $counter++;
            $candidate = __('templates::editor.messages.copy_name_numbered', ['name' => $trimmedName, 'number' => $counter]);
        }

        return mb_substr($candidate, 0, $maxLength);
    }
}
