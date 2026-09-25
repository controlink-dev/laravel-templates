<?php

namespace Controlink\Templates\Data;

/**
 * The sample value of a calculated field.
 *
 * It is not decoration, and the reason is not a truncation rule — there is no static
 * truncation rule at all. The reason is the preview: the canvas shows the fixture and
 * never the template expression, and `TemplateRenderer` builds its Twig environment with
 * `strict_variables => true`. A calculated field placed on the canvas with no sample has
 * no dataset key, and the preview then throws instead of rendering. The sample is what
 * makes the field previewable at all.
 *
 * Because it is also what the canvas measures a box against, it is the longest plausible
 * value the column accepts rather than a pretty one — a box laid out around "Ana" is a box
 * a real name overflows.
 *
 * The defaults exist because SQLite does not report a column's length or scale — measured:
 * `Schema::getColumns()` returns `varchar` there where MySQL returns `varchar(150)`. They
 * are declared here, in one place, rather than guessed at each call site.
 */
class FixtureValue
{
    /** Roughly the width of a typical name or address column. */
    public const DEFAULT_TEXT_LENGTH = 60;

    /**
     * The length of an encrypted column's sample.
     *
     * An encrypted column is a blob: what the driver reports is the width of the
     * CIPHERTEXT, which says nothing about the plaintext a document prints. There is no
     * length here to measure, so the sample is a declared, plausible value rather than a
     * measurement pretending to be one.
     */
    public const ENCRYPTED_TEXT_LENGTH = 30;

    /** A conservative default decimal scale, used when a column reports none. */
    public const DEFAULT_SCALE = 2;

    public static function for(ColumnDescriptor $column): string
    {
        if ($column->isBoolean) {
            return __('templates::editor.fixtures.yes');
        }

        return match ($column->bindingType) {
            'date'   => '2026-12-31',
            'number' => self::nines($column->scale ?? self::DEFAULT_SCALE),
            default  => self::text($column->length ?? self::DEFAULT_TEXT_LENGTH),
        };
    }

    /**
     * Readable text repeated up to the column's limit and cut exactly there — a run of
     * `x` would measure the same width but tells a reader nothing about what the field is.
     *
     * The sentence itself is translated, like everything else the user reads: it is shown
     * on the canvas and in the preview, and a Portuguese sample in an English editor is a
     * string that escaped the translation files.
     */
    private static function text(int $length): string
    {
        $sample = __('templates::editor.fixtures.text_sample').' ';

        return mb_substr(str_repeat($sample, (int) ceil($length / mb_strlen($sample)) + 1), 0, $length);
    }

    /** The widest number the column can hold: nines up to the scale, and four ahead of it. */
    private static function nines(int $scale): string
    {
        return $scale > 0
            ? str_repeat('9', 4).'.'.str_repeat('9', $scale)
            : str_repeat('9', 4);
    }
}
