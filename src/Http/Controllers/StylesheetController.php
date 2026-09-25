<?php

namespace Controlink\Templates\Http\Controllers;

use Controlink\Templates\Output\Pdf\DocumentComposer;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The print stylesheet and the fonts every layout may use, served raw and
 * unauthenticated: the canvas and the compositor have to read exactly the same bytes
 * (spec, "the canvas/PDF fidelity guarantee"), and gating that behind a policy would
 * only be one more way for the two to drift apart.
 */
class StylesheetController extends Controller
{
    /**
     * The print stylesheet.
     *
     * Deliberately not a bundled asset: the compositor reads this same file from disk to
     * build the document handed to the PDF engine, so a canvas loading a bundled copy
     * would be one build step removed from what actually prints.
     */
    public function show(): Response
    {
        $css = app(DocumentComposer::class)->printStylesheet();

        return response($css, HttpResponse::HTTP_OK, [
            'Content-Type'  => 'text/css; charset=utf-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag'          => '"'.substr(hash('sha256', $css), 0, 16).'"',
        ]);
    }

    /**
     * One font file, exactly as declared in `templates.fonts.<family>.weights.<weight>`
     * — the same catalogue `DocumentComposer::fontFaces()` embeds into every rendered
     * PDF, so the canvas measures text against the family that actually prints.
     */
    public function font(string $family, int $weight): Response
    {
        $face = config("templates.fonts.{$family}.weights.{$weight}");

        abort_if($face === null, HttpResponse::HTTP_NOT_FOUND);

        $path = self::fontPath($face['path']);

        abort_if(! File::exists($path), HttpResponse::HTTP_NOT_FOUND);

        return response(File::get($path), HttpResponse::HTTP_OK, [
            'Content-Type'  => $face['mime'],
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /**
     * Absolute paths (the package's own default fonts) are used as they are; relative
     * ones are relative to the host's public directory, where the canvas loads them.
     */
    private static function fontPath(string $path): string
    {
        return preg_match('/^([a-zA-Z]:[\\\\\/]|\/)/', $path) === 1 ? $path : public_path($path);
    }
}
