<?php

namespace Controlink\Templates\Support;

use Controlink\Templates\Contracts\BindingResolver;
use Controlink\Templates\Types\TemplateType;

/** Default: no schema, so only declared bindings are valid. */
final class NullBindingResolver implements BindingResolver
{
    public function resolve(string $key, TemplateType $type): ?array
    {
        return null;
    }
}
