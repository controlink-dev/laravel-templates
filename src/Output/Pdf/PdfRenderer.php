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

        $first = $this->engine->render($html, $geometry);

        $heightMm = RenderedPdf::open($first, fn (RenderedPdf $pdf): float => $pdf->contentHeightMm())
            + $geometry->margins['bottom'];

        $pdf = $this->engine->render($this->withPageHeight($html, $geometry->widthMm, $heightMm), $geometry);

        return ['pdf' => $pdf, 'hash' => PdfHash::of($pdf)];
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
