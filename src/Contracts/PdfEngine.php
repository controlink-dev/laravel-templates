<?php

namespace Controlink\Templates\Contracts;

use Controlink\Templates\Support\PageGeometry;

interface PdfEngine
{
    /** @return string the PDF bytes */
    public function render(string $html, PageGeometry $page): string;
}
