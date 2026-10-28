<?php

namespace Controlink\Templates\Codes;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\ByteMatrix;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use DOMDocument;
use DOMElement;
use Controlink\Templates\Support\PageGeometry;
use DOMXPath;
use Picqer\Barcode\Types\TypeCode128B;
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
 *
 * On a label (CGE-98) the target is a 203 dpi thermal head, which prints whole dots only,
 * so there the codes are drawn with every module a whole number of dots: a Code 128
 * always, a QR when the caller says the page is a label.
 */
class CodeImageGenerator
{
    /** Quiet zone in modules, as the QR specification requires. */
    public const QR_QUIET_ZONE = 4;

    private const QUIET_ZONE = self::QR_QUIET_ZONE;

    /** Byte-mode encoding of a QR's content. */
    private const QR_ENCODING = 'utf-8';

    /** Barcode height when the slot declares none, in millimetres. */
    private const DEFAULT_BARCODE_HEIGHT_MM = 10.0;

    /** Slot width when neither its style nor the version CSS gives one, in millimetres. */
    public const FALLBACK_WIDTH_MM = 30.0;

    /**
     * @param  'qr'|'barcode'  $type
     * @param  float|null  $heightMm  the slot height; only a barcode uses it
     * @return string a `data:` URI ready for an `img` element
     *
     * @throws BarcodeFailure when a barcode value cannot be encoded or does not fit
     * @throws RuntimeException for an unknown type or an empty value
     */
    public function generate(string $type, string $content, float $sizeMm, ?float $heightMm = null): string
    {
        if ($content === '') {
            throw new RuntimeException('A code slot was rendered with no content.');
        }

        return match ($type) {
            'qr' => $this->qr($content, $sizeMm),

            'barcode' => $this->barcode($content, $sizeMm, $heightMm ?? self::DEFAULT_BARCODE_HEIGHT_MM),

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
     * @param  PageGeometry|null  $geometry  a label sheet makes QR modules whole dots; null
     *                                       leaves every output as it was before labels
     * @param  string  $css  the version CSS: on a label, a slot's size is read from its
     *                       `#id` rule when not inline (`SlotSize`); ignored elsewhere
     */
    public function inject(string $html, array $dataset, array $bindings, ?PageGeometry $geometry = null, string $css = ''): string
    {
        // Only a label reads the version CSS: every other output stays byte for byte
        // what it was, sized from the inline style alone.
        $css = $this->isLabel($geometry) ? $css : '';

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
                $this->fill($slot, 'qr', $fixed, $geometry, $css);

                continue;
            }

            $key  = $slot->getAttribute('data-tpl-binding');
            $type = $bindings[$key]['type'] ?? null;

            if ($type === null) {
                throw new RuntimeException("A code slot is bound to an unknown field: {$key}");
            }

            $this->fill($slot, $type, (string) ($dataset[$key] ?? ''), $geometry, $css);
        }

        $root = $document->getElementById('tpl-codes');
        $out  = '';

        foreach ($root?->childNodes ?? [] as $child) {
            $out .= $document->saveHTML($child);
        }

        return $out;
    }

    /**
     * Generate one slot's image and write it into the element.
     *
     * A QR on a label is the one case that also rewrites the slot's size: its modules
     * are whole dots, so the image is a little smaller than the slot (never larger, so
     * it cannot overlap its neighbour), and the element must be told to match or the
     * browser stretches it back to fractional dots. It stays at the slot's own
     * position, i.e. top-left of the area the user drew.
     *
     * A barcode on a label likewise gets its bars' real size written inline: the slot's
     * own box (often only a `#id` rule) is wider than the bars, and an image stretched to
     * it would no longer have whole-dot modules.
     */
    private function fill(DOMElement $slot, string $type, string $content, ?PageGeometry $geometry, string $css): void
    {
        // A slot with no width anywhere falls back to the legal QR minimum rather than
        // failing: off a label it only sets the image's nominal resolution.
        $size     = SlotSize::of($slot->getAttribute('style'), $slot->getAttribute('id'), $css);
        $sizeMm   = $size['width'] ?? self::FALLBACK_WIDTH_MM;
        $heightMm = $size['height'];

        if ($type === 'qr' && $this->isLabel($geometry) && $content !== '') {
            [$src, $imageMm] = $this->qrWholeDots($content, $sizeMm);

            $slot->setAttribute('src', $src);
            $slot->setAttribute('style', $this->withSize($slot->getAttribute('style'), $imageMm, $imageMm));

            return;
        }

        $slot->setAttribute('src', $this->generate($type, $content, $sizeMm, $heightMm));

        if ($type === 'barcode' && $this->isLabel($geometry)) {
            // generate() has already refused a value that does not fit, so this is safe.
            $barsMm = Code128Fit::modules($content) * Code128Fit::moduleDots($content, $sizeMm) / Code128Fit::DOTS_PER_MM;

            $slot->setAttribute('style', $this->withSize($slot->getAttribute('style'), $barsMm, $heightMm ?? self::DEFAULT_BARCODE_HEIGHT_MM));
        }
    }

    /** A label is a sheet sized in millimetres, which `PageGeometry` marks with no paper format. */
    private function isLabel(?PageGeometry $geometry): bool
    {
        return $geometry !== null && $geometry->medium === 'sheet' && $geometry->paperFormat === null;
    }

    /** The inline style with its width and height replaced by the given ones. */
    private function withSize(string $style, float $widthMm, float $heightMm): string
    {
        $style  = preg_replace('/(^|;)\s*(width|height)\s*:[^;]*/i', '$1', $style) ?? $style;
        $style  = trim($style, "; \t\n\r");
        $width  = self::mm($widthMm);
        $height = self::mm($heightMm);

        return ($style === '' ? '' : $style.';')."width:{$width}mm;height:{$height}mm";
    }

    /** Millimetres as a short decimal: exact for whole-dot sizes, which are multiples of 1/8. */
    private static function mm(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
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

    /**
     * A QR whose modules are a whole number of printer dots, with the side it prints at.
     *
     * Module dots = floor(slot dots / modules across, quiet zone included), at least 1;
     * the image is exactly modules x dots wide, so it never exceeds the slot unless even
     * 1 dot per module does not fit (then the code is bigger than asked rather than
     * unreadable, and the host's size validation is what reports it).
     *
     * @return array{string, float} the data URI and the side in millimetres
     */
    private function qrWholeDots(string $content, float $sizeMm): array
    {
        $qr      = self::labelQr($content, $sizeMm);
        $modules = $qr['matrix']->getWidth() + 2 * self::QUIET_ZONE;

        $writer = new Writer(
            new ImageRenderer(
                new RendererStyle($modules * $qr['moduleDots'], self::QUIET_ZONE),
                new SvgImageBackEnd,
            ),
        );

        $svg = $writer->writeString($content, self::QR_ENCODING, self::qrLevel());

        return ['data:image/svg+xml;base64,'.base64_encode($svg), $modules * $qr['moduleDots'] / Code128Fit::DOTS_PER_MM];
    }

    /**
     * The QR a label prints, as its module matrix and the whole dots per module: the one
     * source of both the PDF's image (`qrWholeDots`) and the ZPL's picture, which draws
     * the same matrix dot by dot because a screenshot resamples an image. The matrix
     * excludes the quiet zone, which is `QR_QUIET_ZONE` modules of white on every side.
     *
     * @return array{matrix: ByteMatrix, moduleDots: int}
     */
    public static function labelQr(string $content, float $sizeMm): array
    {
        $matrix  = Encoder::encode($content, self::qrLevel(), self::QR_ENCODING)->getMatrix();
        $modules = $matrix->getWidth() + 2 * self::QUIET_ZONE;

        return ['matrix' => $matrix, 'moduleDots' => max(1, (int) floor($sizeMm * Code128Fit::DOTS_PER_MM / $modules))];
    }

    /** Error correction of a label's QR, given to both the encoder and the writer so they cannot drift. */
    private static function qrLevel(): ErrorCorrectionLevel
    {
        return ErrorCorrectionLevel::L();
    }

    /**
     * A Code 128 barcode as an SVG data URI: bars only, no human-readable text.
     *
     * Subset B is forced rather than letting the library pick: its automatic mode swaps
     * subsets to save modules, which makes the symbol length depend on the content's
     * mix of digits and letters, and the ZPL path and the width check must agree on the
     * length from the character count alone. The bars come from the library; the SVG is
     * drawn here so every module is exactly `dots / 8` millimetres, which no library
     * option guarantees. The quiet zone is real white space inside the image.
     *
     * @throws BarcodeFailure
     */
    private function barcode(string $content, float $widthMm, float $heightMm): string
    {
        Code128Fit::assertEncodable($content);

        $moduleMm = Code128Fit::moduleDots($content, $widthMm) / Code128Fit::DOTS_PER_MM;
        $w        = self::mm(Code128Fit::modules($content) * $moduleMm);
        $h        = self::mm($heightMm);

        $x     = Code128Fit::QUIET_ZONE * $moduleMm;
        $rects = '';

        foreach ((new TypeCode128B)->getBarcode($content)->getBars() as $bar) {
            if ($bar->isBar()) {
                $rects .= '<rect x="'.self::mm($x).'" y="0" width="'.self::mm($bar->getWidth() * $moduleMm).'" height="'.$h.'"/>';
            }

            $x += $bar->getWidth() * $moduleMm;
        }

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'mm" height="'.$h.'mm" viewBox="0 0 '.$w.' '.$h.'" shape-rendering="crispEdges">'
            .'<rect x="0" y="0" width="'.$w.'" height="'.$h.'" fill="#fff"/>'
            .'<g fill="#000">'.$rects.'</g></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
