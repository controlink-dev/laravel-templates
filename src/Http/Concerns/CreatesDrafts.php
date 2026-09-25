<?php

namespace Controlink\Templates\Http\Concerns;

use Controlink\Templates\Models\Template;
use Controlink\Templates\Models\TemplateVersion;
use Controlink\Templates\Versions\CanonicalHash;
use Controlink\Templates\Versions\VersionState;

/**
 * Public on purpose: the host's own controller and the catalogue seeder both need to
 * create a draft the same way the editor's endpoints do.
 */
trait CreatesDrafts
{
    /** A new draft from $source, or from the type's initial layout when $source is null. */
    protected function createDraftFrom(Template $template, ?TemplateVersion $source): TemplateVersion
    {
        $type        = $template->type();
        $projectData = $source?->project_data ?? [];

        return $template->versions()->create([
            'number'          => $template->nextVersionNumber(),
            'state'           => VersionState::Draft,
            'project_data'    => $projectData,
            'html'            => $source?->html ?? $type->initialLayout['html'],
            'css'             => $source?->css ?? $type->initialLayout['css'],
            'hash'            => $projectData === [] ? null : CanonicalHash::of($projectData),
            'created_by'      => auth()->id() !== null ? (string) auth()->id() : null,
            'created_by_name' => auth()->user()?->name,
        ]);
    }

    /** The draft a user would be sent to, creating one if every version is immutable. */
    protected function editableVersionFor(Template $template): TemplateVersion
    {
        return $template->versions()
            ->whereIn('state', [VersionState::Draft->value, VersionState::Validated->value])
            ->reorder()->orderByDesc('number')
            ->first() ?? $this->createDraftFrom($template, $template->activeVersion);
    }
}
