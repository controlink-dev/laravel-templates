<?php

namespace Controlink\Templates\Output\Pdf;

use Throwable;
use Zxing\QrReader;

/**
 * Reads a QR code back out of a rasterised page (spec 8.2, R-05).
 *
 * Generating a code and printing it are not the same as it being readable. A code can
 * render perfectly on screen and still fail in a scanner — too small, resampled, drawn
 * over — and that failure is invisible until a customer's invoice is rejected. So the
 * activation check decodes the code from the actual pixels of the actual page, and
 * compares what it says with what it was supposed to say.
 *
 * `khanamiryan/qrcode-detector-decoder` (MIT) is a pure PHP port of ZXing's QR reader:
 * no system library, no extension, nothing to install beyond composer.
 *
 * If the library is absent the reader says so rather than passing: a check that cannot
 * run has not succeeded, and R-05 reports it as a failure so that activation stops.
 */
class CodeReader
{
    /** Whether decoding is possible at all in this installation. */
    public function isAvailable(): bool
    {
        return class_exists(QrReader::class) && extension_loaded('gd');
    }

    /**
     * The content of the code on the page, or null when there is none to read.
     *
     * @param  string  $pngBytes  a rasterised page
     */
    public function read(string $pngBytes): ?string
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $path = tempnam(sys_get_temp_dir(), 'tpl-code-').'.png';
        file_put_contents($path, $pngBytes);

        try {
            $reader = new QrReader($path);
            $text   = $reader->text();

            return is_string($text) && $text !== '' ? $text : null;
        } catch (Throwable) {
            // A page with no code, or one the decoder cannot make sense of, is "nothing
            // read" — the caller decides whether that is a failure for this field.
            return null;
        } finally {
            @unlink($path);
        }
    }
}
