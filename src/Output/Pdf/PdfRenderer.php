<?php

namespace Controlink\Templates\Output\Pdf;

use Controlink\Templates\Contracts\PdfEngine;
use Controlink\Templates\Support\PageGeometry;
use Controlink\Templates\Types\TemplateType;

final class PdfRenderer
{
    public function __construct(private readonly PdfEngine $engine) {}

    /** @return array{pdf: string, hash: string} */
    public function render(string $html, TemplateType $type): array
    {
        $pdf = $this->engine->render($html, PageGeometry::fromType($type->pdf));

        return ['pdf' => $pdf, 'hash' => PdfHash::of($pdf)];
    }
}
