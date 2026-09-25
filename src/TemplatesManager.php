<?php

namespace Controlink\Templates;

use Controlink\Templates\Types\TemplateType;
use Controlink\Templates\Types\TypeCatalogue;

class TemplatesManager
{
    public function __construct(private readonly TypeCatalogue $catalogue) {}

    public function type(string $code): TemplateType
    {
        return $this->catalogue->find($code);
    }

    /** @return array<string, TemplateType> */
    public function types(): array
    {
        return $this->catalogue->all();
    }

    /** Absolute path of the package migrations, for hosts that run tenant migrations by `--path`. */
    public function migrationPath(): string
    {
        $path = realpath(__DIR__.'/../database/migrations');

        if ($path === false) {
            throw new \RuntimeException('The package migrations directory is missing.');
        }

        return $path;
    }
}
