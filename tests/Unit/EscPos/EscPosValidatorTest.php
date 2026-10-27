<?php

namespace Controlink\Templates\Tests\Unit\EscPos;

use Controlink\Templates\Output\EscPos\EscPosValidator;
use Controlink\Templates\Output\EscPos\Receipt;
use Controlink\Templates\Tests\TestCase;

/** E-01 reads the receipt by block (CGE-97): spaces and NBSP alike, across a block's own lines, never across two blocks. */
class EscPosValidatorTest extends TestCase
{
    private const FIELD = ['key' => 'cliente.nome', 'label' => 'Nome', 'type' => 'text', 'mandatory' => true, 'legal' => true];

    /** @param list<string> $lines */
    private function receipt(array $lines): Receipt
    {
        return new Receipt(40, array_map(
            fn (string $text): array => ['kind' => 'line', 'text' => $text, 'double' => false, 'bold' => [], 'sources' => []],
            $lines,
        ));
    }

    /** @return list<string> */
    private function codes(Receipt $receipt, string $value): array
    {
        return array_column(app(EscPosValidator::class)->validate($receipt, [self::FIELD], ['cliente.nome' => $value]), 'code');
    }

    public function test_a_value_on_one_line_is_found_whatever_its_spaces(): void
    {
        $this->assertSame([], $this->codes($this->receipt(['Cliente:  Ana   Maria Sá']), "Ana Maria\u{00A0}Sá"));
    }

    public function test_a_value_split_over_two_lines_is_e01(): void
    {
        $this->assertSame(['E-01'], $this->codes($this->receipt(['Cliente: Ana', 'Maria Sá']), 'Ana Maria Sá'));
    }

    public function test_a_wrapped_legal_value_is_found_across_its_own_lines(): void
    {
        $name    = 'Sociedade Comercial de Equipamentos Industriais e Agricolas de Leiria, Unip.';
        $receipt = new Receipt(40, [
            ['kind' => 'line', 'text' => 'Cliente:'.str_repeat(' ', 32), 'double' => false, 'bold' => [], 'sources' => ['tpl-label'], 'group' => 0],
            ['kind' => 'line', 'text' => 'Sociedade Comercial de Equipamentos     ', 'double' => false, 'bold' => [], 'sources' => ['tpl-name'], 'group' => 1],
            ['kind' => 'line', 'text' => "Industriais e Agricolas de Leiria,\u{00A0}Unip.", 'double' => false, 'bold' => [], 'sources' => ['tpl-name'], 'group' => 1],
        ]);

        $this->assertSame([], $this->codes($receipt, $name));

        // With no group, consecutive lines of the same source are one block.
        $this->assertSame([], $this->codes(new Receipt(40, [
            ['kind' => 'line', 'text' => 'Sociedade Comercial de Equipamentos', 'double' => false, 'bold' => [], 'sources' => ['tpl-name']],
            ['kind' => 'line', 'text' => 'Industriais e Agricolas de Leiria, Unip.', 'double' => false, 'bold' => [], 'sources' => ['tpl-name']],
        ]), $name));
    }

    public function test_lines_join_as_they_broke(): void
    {
        $line = fn (string $text, string $joint): array => ['kind' => 'line', 'text' => $text, 'double' => false, 'bold' => [], 'sources' => ['tpl-nome'], 'group' => 1, 'joint' => $joint];

        // After a hyphen, nothing between the lines; after a space, one space.
        $this->assertSame([], $this->codes(new Receipt(40, [$line('Ana Silva-', ' '), $line('Santos', '')]), 'Ana Silva-Santos'));
        $this->assertSame(['E-01'], $this->codes(new Receipt(40, [$line('Ana Silva-', ' '), $line('Santos', ' ')]), 'Ana Silva-Santos'));
    }

    public function test_a_postal_code_split_at_its_hyphen_is_found(): void
    {
        $address = 'Rua Doutor António Bernardino de Almeida, n.º 1234, 5.º Esquerdo, 4200-072 Porto';
        $line    = fn (string $text, string $joint): array => ['kind' => 'line', 'text' => $text, 'double' => false, 'bold' => [], 'sources' => ['tpl-morada'], 'group' => 1, 'joint' => $joint];
        $lines   = [
            $line('Rua Doutor António Bernardino de        ', ' '),
            $line('Almeida, n.º 1234, 5.º Esquerdo, 4200-  ', ' '),
        ];

        // As the builder breaks it (ReceiptBuilderTest): «4200-» / «072 Porto», joined by nothing.
        $this->assertSame([], $this->codes(new Receipt(40, [...$lines, $line('072 Porto', '')]), $address));
        $this->assertSame(['E-01'], $this->codes(new Receipt(40, [...$lines, $line('072 Porto', ' ')]), $address));
    }

    public function test_a_value_is_not_found_across_two_blocks(): void
    {
        $line = fn (string $text, array $sources, ?int $group): array => ['kind' => 'line', 'text' => $text, 'double' => false, 'bold' => [], 'sources' => $sources]
            + ($group === null ? [] : ['group' => $group]);

        // Two blocks, by group even when they share a source (an id'd container's two texts).
        $this->assertSame(['E-01'], $this->codes(new Receipt(40, [
            $line('Cliente: Ana', ['tpl-box'], 0),
            $line('Maria Sá', ['tpl-box'], 1),
        ]), 'Ana Maria Sá'));

        // Two blocks, by source.
        $this->assertSame(['E-01'], $this->codes(new Receipt(40, [
            $line('Cliente: Ana', ['tpl-a'], null),
            $line('Maria Sá', ['tpl-b'], null),
        ]), 'Ana Maria Sá'));

        // The same source, not consecutive: a line of another block between them.
        $this->assertSame(['E-01'], $this->codes(new Receipt(40, [
            $line('Cliente: Ana', ['tpl-a'], null),
            $line('---', [], null),
            $line('Maria Sá', ['tpl-a'], null),
        ]), 'Ana Maria Sá'));
    }

    public function test_a_value_with_a_line_break_or_tab_printed_on_one_line_is_found(): void
    {
        // The builder collapses \n and \t to one space: «Rua X\nLisboa» prints as «Rua X Lisboa».
        $this->assertSame([], $this->codes($this->receipt(['Rua X Lisboa']), "Rua X\nLisboa"));
        $this->assertSame([], $this->codes($this->receipt(['Rua X Lisboa']), "Rua X\t\r\nLisboa"));
    }

    public function test_a_transliterated_value_is_found_as_the_receipt_prints_it(): void
    {
        // «Ł» is not in CP858: the builder lays out «L», and E-01 looks for that.
        $this->assertSame([], $this->codes($this->receipt(['Lodz']), 'Łodz'));
    }
}
