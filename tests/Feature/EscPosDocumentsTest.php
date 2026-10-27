<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Toolkit\Documents;
use InvalidArgumentException;

/**
 * `Documents::escPos` (CGE-97): the receipt's bytes and preview from the same `Receipt`,
 * the legal checks over it (E-01..E-04), and V-21 before any translation.
 *
 * The bindings and values are the test type's (`tests/Fixtures/types/invoice.php`), and
 * only the three the layout places are passed: every other one is `mandatory` too, and a
 * layout without them is the E-01 case, not the valid one.
 */
class EscPosDocumentsTest extends TestCase
{
    private const ROLL_80 = [
        'medium' => 'roll', 'width_mm' => 80, 'fit' => 'measure', 'max_height_mm' => 4000,
        'margins' => ['top' => 3, 'right' => 3, 'bottom' => 3, 'left' => 3],
    ];

    private const ATCUD = '<div class="flow" data-tpl-type="data-field" data-tpl-binding="documento.atcud" data-tpl-format="" data-tpl-legal="1" id="tpl-atcud">ATCUD: {{ documento.atcud }}</div>';

    private const TOTAL = '<div class="flow esc-row" data-tpl-type="text-row" id="tpl-total-row">'
        .'<div class="flow esc-cell esc-bold" data-tpl-type="static-text" data-tpl-cols="10" id="tpl-total-label">Total</div>'
        .'<div class="flow esc-cell esc-right esc-nowrap" data-tpl-type="data-field" data-tpl-binding="totais.total_a_pagar" data-tpl-format="money" data-tpl-legal="1" data-tpl-cols="0" id="tpl-total">{{ totais.total_a_pagar|money }}</div>'
        .'</div>';

    private const QR = '<img class="block block--code" data-tpl-type="code-slot" data-tpl-binding="documento.qr" data-tpl-legal="1" id="tpl-qr" alt="">';

    /** @return list<array<string, mixed>> */
    private function fields(): array
    {
        $bindings = (require __DIR__.'/../Fixtures/types/invoice.php')['invoice']['bindings'];

        return array_values(array_filter(
            $bindings,
            fn (array $binding): bool => in_array($binding['key'], ['documento.atcud', 'totais.total_a_pagar', 'documento.qr'], true),
        ));
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return (require __DIR__.'/../Fixtures/types/invoice.php')['invoice']['fixture'];
    }

    /** @return array{project_data: array<string, mixed>, html: string, css: string} */
    private function layout(string $body): array
    {
        return [
            'project_data' => [],
            'html'         => '<div class="page"><div class="zone zone--body" data-tpl-zone="body">'.$body.'</div></div>',
            'css'          => '#tpl-qr{width:30mm;height:30mm}',
        ];
    }

    /** @return array{bytes: string, preview: string, problems: list<array<string, mixed>>} */
    private function escPos(string $body, array $geometry = self::ROLL_80): array
    {
        return app(Documents::class)->escPos($this->layout($body), $this->data(), $geometry, '', $this->fields());
    }

    public function test_a_valid_roll_produces_bytes_preview_and_no_problems(): void
    {
        $result = $this->escPos(self::ATCUD.self::TOTAL.'<hr>'.self::QR);

        $this->assertSame([], $result['problems']);
        $this->assertStringStartsWith("\x1B@", $result['bytes']);
        $this->assertStringContainsString('JFT7-0000000001', $result['bytes']);
        $this->assertStringContainsString('<div class="tpl-receipt"', $result['preview']);
        $this->assertStringContainsString('ATCUD: JFT7-0000000001', $result['preview']);

        // The total, formatted as the field prints it (the test app's money format), whatever
        // space the format put inside.
        $text = preg_replace('/[\s\x{00A0}]+/u', ' ', html_entity_decode(strip_tags($result['preview']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $this->assertStringContainsString('Total 1 229 999,98 €', (string) $text);
        $this->assertStringContainsString('<img', $result['preview']);
    }

    public function test_missing_legal_value_is_e01(): void
    {
        $result = $this->escPos(self::ATCUD.'<hr>'.self::QR);

        $this->assertSame(['E-01'], array_column($result['problems'], 'code'));
        $this->assertSame('value_missing', $result['problems'][0]['key']);
        $this->assertSame('Total a pagar', $result['problems'][0]['subject']);
        $this->assertStringContainsString('Total a pagar', $result['problems'][0]['message']);
        // Nothing to print while there are problems; the preview still shows what there is.
        $this->assertSame('', $result['bytes']);
        $this->assertStringContainsString('JFT7-0000000001', $result['preview']);
    }

    public function test_wrong_qr_payload_is_e02(): void
    {
        // The slot is bound to another QR field, one the law does not ask for.
        $fields   = [...$this->fields(), ['key' => 'loja.qr', 'label' => 'QR da loja', 'type' => 'qr', 'mandatory' => false, 'legal' => false]];
        $data     = ['loja.qr' => 'https://loja.example/avaliar'] + $this->data();
        $layout   = $this->layout(self::ATCUD.self::TOTAL.str_replace('documento.qr', 'loja.qr', self::QR));
        $result   = app(Documents::class)->escPos($layout, $data, self::ROLL_80, '', $fields);

        $this->assertSame(['E-02'], array_column($result['problems'], 'code'));
        $this->assertSame('qr_mismatch', $result['problems'][0]['key']);
        $this->assertSame('Código QR', $result['problems'][0]['subject']);
        $this->assertSame('', $result['bytes']);
    }

    public function test_builder_problems_come_through(): void
    {
        // A word longer than the line is E-04 from the builder, reported as is.
        $result = $this->escPos(self::ATCUD.self::TOTAL.self::QR.'<div class="flow" id="tpl-long">'.str_repeat('X', 45).'</div>');

        $this->assertSame(['E-04'], array_column($result['problems'], 'code'));
        $this->assertSame('', $result['bytes']);
    }

    public function test_old_style_roll_returns_v21_without_translating(): void
    {
        $old = '<div class="block block--field" data-tpl-type="data-field" data-tpl-binding="documento.atcud" id="tpl-atcud" style="left:2mm;top:5mm">{{ documento.atcud }}</div>';

        $result = $this->escPos($old);

        $this->assertNotSame([], $result['problems']);
        $this->assertSame(['V-21'], array_values(array_unique(array_column($result['problems'], 'code'))));
        $this->assertSame('', $result['bytes']);
        $this->assertSame('', $result['preview']);
    }

    public function test_non_roll_geometry_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->escPos(self::ATCUD, ['paper_format' => 'A4', 'margins' => ['top' => 10, 'right' => 10, 'bottom' => 10, 'left' => 10]]);
    }
}
