<?php

namespace Controlink\Templates\Support;

use Controlink\Templates\Contracts\Auditor;
use Illuminate\Database\Eloquent\Model;

final class NullAuditor implements Auditor
{
    public function record(string $event, Model $subject, array $context = []): void
    {
    }
}
