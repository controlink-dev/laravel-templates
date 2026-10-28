<?php

namespace Controlink\Templates\Output\Zpl;

use Controlink\Templates\Support\PageGeometry;

/**
 * One label as a ZPL block (CGE-98): the label's picture as one graphic field at the
 * origin, and the barcodes on top of it as native Code 128.
 *
 * The picture carries everything the PDF shows, text included, because ZPL's own fonts
 * measure and break lines differently from Chromium; only the barcodes are native, so
 * the printer draws their bars on its own dot grid. Their human-readable line is in the
 * picture, so `^BC` prints none.
 *
 * The graphic is `^GFA` in Z64: the packed rows compressed with zlib (`gzcompress`,
 * RFC 1950, the "LZ77" Zebra documents), in base64, followed by a CRC-16/CCITT
 * (polynomial 0x1021, initial 0) of the base64 text.
 */
final class ZplEncoder
{
    /**
     * @param  list<array{x: int, y: int, heightDots: int, moduleDots: int, content: string}>  $barcodes  positions in dots, each at its first bar
     */
    public function label(MonoImage $image, array $barcodes, PageGeometry $label): string
    {
        $rowBytes = intdiv($image->width + 7, 8);
        $bits     = implode('', $image->rows());
        $bytes    = strlen($bits);
        $base64   = base64_encode((string) gzcompress($bits, 9));

        $zpl = '^XA^CI28'
            .'^PW'.Dots::fromMm($label->widthMm)
            .'^LL'.Dots::fromMm($label->heightMm)
            .'^LH0,0'
            .'^FO0,0^GFA,'.$bytes.','.$bytes.','.$rowBytes.',:Z64:'.$base64.':'.self::crc16($base64).'^FS';

        foreach ($barcodes as $barcode) {
            $zpl .= '^FO'.$barcode['x'].','.$barcode['y']
                .'^BY'.$barcode['moduleDots']
                .'^BCN,'.$barcode['heightDots'].',N,N,N,N'
                .self::fieldData($barcode['content'])
                .'^FS';
        }

        return $zpl.'^XZ';
    }

    /** CRC-16/CCITT (XMODEM: polynomial 0x1021, initial 0), as four uppercase hex digits. */
    public static function crc16(string $data): string
    {
        $crc = 0;

        for ($i = 0, $length = strlen($data); $i < $length; $i++) {
            $crc ^= ord($data[$i]) << 8;

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) !== 0 ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }

        return sprintf('%04X', $crc);
    }

    /**
     * The `^FD` of a Code 128 forced into subset B (`>:`), carrying exactly the value.
     *
     * Three characters of printable ASCII are not themselves in ZPL: `>` is `^BC`'s own
     * escape, written `><`; `^` and `~` would start a command, and are sent as `^FH` hex
     * (`_5E`, `_7E`), which makes `_` the hex indicator, so it goes as `_5F` too.
     */
    private static function fieldData(string $content): string
    {
        $data = str_replace('>', '><', $content);

        if (strpbrk($data, '^~_') === false) {
            return '^FD>:'.$data;
        }

        return '^FH^FD>:'.str_replace(['_', '^', '~'], ['_5F', '_5E', '_7E'], $data);
    }
}
