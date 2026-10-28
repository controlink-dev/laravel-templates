<?php

namespace Controlink\Templates\Tests\Unit\EscPos;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Controlink\Templates\Output\EscPos\EscPosEncoder;
use Controlink\Templates\Output\EscPos\Receipt;
use Controlink\Templates\Support\PageGeometry;
use PHPUnit\Framework\TestCase;

/** The translator's second half (CGE-97): a `Receipt` as Epson ESC/POS bytes. */
class EscPosEncoderTest extends TestCase
{
    private const ROLL = [
        'medium' => 'roll', 'fit' => 'measure', 'max_height_mm' => 4000,
        'margins' => ['top' => 3, 'right' => 3, 'bottom' => 3, 'left' => 3],
    ];

    private function geometry(int $widthMm = 80): PageGeometry
    {
        return PageGeometry::fromType(['width_mm' => $widthMm] + self::ROLL);
    }

    /** @return array{kind: 'line', text: string, double: bool, bold: list<array{0: int, 1: int}>, sources: list<string>} */
    private function line(string $text, bool $double = false, array $bold = []): array
    {
        return ['kind' => 'line', 'text' => $text, 'double' => $double, 'bold' => $bold, 'sources' => []];
    }

    public function test_preamble_selects_cp858_and_centres_a_40_column_area_on_80mm(): void
    {
        $bytes = (new EscPosEncoder)->encode(new Receipt(40, []), $this->geometry());

        $this->assertStringStartsWith("\x1B@\x1Bt\x13\x1DL\x30\x00\x1DW\xE0\x01", $bytes);
    }

    public function test_58mm_uses_the_full_384_dot_area(): void
    {
        $bytes = (new EscPosEncoder)->encode(new Receipt(32, []), $this->geometry(58));

        $this->assertStringStartsWith("\x1B@\x1Bt\x13\x1DL\x00\x00\x1DW\x80\x01", $bytes);

        // A grid wider than the paper takes all of it, never a negative margin.
        $wide = PageGeometry::fromType(['width_mm' => 58, 'columns' => 42] + self::ROLL);
        $this->assertStringStartsWith("\x1B@\x1Bt\x13\x1DL\x00\x00\x1DW\x80\x01", (new EscPosEncoder)->encode(new Receipt(42, []), $wide));
    }

    public function test_qr_bytes_carry_the_exact_payload(): void
    {
        $payload = 'A:509999999*B:123456789*C:PT*D:FS*E:N*F:20261027*G:FS A/1*H:JFJ5KZ2Q-1*I1:PT*N:0.23*O:1.23*Q:ab12*R:0001';
        $bytes   = (new EscPosEncoder)->encode(
            new Receipt(40, [['kind' => 'qr', 'payload' => $payload, 'binding' => 'document.qr']]),
            $this->geometry(),
        );

        $length = strlen($payload) + 3;
        $store  = "\x1D(k".chr($length % 256).chr(intdiv($length, 256))."1P0";
        $at     = strpos($bytes, $store);
        $this->assertNotFalse($at);
        $this->assertSame($payload, substr($bytes, $at + strlen($store), strlen($payload)));

        // Model 2, correction M, print; centred and back to the left.
        $this->assertStringContainsString("\x1Ba\x01\x1D(k\x04\x001A2\x00", $bytes);
        $this->assertStringContainsString("\x1D(k\x03\x001E1", $bytes);
        $this->assertStringContainsString("\x1D(k\x03\x001Q0\x1Ba\x00", $bytes);

        // The module makes the symbol at least 30 mm (240 dots) and no wider than the area.
        $this->assertSame(1, preg_match('/\x1D\(k\x03\x001C(.)/s', $bytes, $match));
        $module = ord($match[1]);
        $n      = Encoder::encode($payload, ErrorCorrectionLevel::M())->getMatrix()->getWidth();

        // 104 bytes at level M is version 6 (106 bytes): 41 modules, so a module of 6 dots
        // (246 dots, 30.75 mm). Pinned, so a broken matrix width cannot agree with itself.
        $this->assertSame(41, $n);
        $this->assertSame(6, $module);
        $this->assertGreaterThanOrEqual(240, $module * $n);
        $this->assertLessThanOrEqual(480, $module * $n);
    }

    public function test_double_and_bold_commands_wrap_the_right_text(): void
    {
        $bytes = (new EscPosEncoder)->encode(new Receipt(40, [
            $this->line('TOTAL'.str_repeat(' ', 7).'1.234,56', true, [[0, 5]]),
            $this->line('Preço €'.str_repeat(' ', 33)),
        ]), $this->geometry());

        $this->assertStringContainsString(
            "\x1D!\x11\x1BE\x01TOTAL\x1BE\x00".str_repeat(' ', 7)."1.234,56\n"
            ."\x1D!\x00Pre\x87o \xD5".str_repeat(' ', 33)."\n",
            $bytes,
        );
    }

    public function test_images_are_raster_bits_by_luminance(): void
    {
        // 16 × 2: black left half, white right half (and a transparent pixel, as white).
        $image = imagecreatetruecolor(16, 2);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 0, 0, 7, 1, imagecolorallocate($image, 0, 0, 0));
        imagesetpixel($image, 15, 1, imagecolorallocatealpha($image, 0, 0, 0, 127));
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        $bytes = (new EscPosEncoder)->encode(new Receipt(40, [['kind' => 'image', 'png' => $png]]), $this->geometry());

        $this->assertStringContainsString("\x1Ba\x01\x1Dv0\x00\x02\x00\x02\x00\xFF\x00\xFF\x00", $bytes);
    }

    public function test_a_receipt_with_problems_is_never_encoded(): void
    {
        // An E-03 left a `?` in the line: it must not reach paper.
        $receipt = new Receipt(40, [$this->line('Cliente ?'.str_repeat(' ', 31))], [
            ['code' => 'E-03', 'component_id' => 'tpl-name', 'message' => 'x', 'key' => 'unencodable_field', 'subject' => 'customer.name'],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('E-03');

        (new EscPosEncoder)->encode($receipt, $this->geometry());
    }

    public function test_ends_with_feed_and_partial_cut(): void
    {
        $bytes = (new EscPosEncoder)->encode(new Receipt(40, [$this->line(str_repeat(' ', 40))]), $this->geometry());

        $this->assertStringEndsWith("\n\x1Bd\x04\x1DVB\x00", $bytes);
    }
}
