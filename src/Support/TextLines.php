<?php

namespace Controlink\Templates\Support;

/**
 * How many lines a text takes in a column of a given width (CGE-89).
 *
 * The same breaking the cell's CSS asks of the engine (`white-space: pre-line;
 * overflow-wrap: anywhere`): typed line breaks are kept, runs of spaces collapse, words
 * fill a line greedily, and a word wider than the column is broken by character.
 */
class TextLines
{
    public static function count(FontMetrics $font, string $text, float $fontPt, float $widthMm): int
    {
        $lines = 0;

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [''] as $paragraph) {
            $lines += self::paragraph($font, $paragraph, $fontPt, $widthMm);
        }

        return max(1, $lines);
    }

    private static function paragraph(FontMetrics $font, string $paragraph, float $fontPt, float $widthMm): int
    {
        $words = preg_split('/[ \t]+/', trim($paragraph, " \t"), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return 1;
        }

        $lines   = 1;
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;

            if ($font->widthMm($candidate, $fontPt) <= $widthMm) {
                $current = $candidate;

                continue;
            }

            if ($current !== '') {
                $lines++;
            }

            // The word starts a fresh line; one wider than the column is cut by character,
            // and the last piece carries on as the current line.
            $current = '';

            foreach (mb_str_split($word) as $character) {
                if ($current !== '' && $font->widthMm($current.$character, $fontPt) > $widthMm) {
                    $lines++;
                    $current = '';
                }

                $current .= $character;
            }
        }

        return $lines;
    }
}
