<?php

namespace Controlink\Templates\Output\Pdf;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * A rendered PDF, opened for measurement (spec 8.2).
 *
 * Layer 2 is the proof: it does not read the layout, it reads the document the engine
 * produced. That means measuring the real thing — where each word landed, how many
 * pages there are, what a page actually looks like as pixels.
 *
 * The measuring is done by **poppler's command line tools**, not by a PHP library.
 * `pdftotext -bbox` reports every word's box in points, which is what rules R-03 and
 * R-04 need and what no PHP parser gives reliably; `pdftoppm` rasterises a page, which
 * is the only way to ask whether a code is actually readable. They are external
 * executables invoked as processes, not linked code.
 *
 * **Deployment requirement:** `poppler-utils` has to be installed wherever this runs.
 * Without it, the render validation fails — deliberately, and with a message that says
 * so, because the alternative is activating a layout nobody checked.
 */
class RenderedPdf
{
    /** @var array<int, array<int, array{text: string, left: float, top: float, right: float, bottom: float}>>|null */
    private ?array $words = null;

    private ?int $pageCount = null;

    private function __construct(private readonly string $path) {}

    /**
     * Write the PDF somewhere poppler can read it, and clean up afterwards.
     *
     * @template T
     *
     * @param  callable(self): T  $callback
     * @return T
     */
    public static function open(string $pdf, callable $callback): mixed
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl-render-').'.pdf';

        file_put_contents($path, $pdf);

        try {
            return $callback(new self($path));
        } finally {
            @unlink($path);

            foreach (glob(dirname($path).'/'.pathinfo($path, PATHINFO_FILENAME).'-*.png') ?: [] as $raster) {
                @unlink($raster);
            }
        }
    }

    public function pageCount(): int
    {
        if ($this->pageCount !== null) {
            return $this->pageCount;
        }

        $output = $this->run(['pdfinfo', $this->path], 'pdfinfo');

        if (preg_match('/^Pages:\s+(\d+)/m', $output, $matches) !== 1) {
            throw new RuntimeException('The rendered PDF does not report a page count.');
        }

        return $this->pageCount = (int) $matches[1];
    }

    /**
     * Every word on a page, with its box in millimetres from the top left.
     *
     * Millimetres because that is the unit the layout, the limits and the whole of this
     * module speak in; points are poppler's business only.
     *
     * @return array<int, array{text: string, left: float, top: float, right: float, bottom: float}>
     */
    public function wordsOn(int $page): array
    {
        return $this->allWords()[$page] ?? [];
    }

    /**
     * The concatenated text of a page, with single spaces.
     *
     * Used by the presence rules, which ask whether a value is on the page at all —
     * and a value may well be broken into several words by the extractor.
     */
    public function textOn(int $page): string
    {
        return implode(' ', array_column($this->wordsOn($page), 'text'));
    }

    /**
     * A page as a greyscale raster, at the given resolution, optionally cropped.
     *
     * The crop is not an optimisation. The QR decoder cannot find a 30mm code inside a
     * 2479x3508 page that is otherwise blank: handed the whole page it reads nothing,
     * handed a 401x401 crop around the slot it reads the same code perfectly. Without
     * the crop, R-05 would fail every document ever rendered.
     *
     * @param  array{left: float, top: float, width: float, height: float}|null  $boxMm  millimetres from the page's top left
     * @return string PNG bytes
     */
    public function rasterOf(int $page, int $dpi = 300, ?array $boxMm = null): string
    {
        $prefix = dirname($this->path).'/'.pathinfo($this->path, PATHINFO_FILENAME).'-raster';

        $this->run([
            'pdftoppm', '-r', (string) $dpi, '-gray', '-png',
            '-f', (string) $page, '-l', (string) $page,
            $this->path, $prefix,
        ], 'pdftoppm');

        foreach (glob($prefix.'*.png') ?: [] as $file) {
            $bytes = (string) file_get_contents($file);
            @unlink($file);

            return $boxMm === null ? $bytes : $this->crop($bytes, $boxMm, $dpi);
        }

        throw new RuntimeException("The rendered PDF page {$page} could not be rasterised.");
    }

    /**
     * @param  array{left: float, top: float, width: float, height: float}  $boxMm
     */
    private function crop(string $png, array $boxMm, int $dpi): string
    {
        $image = imagecreatefromstring($png);

        if ($image === false) {
            throw new RuntimeException('The rasterised page could not be read for cropping.');
        }

        $perMm = $dpi / 25.4;

        // A margin around the slot: a code needs its quiet zone to decode, and the box
        // the layout states is the slot rather than the printed code.
        $margin = 2.0;

        $left   = (int) max(0, floor(($boxMm['left'] - $margin) * $perMm));
        $top    = (int) max(0, floor(($boxMm['top'] - $margin) * $perMm));
        $width  = (int) min(imagesx($image) - $left, ceil(($boxMm['width'] + $margin * 2) * $perMm));
        $height = (int) min(imagesy($image) - $top, ceil(($boxMm['height'] + $margin * 2) * $perMm));

        $cropped = imagecrop($image, ['x' => $left, 'y' => $top, 'width' => $width, 'height' => $height]);
        imagedestroy($image);

        if ($cropped === false) {
            throw new RuntimeException('The rasterised page could not be cropped.');
        }

        ob_start();
        imagepng($cropped);
        imagedestroy($cropped);

        return (string) ob_get_clean();
    }

    /**
     * @return array<int, array<int, array{text: string, left: float, top: float, right: float, bottom: float}>>
     */
    private function allWords(): array
    {
        if ($this->words !== null) {
            return $this->words;
        }

        $xml = $this->run(['pdftotext', '-bbox', $this->path, '-'], 'pdftotext');

        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($document === false) {
            throw new RuntimeException('The text extracted from the rendered PDF could not be read.');
        }

        $perMm = 72 / 25.4;
        $words = [];
        $page  = 0;

        foreach ($document->body->doc->page ?? [] as $pageNode) {
            $page++;

            foreach ($pageNode->word ?? [] as $word) {
                $words[$page][] = [
                    'text'   => trim((string) $word),
                    'left'   => ((float) $word['xMin']) / $perMm,
                    'top'    => ((float) $word['yMin']) / $perMm,
                    'right'  => ((float) $word['xMax']) / $perMm,
                    'bottom' => ((float) $word['yMax']) / $perMm,
                ];
            }
        }

        return $this->words = $words;
    }

    /**
     * @param  array<int, string>  $command
     *
     * @throws RuntimeException when the tool is missing or fails
     */
    private function run(array $command, string $tool): string
    {
        $process = new Process($command);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                "The rendered PDF could not be inspected: `{$tool}` failed or is not installed "
                .'(poppler-utils). '.trim($process->getErrorOutput()),
            );
        }

        return $process->getOutput();
    }
}
