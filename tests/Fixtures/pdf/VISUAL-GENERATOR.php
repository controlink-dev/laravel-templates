<?php

use App\Services\Currency\MoneyFormatter;
use App\Services\PrintForms\DocumentComposer;
use App\Services\PrintForms\FixtureDataset;
use App\Services\PrintForms\PdfRenderer;
use App\Services\PrintForms\Validation\RenderedPdf;
use Illuminate\Contracts\Console\Kernel;

/**
 * Freezes the visual regression baseline: the layout, the dataset and one reference
 * raster per page.
 *
 * Frozen on purpose. If the test composed from the live catalogue, a change to the
 * document type would change the picture and the test would fail for reasons that have
 * nothing to do with rendering. What it has to detect is the stylesheet, the compositor
 * or the engine drifting — so everything else is nailed down.
 */
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
app()->setLocale('pt_PT');

app()->instance(MoneyFormatter::class, new class extends MoneyFormatter
{
    public function format(float|string|null $value, ?int $decimals = null): string
    {
        return number_format((float) $value, 2, ',', ' ').' €';
    }
});

$out      = __DIR__;
$captured = json_decode(file_get_contents(__DIR__.'/../f2/captured.json'), true);
$type     = config('print_forms.document_types.invoice');

// A short dataset: three pages is enough to cover the bands and the pagination, and a
// hundred and eighty rows of reference rasters is a lot of repository for no more proof.
$dataset           = (new FixtureDataset)->for($type);
$dataset['linhas'] = array_slice($dataset['linhas'], 0, 90);

file_put_contents($out.'/visual-layout.json', json_encode([
    'html'          => $captured['html'],
    'css'           => $captured['css'],
    'dataset'       => $dataset,
    'document_type' => $type,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$document = app(DocumentComposer::class)->compose(
    $captured['html'],
    $captured['css'],
    $type,
    $dataset,
);
$rendered = app(PdfRenderer::class)->render($document);
file_put_contents($out.'/visual-reference.pdf', $rendered['pdf']);

printf("pdf: %d bytes\n", strlen($rendered['pdf']));

RenderedPdf::open($rendered['pdf'], function ($r) use ($out) {
    for ($page = 1; $page <= $r->pageCount(); $page++) {
        /*
         * Downscaled here, with GD, by the same calls the test uses.
         *
         * The first version of these references was resampled with a different library,
         * and the comparison then measured the difference between two resampling
         * algorithms rather than between two renders — 13 levels per pixel of pure
         * noise, on a tolerance of 6.
         *
         * 240px wide keeps block positions and text masses and drops the glyph edges a
         * font update would legitimately move.
         */
        $raster = imagecreatefromstring($r->rasterOf($page, 50));
        imagepalettetotruecolor($raster);

        $scaled = imagescale($raster, 240, (int) round(240 * imagesy($raster) / imagesx($raster)));
        imagedestroy($raster);

        imagepng($scaled, $out."/visual-reference-{$page}.png");
        imagedestroy($scaled);

        printf("page %d reference: %d bytes\n", $page, filesize($out."/visual-reference-{$page}.png"));
    }
});
