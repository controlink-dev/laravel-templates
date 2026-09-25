<?php

namespace Controlink\Templates\Output\Pdf;

/**
 * Colour parsing and WCAG contrast, for the legibility rules (V-12).
 *
 * Only the notations the editor can produce are understood: `rgb()`, `rgba()`, hex
 * and `transparent`. Anything else returns null, and a rule that cannot resolve a
 * colour stays silent rather than inventing one — a false "insufficient contrast" on
 * a field that is perfectly legible teaches the user to ignore the panel.
 */
class Colour
{
    /**
     * @param  float  $alpha  0 = fully transparent, 1 = opaque
     */
    private function __construct(
        public readonly int $red,
        public readonly int $green,
        public readonly int $blue,
        public readonly float $alpha,
    ) {}

    public static function parse(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        $value = mb_strtolower(trim($value));

        if ($value === '' || $value === 'transparent') {
            return new self(0, 0, 0, 0.0);
        }

        if (preg_match('/^#([0-9a-f]{3,8})$/', $value, $matches) === 1) {
            return self::fromHex($matches[1]);
        }

        if (preg_match('/^rgba?\(([^)]+)\)$/', $value, $matches) === 1) {
            return self::fromRgb($matches[1]);
        }

        // The editor's colour picker emits hex or rgb(); a named colour can only come
        // from hand-written config, and only white and black are worth knowing.
        return match ($value) {
            'white' => new self(255, 255, 255, 1.0),
            'black' => new self(0, 0, 0, 1.0),
            default => null,
        };
    }

    private static function fromHex(string $digits): ?self
    {
        $length = strlen($digits);

        if ($length === 3 || $length === 4) {
            $digits = implode('', array_map(fn (string $c): string => $c.$c, str_split($digits)));
            $length = strlen($digits);
        }

        if ($length !== 6 && $length !== 8) {
            return null;
        }

        return new self(
            (int) hexdec(substr($digits, 0, 2)),
            (int) hexdec(substr($digits, 2, 2)),
            (int) hexdec(substr($digits, 4, 2)),
            $length === 8 ? hexdec(substr($digits, 6, 2)) / 255 : 1.0,
        );
    }

    private static function fromRgb(string $arguments): ?self
    {
        $parts = preg_split('/[,\s\/]+/', trim($arguments)) ?: [];
        $parts = array_values(array_filter($parts, fn (string $part): bool => $part !== ''));

        if (count($parts) < 3) {
            return null;
        }

        return new self(
            (int) round(self::channel($parts[0])),
            (int) round(self::channel($parts[1])),
            (int) round(self::channel($parts[2])),
            isset($parts[3]) ? self::alpha($parts[3]) : 1.0,
        );
    }

    private static function channel(string $value): float
    {
        return str_ends_with($value, '%')
            ? ((float) rtrim($value, '%')) * 255 / 100
            : (float) $value;
    }

    private static function alpha(string $value): float
    {
        $alpha = str_ends_with($value, '%')
            ? ((float) rtrim($value, '%')) / 100
            : (float) $value;

        return max(0.0, min(1.0, $alpha));
    }

    public function isOpaque(): bool
    {
        return $this->alpha >= 1.0;
    }

    public function isTransparent(): bool
    {
        return $this->alpha <= 0.0;
    }

    /**
     * Composite this colour over an opaque backdrop, so a semi-transparent text colour
     * is measured as it will actually appear.
     */
    public function over(self $backdrop): self
    {
        if ($this->isOpaque()) {
            return $this;
        }

        $blend = fn (int $top, int $bottom): int => (int) round(
            $top * $this->alpha + $bottom * (1 - $this->alpha),
        );

        return new self(
            $blend($this->red, $backdrop->red),
            $blend($this->green, $backdrop->green),
            $blend($this->blue, $backdrop->blue),
            1.0,
        );
    }

    /** WCAG 2.x relative luminance. */
    public function luminance(): float
    {
        $channel = static function (int $value): float {
            $ratio = $value / 255;

            return $ratio <= 0.04045
                ? $ratio / 12.92
                : (($ratio + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($this->red)
            + 0.7152 * $channel($this->green)
            + 0.0722 * $channel($this->blue);
    }

    /** WCAG 2.x contrast ratio, between 1 and 21. */
    public function contrastWith(self $other): float
    {
        $a = $this->luminance();
        $b = $other->luminance();

        [$lighter, $darker] = $a >= $b ? [$a, $b] : [$b, $a];

        return ($lighter + 0.05) / ($darker + 0.05);
    }
}
