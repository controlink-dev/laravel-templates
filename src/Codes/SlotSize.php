<?php

namespace Controlink\Templates\Codes;

/**
 * The size a code slot was drawn at, in millimetres (CGE-98).
 *
 * The editor runs GrapesJS with `avoidInlineStyle: true`, so a slot it placed carries its
 * width and height in a `#id{…}` rule of the version CSS, not in its `style` attribute;
 * a seeded or hand-written slot may carry them inline. Each dimension is read inline
 * first, as the cascade would, then from the `#id` rule (a later rule wins).
 *
 * The one reader of that box: the batch pre-check and the image generator both ask here,
 * so a barcode the pre-check accepts is a barcode the render can draw.
 */
final class SlotSize
{
    /** @var array<string, array<string, array{width?: float, height?: float}>> parsed CSS, by its hash */
    private static array $parsed = [];

    /**
     * @return array{width: float|null, height: float|null} null where neither says
     */
    public static function of(string $style, string $id, string $css = ''): array
    {
        $rule = $id !== '' && $css !== '' ? (self::rules($css)[$id] ?? []) : [];

        return [
            'width'  => self::length($style, 'width') ?? $rule['width'] ?? null,
            'height' => self::length($style, 'height') ?? $rule['height'] ?? null,
        ];
    }

    /**
     * `width`/`height` in millimetres in a declaration list, or null. Only the property
     * itself: `max-width`, `min-height` and `line-height` are not the box.
     */
    private static function length(string $declarations, string $property): ?float
    {
        return preg_match('/(?<![-\w])'.$property.'\s*:\s*([\d.]+)mm/i', $declarations, $m) === 1 ? (float) $m[1] : null;
    }

    /**
     * Each `#id` rule's width and height.
     *
     * @return array<string, array{width?: float, height?: float}>
     */
    private static function rules(string $css): array
    {
        $key = md5($css);

        if (isset(self::$parsed[$key])) {
            return self::$parsed[$key];
        }

        $stripped = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        $rules    = [];

        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $stripped, $blocks, PREG_SET_ORDER);

        foreach ($blocks as $block) {
            $size = array_filter(
                ['width' => self::length($block[2], 'width'), 'height' => self::length($block[2], 'height')],
                fn (?float $value): bool => $value !== null,
            );

            if ($size === []) {
                continue;
            }

            foreach (explode(',', $block[1]) as $selector) {
                if (preg_match('/^#([\w-]+)$/', trim($selector), $m) === 1) {
                    $rules[$m[1]] = array_merge($rules[$m[1]] ?? [], $size);
                }
            }
        }

        // A handful of stylesheets per request; the cap only guards a long-lived worker.
        if (count(self::$parsed) >= 16) {
            self::$parsed = [];
        }

        return self::$parsed[$key] = $rules;
    }
}
