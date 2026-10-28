<?php

namespace Controlink\Templates\Output\Zpl;

use Controlink\Templates\Support\PageGeometry;
use GdImage;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Pictures of labels at the printer's resolution, one image per label, one pixel per
 * dot (CGE-98).
 *
 * A composed run of labels (one `.page` each, stacked with no gap) goes to the engine's
 * screenshot route with the device scale that makes a CSS millimetre exactly
 * `Dots::PER_MM` pixels, and the print stylesheet in force, so the picture is the PDF's
 * page; the stack is then cut into labels. One call takes at most `perCall()` labels,
 * and no stack taller than 8000 pixels: further down, Chromium drew an image up to
 * 3 dots short (measured: a 80-dot barcode box came back 77 dots high from 8800 px
 * down a 12000 px stack, and within one dot above 8000 px). The caller composes each
 * run with only its own labels, so a large batch is never sent whole.
 */
final class LabelScreenshots
{
    private const MAX_PER_CALL = 30;

    private const MAX_PIXELS_HIGH = 8000;

    /** CSS pixels per millimetre (96 per inch). */
    private const CSS_PX_PER_MM = 96 / 25.4;

    /** How many labels of this size one screenshot takes: 30, fewer when they are tall (20 of 50 mm). */
    public static function perCall(PageGeometry $label): int
    {
        return max(1, min(self::MAX_PER_CALL, intdiv(self::MAX_PIXELS_HIGH, max(1, Dots::fromMm($label->heightMm)))));
    }

    /**
     * @param  string  $html  a composed run of `count` sheets of `$label`, at most `perCall()`
     * @return list<GdImage> `widthMm*8 x heightMm*8` pixels each, in order
     *
     * @throws RuntimeException when the engine is not configured, refuses, or returns a
     *                          picture smaller than the labels
     */
    public function take(string $html, PageGeometry $label, int $count): array
    {
        if ($count > self::perCall($label)) {
            throw new RuntimeException("One label screenshot takes at most ".self::perCall($label)." labels, not {$count}.");
        }

        return $this->slice($this->shoot($html, $label, $count), $label, $count);
    }

    private function shoot(string $html, PageGeometry $label, int $n): string
    {
        $baseUrl = rtrim((string) config('templates.pdf.gotenberg.url'), '/');

        if ($baseUrl === '') {
            throw new RuntimeException('The PDF engine URL is not configured (templates.pdf.gotenberg.url).');
        }

        $response = Http::asMultipart()
            ->timeout((int) config('templates.pdf.gotenberg.timeout', 30))
            ->attach('files', $html, 'index.html')
            ->post($baseUrl.'/forms/chromium/screenshot/html', [
                ['name' => 'width', 'contents' => (string) (int) ceil($label->widthMm * self::CSS_PX_PER_MM)],
                ['name' => 'height', 'contents' => (string) (int) ceil($n * $label->heightMm * self::CSS_PX_PER_MM)],
                ['name' => 'deviceScaleFactor', 'contents' => (string) (Dots::PER_MM / self::CSS_PX_PER_MM)],
                ['name' => 'emulatedMediaType', 'contents' => 'print'],
                ['name' => 'format', 'contents' => 'png'],
                ['name' => 'clip', 'contents' => 'true'],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('The engine refused the label screenshot: HTTP '.$response->status());
        }

        return $response->body();
    }

    /** @return list<GdImage> */
    private function slice(string $png, PageGeometry $label, int $n): array
    {
        $image = @imagecreatefromstring($png);

        if ($image === false) {
            throw new RuntimeException('The engine returned a label screenshot that is not an image.');
        }

        $width  = Dots::fromMm($label->widthMm);
        $height = Dots::fromMm($label->heightMm);

        if (imagesx($image) < $width || imagesy($image) < Dots::fromMm($n * $label->heightMm)) {
            throw new RuntimeException(sprintf(
                'The label screenshot is %dx%d pixels, smaller than %d labels of %dx%d dots.',
                imagesx($image), imagesy($image), $n, $width, $height,
            ));
        }

        $labels = [];

        for ($i = 0; $i < $n; $i++) {
            $one = imagecrop($image, ['x' => 0, 'y' => Dots::fromMm($i * $label->heightMm), 'width' => $width, 'height' => $height]);

            if ($one === false) {
                throw new RuntimeException('The label screenshot could not be cut into labels.');
            }

            $labels[] = $one;
        }

        return $labels;
    }
}
