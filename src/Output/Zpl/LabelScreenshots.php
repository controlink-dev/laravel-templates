<?php

namespace Controlink\Templates\Output\Zpl;

use Controlink\Templates\Support\PageGeometry;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Pictures of a batch of labels at the printer's resolution, one PNG per label, one
 * pixel per dot (CGE-98).
 *
 * The composed batch (one `.page` per label, stacked with no gap) goes to the engine's
 * screenshot route with the device scale that makes a CSS millimetre exactly
 * `Dots::PER_MM` pixels, and the print stylesheet in force, so the picture is the PDF's
 * page. The stack is cut into labels here. One call takes at most 30 labels, fewer when
 * they are tall: a Chromium screenshot is capped at 16384 pixels high; the others are
 * hidden in that call and shown in the next.
 */
final class LabelScreenshots
{
    private const MAX_PER_CALL = 30;

    private const MAX_PIXELS_HIGH = 16384;

    /** CSS pixels per millimetre (96 per inch). */
    private const CSS_PX_PER_MM = 96 / 25.4;

    /**
     * @param  string  $html  the composed batch, `count` sheets of `$label`
     * @return list<string> PNGs of `widthMm*8 x heightMm*8` pixels, in order
     *
     * @throws RuntimeException when the engine is not configured, refuses, or returns a
     *                          picture smaller than the labels
     */
    public function take(string $html, PageGeometry $label, int $count): array
    {
        $height = Dots::fromMm($label->heightMm);
        $perCall = max(1, min(self::MAX_PER_CALL, intdiv(self::MAX_PIXELS_HIGH, max(1, $height))));

        $pngs = [];

        for ($first = 0; $first < $count; $first += $perCall) {
            $n    = min($perCall, $count - $first);
            $pngs = [...$pngs, ...$this->slice($this->shoot($this->only($html, $first, $n), $label, $n), $label, $n)];
        }

        return $pngs;
    }

    /** The batch with only labels `first` to `first + n - 1` (0-based) laid out. */
    private function only(string $html, int $first, int $n): string
    {
        $style = '<style>body>.page{display:none}'
            .'body>.page:nth-child(n+'.($first + 1).'):nth-child(-n+'.($first + $n).'){display:block}</style>';

        return str_replace('</head>', $style.'</head>', $html);
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

    /** @return list<string> */
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

        $pngs = [];

        for ($i = 0; $i < $n; $i++) {
            $one = imagecrop($image, ['x' => 0, 'y' => Dots::fromMm($i * $label->heightMm), 'width' => $width, 'height' => $height]);

            if ($one === false) {
                throw new RuntimeException('The label screenshot could not be cut into labels.');
            }

            ob_start();
            imagepng($one, null, 1);
            $pngs[] = (string) ob_get_clean();
        }

        return $pngs;
    }
}
