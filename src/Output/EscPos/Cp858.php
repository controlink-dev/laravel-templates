<?php

namespace Controlink\Templates\Output\EscPos;

/**
 * UTF-8 to CP858, the code page `ESC t 19` selects (CGE-97).
 *
 * A static table of our own, never iconv: `iconv('UTF-8', 'CP858//TRANSLIT', …)` returns
 * "" on the Windows PHP of this project, and transliterates differently under glibc and
 * libiconv. Three sources, in order:
 *
 * 1. printable ASCII (0x20–0x7E), byte for byte;
 * 2. the 128 high characters of CP858 (CP850 with the euro at 0xD5);
 * 3. a transliteration map, each entry ONE character for one, so a transliterated text
 *    keeps its width on the character grid (the builder lays out the normalized text).
 *
 * Anything else — control characters included, which would be printer commands — throws
 * `UnencodableText`.
 */
final class Cp858
{
    /** The high half: byte 0x80 + index. 0xD5 is the euro (CP850 has the dotless i there). */
    private const HIGH = [
        'Ç', 'ü', 'é', 'â', 'ä', 'à', 'å', 'ç', 'ê', 'ë', 'è', 'ï', 'î', 'ì', 'Ä', 'Å',
        'É', 'æ', 'Æ', 'ô', 'ö', 'ò', 'û', 'ù', 'ÿ', 'Ö', 'Ü', 'ø', '£', 'Ø', '×', 'ƒ',
        'á', 'í', 'ó', 'ú', 'ñ', 'Ñ', 'ª', 'º', '¿', '®', '¬', '½', '¼', '¡', '«', '»',
        '░', '▒', '▓', '│', '┤', 'Á', 'Â', 'À', '©', '╣', '║', '╗', '╝', '¢', '¥', '┐',
        '└', '┴', '┬', '├', '─', '┼', 'ã', 'Ã', '╚', '╔', '╩', '╦', '╠', '═', '╬', '¤',
        'ð', 'Ð', 'Ê', 'Ë', 'È', '€', 'Í', 'Î', 'Ï', '┘', '┌', '█', '▄', '¦', 'Ì', '▀',
        'Ó', 'ß', 'Ô', 'Ò', 'õ', 'Õ', 'µ', 'þ', 'Þ', 'Ú', 'Û', 'Ù', 'ý', 'Ý', '¯', '´',
        "\u{00AD}", '±', '‗', '¾', '¶', '§', '÷', '¸', '°', '¨', '·', '¹', '³', '²', '■', "\u{00A0}",
    ];

    /**
     * One character for one: typographic punctuation to ASCII, and letters with a mark
     * CP858 lacks to the letter without it (or with a mark CP858 has).
     */
    private const TRANSLITERATION = [
        // Quotes, dashes, spaces and the like.
        '‘' => "'", '’' => "'", '‚' => "'", '‛' => "'", '′' => "'", '‹' => '<', '›' => '>',
        '“' => '"', '”' => '"', '„' => '"', '‟' => '"', '″' => '"', '…' => '.',
        '‐' => '-', "\u{2011}" => '-', '‒' => '-', '–' => '-', '—' => '-', '―' => '-', '−' => '-',
        '•' => '·', '∙' => '·', '‧' => '·', '⁄' => '/', '∕' => '/',
        "\u{2002}" => ' ', "\u{2003}" => ' ', "\u{2007}" => "\u{00A0}", "\u{2009}" => ' ',
        "\u{200A}" => ' ', "\u{202F}" => "\u{00A0}",
        // Latin Extended-A (and a few beyond) that CP858 does not carry.
        'Ā' => 'A', 'ā' => 'a', 'Ă' => 'A', 'ă' => 'a', 'Ą' => 'A', 'ą' => 'a',
        'Ć' => 'C', 'ć' => 'c', 'Ĉ' => 'C', 'ĉ' => 'c', 'Ċ' => 'C', 'ċ' => 'c', 'Č' => 'C', 'č' => 'c',
        'Ď' => 'D', 'ď' => 'd', 'Đ' => 'Ð', 'đ' => 'd',
        'Ē' => 'E', 'ē' => 'e', 'Ĕ' => 'E', 'ĕ' => 'e', 'Ė' => 'E', 'ė' => 'e', 'Ę' => 'E', 'ę' => 'e', 'Ě' => 'E', 'ě' => 'e',
        'Ĝ' => 'G', 'ĝ' => 'g', 'Ğ' => 'G', 'ğ' => 'g', 'Ġ' => 'G', 'ġ' => 'g', 'Ģ' => 'G', 'ģ' => 'g',
        'Ĥ' => 'H', 'ĥ' => 'h', 'Ħ' => 'H', 'ħ' => 'h',
        'Ĩ' => 'I', 'ĩ' => 'i', 'Ī' => 'I', 'ī' => 'i', 'Ĭ' => 'I', 'ĭ' => 'i', 'Į' => 'I', 'į' => 'i', 'İ' => 'I', 'ı' => 'i',
        'Ĵ' => 'J', 'ĵ' => 'j', 'Ķ' => 'K', 'ķ' => 'k', 'ĸ' => 'k',
        'Ĺ' => 'L', 'ĺ' => 'l', 'Ļ' => 'L', 'ļ' => 'l', 'Ľ' => 'L', 'ľ' => 'l', 'Ŀ' => 'L', 'ŀ' => 'l', 'Ł' => 'L', 'ł' => 'l',
        'Ń' => 'N', 'ń' => 'n', 'Ņ' => 'N', 'ņ' => 'n', 'Ň' => 'N', 'ň' => 'n', 'Ŋ' => 'N', 'ŋ' => 'n',
        'Ō' => 'O', 'ō' => 'o', 'Ŏ' => 'O', 'ŏ' => 'o', 'Ő' => 'Ö', 'ő' => 'ö',
        'Ŕ' => 'R', 'ŕ' => 'r', 'Ŗ' => 'R', 'ŗ' => 'r', 'Ř' => 'R', 'ř' => 'r',
        'Ś' => 'S', 'ś' => 's', 'Ŝ' => 'S', 'ŝ' => 's', 'Ş' => 'S', 'ş' => 's', 'Š' => 'S', 'š' => 's',
        'Ţ' => 'T', 'ţ' => 't', 'Ť' => 'T', 'ť' => 't', 'Ŧ' => 'T', 'ŧ' => 't',
        'Ũ' => 'U', 'ũ' => 'u', 'Ū' => 'U', 'ū' => 'u', 'Ŭ' => 'U', 'ŭ' => 'u', 'Ů' => 'U', 'ů' => 'u',
        'Ű' => 'Ü', 'ű' => 'ü', 'Ų' => 'U', 'ų' => 'u',
        'Ŵ' => 'W', 'ŵ' => 'w', 'Ŷ' => 'Y', 'ŷ' => 'y', 'Ÿ' => 'Y',
        'Ź' => 'Z', 'ź' => 'z', 'Ż' => 'Z', 'ż' => 'z', 'Ž' => 'Z', 'ž' => 'z', 'ſ' => 's',
        'Ș' => 'S', 'ș' => 's', 'Ț' => 'T', 'ț' => 't',
        'Ẽ' => 'E', 'ẽ' => 'e', 'Ỹ' => 'Y', 'ỹ' => 'y',
    ];

    /** @var array<string, int>|null the high table, character => byte */
    private static ?array $bytes = null;

    /**
     * The text in Unicode composed form (NFC): a letter typed as letter + combining mark
     * ("c" + U+0327) becomes the one character the table knows (ç), as it is one glyph in
     * the PDF. Unchanged where PHP has no intl extension.
     */
    public static function compose(string $utf8): string
    {
        if ($utf8 === '' || ! class_exists(\Normalizer::class)) {
            return $utf8;
        }

        $composed = \Normalizer::normalize($utf8, \Normalizer::FORM_C);

        return is_string($composed) ? $composed : $utf8;
    }

    /**
     * The text as the printer will print it, still in UTF-8: every character in CP858,
     * transliterated one for one where it had to be. Same length as the composed input.
     *
     * @throws UnencodableText
     */
    public static function normalize(string $utf8, ?string $field = null): string
    {
        $out = '';

        foreach (mb_str_split(self::compose($utf8), 1, 'UTF-8') as $char) {
            $out .= self::printable($char, $field);
        }

        return $out;
    }

    /**
     * @throws UnencodableText
     */
    public static function encode(string $utf8, ?string $field = null): string
    {
        $bytes = self::bytes();
        $out   = '';

        foreach (mb_str_split(self::compose($utf8), 1, 'UTF-8') as $char) {
            $char = self::printable($char, $field);
            $out .= strlen($char) === 1 ? $char : chr($bytes[$char]);
        }

        return $out;
    }

    /** The character itself when the printer has it, else its transliteration. */
    private static function printable(string $char, ?string $field): string
    {
        $bytes = self::bytes();

        if (self::isAscii($char) || isset($bytes[$char])) {
            return $char;
        }

        $other = self::TRANSLITERATION[$char] ?? null;

        if ($other !== null && (self::isAscii($other) || isset($bytes[$other]))) {
            return $other;
        }

        throw new UnencodableText($field, $char);
    }

    private static function isAscii(string $char): bool
    {
        return strlen($char) === 1 && ord($char) >= 0x20 && ord($char) <= 0x7E;
    }

    /** @return array<string, int> */
    private static function bytes(): array
    {
        if (self::$bytes === null) {
            self::$bytes = [];

            foreach (self::HIGH as $index => $char) {
                self::$bytes[$char] = 0x80 + $index;
            }
        }

        return self::$bytes;
    }
}
