<?php

namespace Controlink\Templates\Output\Pdf;

use Controlink\Templates\Contracts\PdfEngine;
use Controlink\Templates\Support\PageGeometry;
use Controlink\Templates\Types\TemplateType;

final class PdfRenderer
{
    public function __construct(private readonly PdfEngine $engine) {}

    /**
     * Sheets render once. A roll that fits its content renders twice: a first pass at
     * the ceiling height to measure where the content ends, then a second on a page of
     * exactly that height, so the result is a single page with no trailing blank.
     *
     * @return array{pdf: string, hash: string}
     */
    public function render(string $html, TemplateType $type): array
    {
        $geometry = PageGeometry::fromType($type->pdf);

        if ($geometry->medium !== 'roll' || $geometry->fit !== 'measure') {
            $pdf = $this->engine->render($html, $geometry);

            return ['pdf' => $pdf, 'hash' => PdfHash::of($pdf)];
        }

        $first = $this->engine->render($this->withEndMark($html), $geometry);

        $heightMm = RenderedPdf::open($first, fn (RenderedPdf $pdf): float => $pdf->contentHeightMm())
            - self::END_MARK_MM
            + $geometry->margins['bottom'];

        $pdf = $this->engine->render($this->withPageHeight($html, $geometry->widthMm, $heightMm), $geometry);

        return ['pdf' => $pdf, 'hash' => PdfHash::of($pdf)];
    }

    /** The end mark's thickness, taken back off the measured height. */
    private const END_MARK_MM = 0.5;

    /**
     * The measuring pass only: a dark line after the page, where the document's flow ends.
     *
     * The raster finds the last dark row, but the flow can end below it: a last child's
     * bottom margin (a `<p>`, an `<hr>`) collapses out of the roll's in-flow zones (CGE-97)
     * and still takes room. Measured on the text alone, the page came out shorter than
     * the document and the margin spilled onto a blank page 2 (R-03 on an `every_page`
     * value). The mark sits below that margin; the second pass does not carry it.
     */
    private function withEndMark(string $html): string
    {
        // A span, not a div: the last `.page` is `:last-of-type` (no page break after it).
        $mark = '<span style="display:block;height:0;border-top:'.self::END_MARK_MM.'mm solid #000"></span>';

        $end = strrpos($html, '</body>');

        return $end === false ? $html.$mark : substr_replace($html, $mark, $end, 0);
    }

    /** Rewrite the height of the roll's `@page{size: <w>mm <h>mm}` declaration. */
    private function withPageHeight(string $html, float $widthMm, float $heightMm): string
    {
        // Rounded up to the next 0.1mm: cutting the last row short would be worse than
        // a sliver of blank.
        $height = ceil($heightMm * 10) / 10;

        return (string) preg_replace_callback(
            '/size:\s*'.preg_quote((string) $widthMm, '/').'mm\s+[\d.]+mm/',
            fn (): string => 'size: '.$widthMm.'mm '.$height.'mm',
            $html,
        );
    }
}
