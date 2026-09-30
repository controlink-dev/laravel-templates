<?php

namespace Controlink\Templates\Support;

final class PageGeometry
{
    /** Sheet formats in millimetres (portrait). The one source every consumer reads. */
    public const SHEET_SIZES = [
        'A3'     => ['width' => 297.0, 'height' => 420.0],
        'A4'     => ['width' => 210.0, 'height' => 297.0],
        'A5'     => ['width' => 148.0, 'height' => 210.0],
        'LETTER' => ['width' => 215.9, 'height' => 279.4],
    ];

    /** @param array{top: float, right: float, bottom: float, left: float} $margins millimetres */
    public function __construct(
        public readonly string $medium,
        public readonly ?string $paperFormat,
        public readonly float $widthMm,
        public readonly array $margins,
        public readonly string $fit,
        public readonly float $maxHeightMm,
    ) {
    }

    /** @param array<string, mixed> $pdf the geometry array (sheet or roll) */
    public static function fromType(array $pdf): self
    {
        $medium = ($pdf['medium'] ?? 'sheet') === 'roll' ? 'roll' : 'sheet';
        $format = strtoupper((string) ($pdf['paper_format'] ?? 'A4'));
        if (! isset(self::SHEET_SIZES[$format])) {
            // Unknown or hostile value: it ends up in `@page{size:...}`, so never pass it on.
            $format = 'A4';
        }

        return new self(
            medium: $medium,
            paperFormat: $medium === 'sheet' ? $format : null,
            widthMm: (float) ($pdf['width_mm'] ?? 0),
            margins: [
                'top'    => (float) ($pdf['margins']['top'] ?? 0),
                'right'  => (float) ($pdf['margins']['right'] ?? 0),
                'bottom' => (float) ($pdf['margins']['bottom'] ?? 0),
                'left'   => (float) ($pdf['margins']['left'] ?? 0),
            ],
            fit: ($pdf['fit'] ?? 'fixed') === 'measure' ? 'measure' : 'fixed',
            maxHeightMm: (float) ($pdf['max_height_mm'] ?? 0),
        );
    }

    /**
     * Sheet size in mm (A4 when unknown); a roll has its width and INF height.
     *
     * @return array{width: float, height: float}
     */
    public function sheetSizeMm(): array
    {
        if ($this->medium === 'roll') {
            return ['width' => $this->widthMm, 'height' => INF];
        }

        return self::SHEET_SIZES[strtoupper((string) $this->paperFormat)] ?? self::SHEET_SIZES['A4'];
    }
}
