<?php

namespace Controlink\Templates\Output\Pdf;

use Controlink\Templates\Contracts\PdfEngine;
use Controlink\Templates\Support\PageGeometry;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Gotenberg's Chromium route. Page geometry is not passed as engine flags: the engine is
 * called with `preferCssPageSize=true` and zero margins so the `@page` rule in print.css
 * is the only place size and margins are decided (measured in docs/NOTAS-FIDELIDADE.md).
 */
final class GotenbergEngine implements PdfEngine
{
    public function render(string $html, PageGeometry $page): string
    {
        $baseUrl = rtrim((string) config('templates.pdf.gotenberg.url'), '/');

        if ($baseUrl === '') {
            throw new RuntimeException('The PDF engine URL is not configured (templates.pdf.gotenberg.url).');
        }

        $response = Http::asMultipart()
            ->timeout((int) config('templates.pdf.gotenberg.timeout', 30))
            ->attach('files', $html, 'index.html')
            ->post($baseUrl.'/forms/chromium/convert/html', [
                ['name' => 'preferCssPageSize', 'contents' => 'true'],
                ['name' => 'printBackground', 'contents' => 'true'],
                ['name' => 'marginTop', 'contents' => '0'],
                ['name' => 'marginBottom', 'contents' => '0'],
                ['name' => 'marginLeft', 'contents' => '0'],
                ['name' => 'marginRight', 'contents' => '0'],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('The PDF engine refused the document: HTTP '.$response->status());
        }

        $pdf = $response->body();

        if (! str_starts_with($pdf, '%PDF')) {
            throw new RuntimeException('The PDF engine returned something that is not a PDF.');
        }

        return $pdf;
    }
}
