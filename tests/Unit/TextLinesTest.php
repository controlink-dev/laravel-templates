<?php

namespace Controlink\Templates\Tests\Unit;

use Controlink\Templates\Support\FontMetrics;
use Controlink\Templates\Support\TextLines;
use Controlink\Templates\Tests\TestCase;

/**
 * How many lines a cell's text takes in a column: greedy at words, like the browser,
 * typed line breaks kept, a word wider than the column broken by character (CGE-89).
 */
class TextLinesTest extends TestCase
{
    private FontMetrics $font;

    protected function setUp(): void
    {
        parent::setUp();

        $this->font = FontMetrics::fromFile(__DIR__.'/../../resources/fonts/LiberationSans-Regular.ttf');
    }

    public function test_short_text_is_one_line(): void
    {
        $this->assertSame(1, TextLines::count($this->font, 'Parafuso', 8, 80));
    }

    public function test_empty_text_is_one_line(): void
    {
        $this->assertSame(1, TextLines::count($this->font, '', 8, 80));
    }

    public function test_text_wider_than_the_column_wraps_at_words(): void
    {
        // Ten equal words; the column holds exactly three "word " groups and a bit.
        $text  = trim(str_repeat('abcd ', 10));
        $three = $this->font->widthMm('abcd abcd abcd', 8);

        $this->assertSame(4, TextLines::count($this->font, $text, 8, $three + 0.01));
    }

    public function test_repeated_spaces_collapse_like_pre_line(): void
    {
        $one = $this->font->widthMm('abcd abcd', 8);

        $this->assertSame(1, TextLines::count($this->font, 'abcd      abcd', 8, $one + 0.01));
    }

    public function test_newlines_force_breaks(): void
    {
        $this->assertSame(3, TextLines::count($this->font, "a\nb\nc", 8, 80));
        $this->assertSame(2, TextLines::count($this->font, "a\r\nb", 8, 80));
    }

    public function test_a_word_longer_than_the_width_breaks_by_character(): void
    {
        $word  = str_repeat('X', 200);
        $lines = TextLines::count($this->font, $word, 8, 10);

        // Whole characters per line, the rest of each line's width left unused.
        $perLine = (int) floor(10 / $this->font->widthMm('X', 8));

        $this->assertSame((int) ceil(200 / $perLine), $lines);
    }
}
