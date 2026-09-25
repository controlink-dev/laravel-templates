<?php

namespace Controlink\Templates\Contracts;

use Illuminate\Database\Eloquent\Model;

interface Auditor
{
    /** Events: `templates.activated`, `templates.archived`, `templates.render_failed`. */
    public function record(string $event, Model $subject, array $context = []): void;
}
