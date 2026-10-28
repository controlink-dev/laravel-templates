<?php

namespace Controlink\Templates\Tests\Unit;

use Controlink\Templates\Output\Zpl\MonoImage;
use Controlink\Templates\Output\Zpl\ZplEncoder;
use Controlink\Templates\Support\PageGeometry;
use Controlink\Templates\Tests\TestCase;

/**
 * One label as ZPL (CGE-98): the screenshot as a Z64 `^GFA` at the origin, the barcodes
 * on top as native Code 128.
 */
class ZplEncoderTest extends TestCase
{
    private function label(): PageGeometry
    {
        return PageGeometry::fromType(['medium' => 'sheet', 'width_mm' => 100, 'height_mm' => 50, 'label' => true]);
    }

    /** @param  callable(\GdImage): void|null  $draw */
    private function image(int $width, int $height, ?callable $draw = null): MonoImage
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));

        if ($draw !== null) {
            $draw($image);
        }

        ob_start();
        imagepng($image);

        return MonoImage::fromPng((string) ob_get_clean());
    }

    /** @return array{string, string} the Z64 base64 text and its CRC */
    private function z64(string $zpl): array
    {
        $this->assertSame(1, preg_match('/:Z64:([A-Za-z0-9+\/=]+):([0-9A-F]{4})\^FS/', $zpl, $match));

        return [$match[1], $match[2]];
    }

    public function test_the_label_block(): void
    {
        $zpl = (new ZplEncoder)->label($this->image(800, 400), [
            ['x' => 40, 'y' => 300, 'heightDots' => 80, 'moduleDots' => 3, 'content' => 'ART-0000000003'],
        ], $this->label());

        $this->assertStringStartsWith('^XA^CI28^PW800^LL400', $zpl);
        $this->assertStringContainsString('^LH0,0^FO0,0^GFA,40000,40000,100,:Z64:', $zpl);
        $this->assertStringContainsString('^FO40,300^BY3^BCN,80,N,N,N,N^FD>:ART-0000000003^FS', $zpl);
        $this->assertStringEndsWith('^XZ', $zpl);
    }

    public function test_the_z64_payload_is_the_image_and_its_crc_is_over_the_base64_text(): void
    {
        $zpl = (new ZplEncoder)->label($this->image(800, 400, function (\GdImage $image): void {
            imagefilledrectangle($image, 0, 0, 7, 0, imagecolorallocate($image, 0, 0, 0));
        }), [], $this->label());

        [$base64, $crc] = $this->z64($zpl);
        $bits           = gzuncompress((string) base64_decode($base64, true));

        $this->assertSame(40000, strlen((string) $bits));
        $this->assertSame("\xFF\x00", substr((string) $bits, 0, 2));
        $this->assertSame(ZplEncoder::crc16($base64), $crc);
    }

    public function test_crc16_ccitt_known_vector(): void
    {
        $this->assertSame('31C3', ZplEncoder::crc16('123456789'));
    }

    public function test_zpl_control_characters_in_a_code_are_escaped_not_sent_raw(): void
    {
        $zpl = (new ZplEncoder)->label($this->image(800, 400), [
            ['x' => 0, 'y' => 0, 'heightDots' => 80, 'moduleDots' => 2, 'content' => 'A>B^C~D_E'],
        ], $this->label());

        // `>` is ^BC's own escape (`><` is a literal `>`); `^`, `~` and `_` go as ^FH hex.
        $this->assertStringContainsString('^BCN,80,N,N,N,N^FH^FD>:A><B_5EC_7ED_5FE^FS', $zpl);
    }
}
