<?php

namespace Controlink\Templates\Tests\Unit\EscPos;

use Controlink\Templates\Output\EscPos\Receipt;
use Controlink\Templates\Output\EscPos\ReceiptPreview;
use Controlink\Templates\Tests\TestCase;

/** The receipt's preview (CGE-97): the same lines the printer gets, drawn in HTML. */
class ReceiptPreviewTest extends TestCase
{
    private function receipt(): Receipt
    {
        return new Receipt(40, [
            ['kind' => 'line', 'text' => mb_str_pad('Fatura <FR> & Ç €', 40), 'double' => false, 'bold' => [[0, 6]], 'sources' => ['a']],
            ['kind' => 'line', 'text' => str_pad('TOTAL 9,99', 20), 'double' => true, 'bold' => [], 'sources' => ['b']],
            ['kind' => 'qr', 'payload' => 'A:1*B:2', 'binding' => 'document.qr'],
            ['kind' => 'line', 'text' => str_repeat('-', 40), 'double' => false, 'bold' => [[38, 40]], 'sources' => []],
        ]);
    }

    public function test_preview_lines_match_the_receipt_text(): void
    {
        $receipt = $this->receipt();
        $html    = (new ReceiptPreview)->html($receipt);

        preg_match_all('#<pre\b[^>]*>(.*?)</pre>#s', $html, $match);
        $lines = array_map(
            fn (string $inner): string => html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $match[1],
        );

        $expected = array_values(array_filter(
            explode("\n", $receipt->text()),
            fn (string $line): bool => ! in_array($line, ['[QR]', '[IMG]'], true),
        ));

        $this->assertSame($expected, $lines);
    }

    public function test_the_paper_style_survives_html_parsing_with_its_monospace_font(): void
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.(new ReceiptPreview)->html($this->receipt()));

        $paper = (new \DOMXPath($document))->query('//div[@class="tpl-receipt"]')->item(0);

        // A quote inside the style attribute would cut it short and the browser would fall
        // back to a proportional font, misaligning every column of the receipt.
        $this->assertStringContainsString('monospace', $paper->getAttribute('style'));
        $this->assertStringContainsString('box-shadow', $paper->getAttribute('style'));
    }

    public function test_preview_is_a_self_contained_grid_with_bold_double_and_the_qr(): void
    {
        $html = (new ReceiptPreview)->html($this->receipt());

        $this->assertStringStartsWith('<div class="tpl-receipt" style="--cols:40;', $html);
        $this->assertStringContainsString('<b>Fatura</b>', $html);
        $this->assertStringContainsString('&lt;FR&gt; &amp;', $html);
        $this->assertStringContainsString('font-size:2em', $html);
        $this->assertStringContainsString('<b>--</b>', $html);
        $this->assertSame(1, substr_count($html, '<img'));
        $this->assertStringContainsString('src="data:image/svg+xml;base64,', $html);
        $this->assertStringNotContainsString('<script', $html);
    }
}
