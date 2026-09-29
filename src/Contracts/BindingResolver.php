<?php

namespace Controlink\Templates\Contracts;

use Controlink\Templates\Types\TemplateType;

/**
 * Resolves a binding key the type never declared (e.g. a field calculated from a
 * host's schema) so layer-1 validation can accept it.
 *
 * The declared list is always consulted first by the validator; this is asked only
 * for keys the type does not declare. A resolved key is never legal nor mandatory.
 */
interface BindingResolver
{
    /**
     * @return array{key: string, label: string, type: string, mandatory: bool, legal: bool, scope: ?string, default_format: string}|null
     *                                                                                                                                  the binding descriptor, or null when the key is unknown
     */
    public function resolve(string $key, TemplateType $type): ?array;
}
