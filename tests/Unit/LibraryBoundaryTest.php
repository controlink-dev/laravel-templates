<?php

namespace Controlink\Templates\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The stateless library must not know about state or the catalogue: the toolkit facade
 * and the pure pipeline behind it never import HTTP, Eloquent models, the version
 * lifecycle or the type catalogue. `src/Data` is covered for the same imports but is NOT
 * fully stateless: `DocumentGraph` reads `Contracts\TenantResolver` and `templates.*`
 * config, `NodeFields` reads `Contracts\EncryptedFieldMap`, `TableColumns` reads the DB
 * schema. Those are host-state dependencies, not imports of the forbidden symbols.
 */
class LibraryBoundaryTest extends TestCase
{
    private const FORBIDDEN = [
        'Controlink\\Templates\\Http',
        'Controlink\\Templates\\Models',
        'Controlink\\Templates\\Versions\\VersionActivator',
        'Controlink\\Templates\\Versions\\VersionState',
        'Controlink\\Templates\\Types\\TypeCatalogue',
    ];

    public function test_toolkit_and_pure_pipeline_do_not_import_state_or_catalogue(): void
    {
        $src     = realpath(__DIR__.'/../../src');
        $toolkit = glob($src.'/Toolkit/*.php') ?: [];

        $this->assertNotEmpty($toolkit, 'src/Toolkit is missing.');

        $files = array_merge(
            $toolkit,
            glob($src.'/Output/Pdf/*.php') ?: [],
            glob($src.'/Data/*.php') ?: [],
            [$src.'/Versions/CanonicalHash.php'],
        );

        $offenders = [];

        foreach ($files as $file) {
            $contents = file_get_contents($file);

            foreach (self::FORBIDDEN as $symbol) {
                if (preg_match('/^use '.preg_quote($symbol, '/').'\b/m', $contents) === 1
                    || str_contains($contents, '\\'.$symbol)) {
                    $offenders[] = basename($file).' -> '.$symbol;
                }
            }
        }

        $this->assertSame([], $offenders, implode(', ', $offenders));
    }
}
