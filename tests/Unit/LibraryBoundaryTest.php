<?php

namespace Controlink\Templates\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The package is a thin stateless toolkit: the toolkit facade and the pure pipeline
 * behind it never import HTTP, Eloquent models, the version lifecycle or the type
 * catalogue, and the state/HTTP/data pieces the host owns are gone from `src/`.
 */
class LibraryBoundaryTest extends TestCase
{
    private const FORBIDDEN = [
        'Controlink\Templates\Http',
        'Controlink\Templates\Models',
        'Controlink\Templates\Versions\VersionActivator',
        'Controlink\Templates\Versions\VersionState',
        'Controlink\Templates\Types\TypeCatalogue',
    ];

    public function test_toolkit_and_pure_pipeline_do_not_import_state_or_catalogue(): void
    {
        $src     = realpath(__DIR__.'/../../src');
        $toolkit = glob($src.'/Toolkit/*.php') ?: [];

        $this->assertNotEmpty($toolkit, 'src/Toolkit is missing.');

        $files = array_merge(
            $toolkit,
            glob($src.'/Output/Pdf/*.php') ?: [],
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

    public function test_the_host_owned_pieces_are_gone_from_src(): void
    {
        $src = realpath(__DIR__.'/../../src');

        foreach ([
            'Http',
            'Data',
            'Models/Template.php',
            'Models/TemplateVersion.php',
            'Models/Concerns/HasTemplatesConnection.php',
            'Versions/VersionActivator.php',
            // Contracts the extraction left behind with no consumer: auditing and field
            // encryption are the host's concern now, so the toolkit no longer ships them.
            'Contracts/Auditor.php',
            'Support/NullAuditor.php',
            'Contracts/EncryptedFieldMap.php',
            'Support/EmptyEncryptedFieldMap.php',
        ] as $gone) {
            $this->assertFileDoesNotExist($src.'/'.$gone, "src/{$gone} must not exist.");
        }

        $this->assertFileExists($src.'/Models/Concerns/GuardsIssuableVersion.php');
        $this->assertFileDoesNotExist(realpath(__DIR__.'/../..').'/routes');
        $this->assertFileDoesNotExist(realpath(__DIR__.'/../..').'/database');
    }
}
