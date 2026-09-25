<?php

namespace Controlink\Templates\Support;

final class PageGeometry
{
    /** @param array{top: float, right: float, bottom: float, left: float} $margins millimetres */
    public function __construct(
        public readonly string $paperFormat,
        public readonly array $margins,
    ) {
    }

    /** @param array<string, mixed> $pdf the `pdf` block of a template type */
    public static function fromType(array $pdf): self
    {
        return new self(
            (string) ($pdf['paper_format'] ?? 'A4'),
            [
                'top'    => (float) ($pdf['margins']['top'] ?? 0),
                'right'  => (float) ($pdf['margins']['right'] ?? 0),
                'bottom' => (float) ($pdf['margins']['bottom'] ?? 0),
                'left'   => (float) ($pdf['margins']['left'] ?? 0),
            ],
        );
    }
}
