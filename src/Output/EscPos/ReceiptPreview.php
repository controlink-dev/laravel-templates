<?php

namespace Controlink\Templates\Output\EscPos;

use Controlink\Templates\Codes\CodeImageGenerator;

/**
 * The receipt as the thermal printer will print it, in HTML (CGE-97): made from the same
 * `Receipt` as the ESC/POS bytes, so what the user sees is what the printer gets.
 *
 * A self-contained fragment, its styles inline (the host drops it into a page of its own):
 * white paper `columns` characters wide in a monospace letter, one `<pre>` per line — a
 * double line at twice the size, so its `columns / 2` characters fill the same width —
 * bold in `<b>`, and the QR code and images centred, as `ESC a 1` prints them.
 */
final class ReceiptPreview
{
    /** The QR's side, the minimum the receipt prints it at (30 mm = 240 dots). */
    private const QR_MM = 30;

    /** 203 dpi. */
    private const DOTS_PER_MM = 8;

    /** Font A is 12 dots wide. */
    private const DOT_PER_CHARACTER = 12;

    private const PAPER = 'background:#fff;color:#000;display:inline-block;box-sizing:content-box;'
        .'width:calc(var(--cols) * 1ch);padding:1.5ch 2ch;'
        .'font-family:"Liberation Mono","Courier New",monospace;font-size:13px;line-height:1.25;'
        .'box-shadow:0 1px 4px rgba(0,0,0,.25)';

    private const LINE = 'margin:0;padding:0;font:inherit;line-height:inherit;white-space:pre;overflow:hidden';

    private const PICTURE = 'display:block;margin:0.25em auto;max-width:100%';

    public function html(Receipt $receipt): string
    {
        $html = '<div class="tpl-receipt" style="--cols:'.$receipt->columns.';'.self::PAPER.'">';

        foreach ($receipt->blocks as $block) {
            $html .= match ($block['kind']) {
                'line'  => $this->line($block),
                'qr'    => $this->qr((string) $block['payload']),
                default => $this->image((string) ($block['png'] ?? '')),
            };
        }

        return $html.'</div>';
    }

    /** @param array{text: string, double: bool, bold: list<array{0: int, 1: int}>} $block */
    private function line(array $block): string
    {
        $text  = $block['text'];
        $out   = '';
        $at    = 0;
        $bold  = $block['bold'];
        usort($bold, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        foreach ($bold as [$start, $end]) {
            $start = max($start, $at);

            if ($end <= $start) {
                continue;
            }

            $out .= self::escape(mb_substr($text, $at, $start - $at, 'UTF-8'))
                .'<b>'.self::escape(mb_substr($text, $start, $end - $start, 'UTF-8')).'</b>';
            $at = $end;
        }

        $out .= self::escape(mb_substr($text, $at, null, 'UTF-8'));

        $style = self::LINE.($block['double'] ? ';font-size:2em' : '');

        return '<pre style="'.$style.'">'.$out.'</pre>';
    }

    private function qr(string $payload): string
    {
        if ($payload === '') {
            return '';
        }

        $src  = app(CodeImageGenerator::class)->generate('qr', $payload, self::QR_MM);
        $side = self::characters(self::QR_MM * self::DOTS_PER_MM);

        return '<img src="'.self::escape($src).'" alt="QR" style="'.self::PICTURE.';width:'.$side.';height:'.$side.'">';
    }

    /** At its printed size: its pixels are the printer's dots. */
    private function image(string $png): string
    {
        if ($png === '') {
            return '';
        }

        $size  = @getimagesizefromstring($png);
        $width = is_array($size) ? ';width:'.self::characters((int) $size[0]) : '';

        return '<img src="data:image/png;base64,'.base64_encode($png).'" alt="" style="'.self::PICTURE.$width.'">';
    }

    /** Printer dots as characters of the grid (one is 12 dots wide), so a size keeps its share of the paper. */
    private static function characters(int|float $dots): string
    {
        return rtrim(rtrim(number_format($dots / self::DOT_PER_CHARACTER, 2, '.', ''), '0'), '.').'ch';
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
