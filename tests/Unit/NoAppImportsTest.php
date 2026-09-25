<?php

namespace Controlink\Templates\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The package must never reach into a host application. Any `use App\…` in `src/` is a
 * coupling the extraction failed to cut.
 */
class NoAppImportsTest extends TestCase
{
    public function test_src_never_imports_the_host_application(): void
    {
        $offenders = [];
        $files     = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../src'));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if (preg_match('/^use App\\\\/m', $contents) === 1 || str_contains($contents, '\\App\\')) {
                $offenders[] = substr($file->getPathname(), strlen(__DIR__.'/../../'));
            }
        }

        $this->assertSame([], $offenders, 'These files import the host application: '.implode(', ', $offenders));
    }
}
