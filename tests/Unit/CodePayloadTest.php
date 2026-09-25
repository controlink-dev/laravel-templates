<?php

namespace Controlink\Templates\Tests\Unit;

use Controlink\Templates\Codes\CodePayload;
use Controlink\Templates\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The payload of a fixed-text code.
 *
 * One implementation, used by two callers: the generator that draws the image, and the
 * host's render validator, which decodes the code back out of the rendered document and
 * compares it with what was expected. Two implementations would let the validation
 * confirm its own mistake.
 */
class CodePayloadTest extends TestCase
{
    /**
     * @return array<string, array{string, array<string, string>, string}>
     */
    public static function payloads(): array
    {
        return [
            'plain text'           => ['text', ['text' => 'Obrigado pela sua compra'], 'Obrigado pela sua compra'],
            'web address'          => ['url', ['url' => 'https://controlink.pt/f/123'], 'https://controlink.pt/f/123'],
            'email with a subject' => ['email', ['email' => 'geral@controlink.pt', 'subject' => 'Fatura 1/2026'], 'mailto:geral@controlink.pt?subject=Fatura%201%2F2026'],
            'phone'                => ['phone', ['number' => '+351210000000'], 'tel:+351210000000'],
            'sms'                  => ['sms', ['number' => '+351210000000', 'message' => 'Recebido'], 'smsto:+351210000000:Recebido'],
            'wifi'                 => ['wifi', ['security' => 'WPA', 'network' => 'Controlink', 'password' => 'segredo'], 'WIFI:T:WPA;S:Controlink;P:segredo;;'],
        ];
    }

    /**
     * @param  array<string, string>  $values
     */
    #[DataProvider('payloads')]
    public function test_each_type_composes_its_payload(string $type, array $values, string $expected): void
    {
        $this->assertSame($expected, CodePayload::compose($type, $values));
    }

    /**
     * A network called `Casa; Piso 1` would end the network name early and turn the rest
     * into a password, because `;` is what separates the fields of a `WIFI:` payload. The
     * escape is declared per type, in the catalogue, and applied here.
     */
    public function test_a_value_containing_a_separator_does_not_break_the_payload(): void
    {
        $payload = CodePayload::compose('wifi', ['security' => 'WPA', 'network' => 'Casa; Piso 1', 'password' => 'a:b']);

        $this->assertSame('WIFI:T:WPA;S:Casa\; Piso 1;P:a\:b;;', $payload);

        /*
         * A value carrying a backslash directly next to a separator is what actually
         * proves the ordering — a lone backslash has nothing for the two orders to
         * disagree on. `a\;b` escaped backslash-first becomes `a` followed by THREE
         * backslashes then `;b`: the original backslash doubles to two, and the
         * semicolon gets its own leading backslash on top. Escaped separator-first it
         * would instead be `a` followed by FOUR backslashes then `;b`: the semicolon
         * step first plants a second backslash next to the original one, and only then
         * does the backslash step double both of them.
         */
        $this->assertSame(
            'WIFI:T:WPA;S:a\\\\\\;b;P:x;;',
            CodePayload::compose('wifi', ['security' => 'WPA', 'network' => 'a\\;b', 'password' => 'x']),
        );
    }

    /** A type the catalogue does not declare is a configuration error, not an empty code. */
    public function test_an_unknown_type_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);

        CodePayload::compose('vcard', ['name' => 'X']);
    }

    /** A slot set to a content type the catalogue does not declare is refused the same way. */
    public function test_an_unknown_type_from_attributes_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);

        CodePayload::fromAttributes([
            'data-tpl-code-source' => 'fixed',
            'data-tpl-code-type'   => 'vcard',
            'data-tpl-code-name'   => 'X',
        ]);
    }

    /**
     * The slot's own attributes are the other way in — it is what the generator and the
     * render validator both hold. A dynamic slot yields null: its content comes from the
     * dataset, not from a payload.
     */
    public function test_the_attributes_of_a_slot_compose_the_same_payload(): void
    {
        $this->assertSame(
            'tel:+351210000000',
            CodePayload::fromAttributes([
                'data-tpl-code-source' => 'fixed',
                'data-tpl-code-type'   => 'phone',
                'data-tpl-code-number' => '+351210000000',
            ]),
        );

        $this->assertNull(CodePayload::fromAttributes([
            'data-tpl-code-source' => 'dynamic',
            'data-tpl-binding'     => 'documento.qr',
        ]));

        $this->assertNull(CodePayload::fromAttributes([]), 'A slot with no source is the dynamic one it has always been.');
    }
}
