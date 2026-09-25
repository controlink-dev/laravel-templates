<?php

namespace Controlink\Templates;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Controlink\Templates\Types\TemplateType type(string $code)
 * @method static array<string, \Controlink\Templates\Types\TemplateType> types()
 * @method static string migrationPath()
 */
class Templates extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TemplatesManager::class;
    }
}
