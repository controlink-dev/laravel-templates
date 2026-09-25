<?php

namespace Controlink\Templates\Codes;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Generates the QR and barcode images for the slots the user placed on the canvas.
 *
 * The image is made on the server and embedded as a data URI, never linked: the PDF
 * engine has no network, and a code that fails to load is a document whose code nobody
 * can scan.
 *
 * SVG rather than a raster, for two reasons. There is no `imagick` on this platform, so
 * `bacon/bacon-qr-code` has no raster backend available; and a vector code stays sharp
 * at any print resolution, which matters because a QR that the engine resamples is a QR
 * a phone may not read.
 */
class CodeImageGenerator
{
    /** Quiet zone in modules, as the QR specification requires. */
    private const QUIET_ZONE = 4;

    /**
     * @param  'qr'|'barcode'  $type
     * @return string a `data:` URI ready for an `img` element
     *
     * @throws RuntimeException for a type this application cannot produce yet
     */
    public function generate(string $type, string $content, float $sizeMm): string
    {
        if ($content === '') {
            throw new RuntimeException('A code slot was rendered with no content.');
        }

        return match ($type) {
            'qr' => $this->qr($content, $sizeMm),

            /*
             * No document type declares a barcode binding yet, so there is nothing to
             * generate and no reason to carry a barcode dependency that renders
             * nothing. When one is declared, `picqer/php-barcode-generator` (MIT) is
             * a good candidate.
             */
            'barcode' => throw new RuntimeException(
                'Barcode slots are not implemented: no document type declares a barcode binding.',
            ),

            default => throw new RuntimeException("Unknown code slot type: {$type}"),
        };
    }

    /**
     * Fill in the code slots the user placed.
     *
     * The slots reach here as empty `img` elements carrying the binding they are bound
     * to; this looks each value up in the dataset, generates the image and writes the
     * `src`. Done with a DOM parser rather than a pattern, because the rendered
     * document is real markup and matching attributes by regular expression is how a
     * slot quietly keeps its placeholder.
     *
     * @param  array<string, mixed>  $dataset
     * @param  array<string, array<string, mixed>>  $bindings  keyed by binding key
     */
    public function inject(string $html, array $dataset, array $bindings): string
    {
        if (! str_contains($html, 'data-tpl-type="code-slot"')) {
            return $html;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        $document->loadHTML(
            '<?xml encoding="utf-8" ?><div id="tpl-codes">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        $slots = $xpath->query('//*[@data-tpl-type="code-slot"]');

        foreach ($slots ?: [] as $slot) {
            if (! $slot instanceof DOMElement) {
                continue;
            }

            $attributes = [];

            foreach ($slot->attributes ?? [] as $attribute) {
                $attributes[$attribute->nodeName] = $attribute->nodeValue;
            }

            /*
             * Two origins: a fixed-text code composes its content from its own
             * attributes through `CodePayload`, and a dynamic one reads it from the dataset
             * exactly as it always has. `fromAttributes()` returning null is what says
             * "this one is dynamic", including for every slot drawn before the feature
             * existed.
             */
            $fixed = CodePayload::fromAttributes($attributes);

            if ($fixed !== null) {
                $slot->setAttribute('src', $this->generate('qr', $fixed, $this->slotSizeMm($slot)));

                continue;
            }

            $key  = $slot->getAttribute('data-tpl-binding');
            $type = $bindings[$key]['type'] ?? null;

            if ($type === null) {
                throw new RuntimeException("A code slot is bound to an unknown field: {$key}");
            }

            $slot->setAttribute('src', $this->generate(
                $type,
                (string) ($dataset[$key] ?? ''),
                $this->slotSizeMm($slot),
            ));
        }

        $root = $document->getElementById('tpl-codes');
        $out  = '';

        foreach ($root?->childNodes ?? [] as $child) {
            $out .= $document->saveHTML($child);
        }

        return $out;
    }

    /**
     * The slot's printed side in millimetres, read from its inline style when it has
     * one. It only decides the nominal resolution of the generated image — the printed
     * size comes from the stylesheet either way — so a slot with no inline size falls
     * back to the legal minimum rather than failing.
     */
    private function slotSizeMm(DOMElement $slot): float
    {
        if (preg_match('/width\s*:\s*([\d.]+)mm/i', $slot->getAttribute('style'), $matches) === 1) {
            return (float) $matches[1];
        }

        return 30.0;
    }

    /**
     * A QR code as an SVG data URI.
     *
     * The pixel size handed to the renderer is nominal: the SVG scales to whatever the
     * stylesheet says, and the slot's own width and height in millimetres are what
     * actually decide the printed size — the host's layout validation is what keeps
     * that above any legally required minimum.
     */
    private function qr(string $content, float $sizeMm): string
    {
        // 4 dots per millimetre is far above what any printer resolves, and keeps the
        // module edges on exact SVG coordinates rather than fractional ones.
        $nominalPixels = max(120, (int) round($sizeMm * 4));

        $writer = new Writer(
            new ImageRenderer(
                new RendererStyle($nominalPixels, self::QUIET_ZONE),
                new SvgImageBackEnd,
            ),
        );

        $svg = $writer->writeString($content, 'utf-8');

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
