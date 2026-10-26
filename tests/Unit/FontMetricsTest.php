<?php

namespace Controlink\Templates\Tests\Unit;

use Controlink\Templates\Support\FontMetrics;
use Controlink\Templates\Tests\TestCase;

/**
 * The compositor measures line-table text with the advances of the very TTF the PDF
 * embeds (CGE-89), so a row's height is arithmetic, never the engine's judgement.
 */
class FontMetricsTest extends TestCase
{
    private const REGULAR = __DIR__.'/../../resources/fonts/LiberationSans-Regular.ttf';

    public function test_width_is_the_sum_of_advances_scaled_to_the_point_size(): void
    {
        $font = FontMetrics::fromFile(self::REGULAR);

        // LiberationSans is metric-compatible with Arial: "M" is 1706/2048 em, space 569/2048.
        $this->assertEqualsWithDelta(1706 / 2048 * 10 * 25.4 / 72, $font->widthMm('M', 10), 0.0001);
        $this->assertEqualsWithDelta(569 / 2048 * 10 * 25.4 / 72, $font->widthMm(' ', 10), 0.0001);
        $this->assertEqualsWithDelta(
            2 * $font->widthMm('M', 10) + $font->widthMm(' ', 10),
            $font->widthMm('M M', 10),
            0.0001,
        );
    }

    public function test_accented_letters_are_measured_from_the_cmap(): void
    {
        $font = FontMetrics::fromFile(self::REGULAR);

        // "ç" and "c" share an advance in Arial metrics (1024/2048).
        $this->assertEqualsWithDelta($font->widthMm('c', 10), $font->widthMm('ç', 10), 0.0001);
        $this->assertGreaterThan(0.0, $font->widthMm('ç', 10));
    }

    public function test_unknown_glyphs_use_the_fallback_advance(): void
    {
        $font = FontMetrics::fromFile(self::REGULAR);

        $this->assertGreaterThan(0.0, $font->widthMm("\u{1F600}", 10));
    }

    public function test_from_config_reads_the_configured_family(): void
    {
        $this->assertEqualsWithDelta(
            FontMetrics::fromFile(self::REGULAR)->widthMm('Fatura', 8),
            FontMetrics::fromConfig(400)->widthMm('Fatura', 8),
            0.0001,
        );

        $bold = __DIR__.'/../../resources/fonts/LiberationSans-Bold.ttf';

        $this->assertEqualsWithDelta(
            FontMetrics::fromFile($bold)->widthMm('Fatura', 8),
            FontMetrics::fromConfig(700)->widthMm('Fatura', 8),
            0.0001,
        );
    }
}
