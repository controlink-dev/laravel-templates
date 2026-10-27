<?php

namespace Controlink\Templates\Output\EscPos;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Controlink\Templates\Support\PageGeometry;
use GdImage;
use Throwable;

/**
 * A `Receipt` as Epson ESC/POS bytes (CGE-97).
 *
 * At 203 dpi (8 dots/mm) a Font A character is 12 dots: the area used is `columns × 12`
 * dots, centred on the printable width (576 dots from 76 mm of paper, else 384), so the
 * 80 mm receipt keeps the 40 columns of the PDF instead of the 48 the printer allows.
 * Alignment is already in the lines (spaces), so justification stays `ESC a 0`, but for
 * the QR and the images, which are centred.
 */
final class EscPosEncoder
{
    private const DOTS_PER_CHARACTER = 12;

    /** The legal minimum side of the QR: 30 mm at 8 dots/mm. */
    private const QR_MIN_DOTS = 240;

    public function encode(Receipt $receipt, PageGeometry $geometry): string
    {
        $printable = $geometry->widthMm >= 76 ? 576 : 384;
        $area      = min($printable, $receipt->columns * self::DOTS_PER_CHARACTER);
        $margin    = intdiv($printable - $area, 2);

        // Initialise, CP858, left margin, print area width.
        $out = "\x1B@\x1Bt\x13\x1DL".self::word($margin)."\x1DW".self::word($area);

        foreach ($receipt->blocks as $block) {
            $out .= match ($block['kind']) {
                'line'  => $this->line($block),
                'qr'    => $this->qr((string) $block['payload'], $area),
                'image' => $this->image((string) $block['png'], $area),
                default => '',
            };
        }

        // Feed 4 lines, then a partial cut (with its own feed to the cutter).
        return $out."\x1Bd\x04\x1DVB\x00";
    }

    /** @param array{text: string, double: bool, bold: list<array{0: int, 1: int}>} $line */
    private function line(array $line): string
    {
        $text = $line['text'];
        $out  = "\x1D!".($line['double'] ? "\x11" : "\x00");
        $at   = 0;

        $bold = $line['bold'];
        usort($bold, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        foreach ($bold as [$start, $end]) {
            $start = max($start, $at);

            if ($end <= $start) {
                continue;
            }

            $out .= Cp858::encode(mb_substr($text, $at, $start - $at, 'UTF-8'))
                ."\x1BE\x01".Cp858::encode(mb_substr($text, $start, $end - $start, 'UTF-8'))."\x1BE\x00";
            $at = $end;
        }

        return $out.Cp858::encode(mb_substr($text, $at, null, 'UTF-8'))."\n";
    }

    /** QR model 2, correction M, centred; the payload is sent as it is, byte for byte. */
    private function qr(string $payload, int $area): string
    {
        if ($payload === '') {
            return '';
        }

        $n      = self::matrixWidth($payload);
        $module = max(1, min(16, (int) ceil(self::QR_MIN_DOTS / $n), intdiv($area, $n)));

        return "\x1Ba\x01"
            ."\x1D(k\x04\x00\x31\x41\x32\x00"          // 165: model 2
            ."\x1D(k\x03\x00\x31\x43".chr($module)    // 167: module size
            ."\x1D(k\x03\x00\x31\x45\x31"              // 169: error correction M
            ."\x1D(k".self::word(strlen($payload) + 3)."\x31\x50\x30".$payload // 180: store
            ."\x1D(k\x03\x00\x31\x51\x30"              // 181: print
            ."\x1Ba\x00";
    }

    /** The side of the QR matrix in modules (no quiet zone), as the printer will build it. */
    private static function matrixWidth(string $payload): int
    {
        foreach (['ISO-8859-1', 'UTF-8'] as $encoding) {
            try {
                return Encoder::encode($payload, ErrorCorrectionLevel::M(), $encoding)->getMatrix()->getWidth();
            } catch (Throwable) {
                continue;
            }
        }

        // Version 40, the largest: the smallest module, never wider than the area.
        return 177;
    }

    /** A raster image (`GS v 0`), black where luminance is under 50%, no wider than the area. */
    private function image(string $png, int $area): string
    {
        $image = @imagecreatefromstring($png);

        if (! $image instanceof GdImage) {
            return '';
        }

        imagepalettetotruecolor($image);

        if (imagesx($image) > $area) {
            $scaled = imagescale($image, $area);
            $image  = $scaled instanceof GdImage ? $scaled : $image;
        }

        $width  = imagesx($image);
        $height = imagesy($image);
        $bytes  = (int) ceil($width / 8);
        $data   = '';

        for ($y = 0; $y < $height; $y++) {
            for ($byte = 0; $byte < $bytes; $byte++) {
                $bits = 0;

                for ($bit = 0; $bit < 8; $bit++) {
                    $x = $byte * 8 + $bit;

                    if ($x < $width && self::isDark(imagecolorat($image, $x, $y))) {
                        $bits |= 0x80 >> $bit;
                    }
                }

                $data .= chr($bits);
            }
        }

        return "\x1Ba\x01\x1Dv0\x00".self::word($bytes).self::word($height).$data."\x1Ba\x00";
    }

    /** A truecolor pixel over white paper: dark when its luminance is under half. */
    private static function isDark(int $color): bool
    {
        $opacity = 1 - (($color >> 24) & 0x7F) / 127;
        $over    = fn (int $channel): float => $channel * $opacity + 255 * (1 - $opacity);

        $luminance = 0.299 * $over(($color >> 16) & 0xFF)
            + 0.587 * $over(($color >> 8) & 0xFF)
            + 0.114 * $over($color & 0xFF);

        return $luminance < 128;
    }

    /** A 16-bit little-endian number: `nL nH`. */
    private static function word(int $value): string
    {
        return chr($value & 0xFF).chr(($value >> 8) & 0xFF);
    }
}
