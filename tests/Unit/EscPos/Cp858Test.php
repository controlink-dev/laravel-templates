<?php

namespace Controlink\Templates\Tests\Unit\EscPos;

use Controlink\Templates\Output\EscPos\Cp858;
use Controlink\Templates\Output\EscPos\UnencodableText;
use PHPUnit\Framework\TestCase;

/**
 * CP858 (CGE-97): what `ESC t 19` selects on the printer. A static table of our own,
 * never iconv (which returns "" on this platform's PHP).
 */
class Cp858Test extends TestCase
{
    /** The high half of CP858 (CP850 with the euro at 0xD5), the reference the table must match. */
    private const REFERENCE = [
        0x80 => 'Ç', 'ü', 'é', 'â', 'ä', 'à', 'å', 'ç', 'ê', 'ë', 'è', 'ï', 'î', 'ì', 'Ä', 'Å',
        'É', 'æ', 'Æ', 'ô', 'ö', 'ò', 'û', 'ù', 'ÿ', 'Ö', 'Ü', 'ø', '£', 'Ø', '×', 'ƒ',
        'á', 'í', 'ó', 'ú', 'ñ', 'Ñ', 'ª', 'º', '¿', '®', '¬', '½', '¼', '¡', '«', '»',
        '░', '▒', '▓', '│', '┤', 'Á', 'Â', 'À', '©', '╣', '║', '╗', '╝', '¢', '¥', '┐',
        '└', '┴', '┬', '├', '─', '┼', 'ã', 'Ã', '╚', '╔', '╩', '╦', '╠', '═', '╬', '¤',
        'ð', 'Ð', 'Ê', 'Ë', 'È', '€', 'Í', 'Î', 'Ï', '┘', '┌', '█', '▄', '¦', 'Ì', '▀',
        'Ó', 'ß', 'Ô', 'Ò', 'õ', 'Õ', 'µ', 'þ', 'Þ', 'Ú', 'Û', 'Ù', 'ý', 'Ý', '¯', '´',
        "\u{00AD}", '±', '‗', '¾', '¶', '§', '÷', '¸', '°', '¨', '·', '¹', '³', '²', '■', "\u{00A0}",
    ];

    public function test_portuguese_text_is_encoded_in_cp858(): void
    {
        $this->assertSame(
            "\x87\xC6o \x80 \x82 \xC6 \xE4 \xD5",
            Cp858::encode('ção Ç é ã õ €'),
        );
        $this->assertSame("Isento artigo 9.\xA7", Cp858::encode('Isento artigo 9.º'));

        // Every one of the 128 high characters maps to its own byte.
        foreach (self::REFERENCE as $byte => $char) {
            $this->assertSame(chr($byte), Cp858::encode($char), sprintf('0x%02X %s', $byte, $char));
        }
    }

    public function test_typographic_characters_are_transliterated_one_for_one(): void
    {
        // ó is in the table and stays; Ł, ź and Ş are not and lose their marks.
        $this->assertSame('"Aspas" - \'x\' - Lódz S', Cp858::normalize('“Aspas” — ‘x’ – Łódź Ş'));
        $this->assertSame("\"Aspas\" - 'x' - L\xA2dz S", Cp858::encode('“Aspas” — ‘x’ – Łódź Ş'));
        // The dotless i is 0xD5 in CP850, but the euro in CP858: it loses its dot instead.
        $this->assertSame('i', Cp858::encode('ı'));
    }

    public function test_unmappable_character_throws_with_the_field(): void
    {
        try {
            Cp858::encode('Cliente 名', 'customer.name');
            $this->fail('No exception.');
        } catch (UnencodableText $e) {
            $this->assertSame('customer.name', $e->field);
            $this->assertSame('名', $e->char);
        }
    }

    public function test_control_characters_never_reach_the_printer(): void
    {
        // An ESC in the data would be a printer command, not text.
        $this->expectException(UnencodableText::class);

        Cp858::encode("abc\x1B@");
    }

    public function test_decomposed_letters_are_composed_before_they_are_mapped(): void
    {
        // "c" + U+0327 (combining cedilla) and "a" + U+0303 (combining tilde), as some
        // keyboards and imports write them: printed as ç and ã, not E-03.
        $this->assertSame('ção', Cp858::normalize("c\u{0327}a\u{0303}o"));
        $this->assertSame("\x87\xC6o", Cp858::encode("c\u{0327}a\u{0303}o"));
        $this->assertSame('ção', Cp858::compose("c\u{0327}a\u{0303}o"));
        $this->assertSame('ção', Cp858::compose('ção'));
    }

    public function test_the_ellipsis_is_one_character_for_one(): void
    {
        // One glyph in the PDF's monospace grid, one character on the receipt.
        $this->assertSame('Ver.', Cp858::normalize('Ver…'));
        $this->assertSame(mb_strlen('Ver…'), mb_strlen(Cp858::normalize('Ver…')));
    }
}
