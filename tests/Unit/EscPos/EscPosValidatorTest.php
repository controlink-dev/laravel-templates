<?php

namespace Controlink\Templates\Tests\Unit\EscPos;

use Controlink\Templates\Output\EscPos\EscPosValidator;
use Controlink\Templates\Output\EscPos\Receipt;
use Controlink\Templates\Tests\TestCase;

/** E-01 reads the receipt's lines (CGE-97): spaces and NBSP alike, never across a break. */
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

    public function test_a_transliterated_value_is_found_as_the_receipt_prints_it(): void
    {
        // «Ł» is not in CP858: the builder lays out «L», and E-01 looks for that.
        $this->assertSame([], $this->codes($this->receipt(['Lodz']), 'Łodz'));
    }
}
