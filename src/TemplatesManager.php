<?php

namespace Controlink\Templates;

class TemplatesManager
{
    public function migrationPath(): string
    {
        $path = realpath(__DIR__.'/../database/migrations');

        if ($path === false) {
            throw new \RuntimeException('The package migrations directory is missing.');
        }

        return $path;
    }
}
