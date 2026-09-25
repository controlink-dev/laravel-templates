<?php

namespace Controlink\Templates\Http\Controllers\Concerns;

use Controlink\Templates\Models\Template;
use Controlink\Templates\Models\TemplateVersion;
use Symfony\Component\HttpFoundation\Response;

/**
 * Loads a template and its version from a route's plain string ids.
 *
 * There is no route model binding: a host's tenant middleware may switch the database
 * connection after routing has already resolved the route, so a bound model here could
 * be read from the wrong connection. Controllers resolve ids themselves, after the
 * host's own middleware has run.
 */
trait ResolvesTemplates
{
    protected function template(string $id): Template
    {
        $model = config('templates.models.template');

        return $model::withTrashed()->whereKey($id)->firstOrFail();
    }

    /** A version reached through the wrong template is a 404: the URL describes something that does not exist. */
    protected function version(Template $template, string $id): TemplateVersion
    {
        $model   = config('templates.models.version');
        $version = $model::whereKey($id)->firstOrFail();

        abort_if($version->template_id !== $template->getKey(), Response::HTTP_NOT_FOUND);

        return $version;
    }
}
