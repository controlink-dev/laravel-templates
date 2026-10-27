<?php

namespace Controlink\Templates\Tests\Unit\EscPos;

use Controlink\Templates\Output\EscPos\Receipt;
use Controlink\Templates\Output\EscPos\ReceiptBuilder;
use Controlink\Templates\Output\Pdf\DocumentComposer;
use Controlink\Templates\Support\PageGeometry;
use Controlink\Templates\Templates;
use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Types\TemplateType;

/**
 * The translator's first half (CGE-97): the composed roll HTML — the same the PDF engine
 * gets — laid on the printer's character grid. The fixtures are written the way the
 * composer leaves them: a `.page` with its three zones, Twig already run.
 */
class ReceiptBuilderTest extends TestCase
{
    private const ROLL = [
        'medium' => 'roll', 'fit' => 'measure', 'max_height_mm' => 4000,
        'margins' => ['top' => 3, 'right' => 3, 'bottom' => 3, 'left' => 3],
    ];

    private function geometry(int $widthMm = 80): PageGeometry
    {
        return PageGeometry::fromType(['width_mm' => $widthMm] + self::ROLL);
    }

    /** @param array<string, mixed> $dataset */
    private function build(string $body, int $widthMm = 80, array $dataset = [], array $fields = []): Receipt
    {
        $html = '<!doctype html><html><head><meta charset="utf-8"><style>.page{x:y}</style></head><body>'
            .'<div class="page"><div class="zone zone--header"></div>'
            .'<div class="zone zone--body">'.$body.'</div>'
            .'<div class="zone zone--footer"></div></div></body></html>';

        return (new ReceiptBuilder)->build($html, $dataset, $this->geometry($widthMm), $fields);
    }

    /** @return list<string> the text of each line block */
    private function lines(Receipt $receipt): array
    {
        return array_values(array_map(
            fn (array $block): string => $block['text'],
            array_filter($receipt->blocks, fn (array $block): bool => $block['kind'] === 'line'),
        ));
    }

    public function test_blocks_are_laid_on_the_grid(): void
    {
        $receipt = $this->build(
            '<div class="flow esc-center" id="tpl-title">FATURA</div>'
            .'<div class="flow esc-right" id="tpl-number">FT A/1</div>'
            .'<hr>'
            .'<div class="flow esc-gap-2" id="tpl-thanks">Obrigado</div>'
        );

        $this->assertSame(40, $receipt->columns);
        $this->assertSame([
            str_repeat(' ', 17).'FATURA'.str_repeat(' ', 17),
            str_repeat(' ', 34).'FT A/1',
            str_repeat('-', 40),
            str_repeat(' ', 40),
            str_repeat(' ', 40),
            'Obrigado'.str_repeat(' ', 32),
        ], $this->lines($receipt));
        $this->assertSame([], $receipt->problems);
        $this->assertSame(['tpl-title'], $receipt->blocks[0]['sources']);

        // The 58 mm grid is 32 wide.
        $this->assertSame([str_repeat('-', 32)], $this->lines($this->build('<hr>', 58)));
    }

    public function test_double_size_line_has_half_the_columns(): void
    {
        $receipt = $this->build('<div class="flow esc-double esc-center" id="tpl-total">TOTAL</div>');
        $line    = $receipt->blocks[0];

        $this->assertSame(20, strlen($line['text']));
        $this->assertTrue($line['double']);
        $this->assertSame(str_repeat(' ', 7).'TOTAL'.str_repeat(' ', 8), $line['text']);

        $this->assertSame(16, strlen($this->build('<div class="flow esc-double">TOTAL</div>', 58)->blocks[0]['text']));
        $this->assertFalse($this->build('<div class="flow">x</div>')->blocks[0]['double']);

        // A gap's `em` is the block's own: on a double block it is a double blank line.
        $gap = $this->build('<div class="flow esc-double esc-gap-1">TOTAL</div>')->blocks[0];
        $this->assertSame([str_repeat(' ', 20), true], [$gap['text'], $gap['double']]);
    }

    public function test_text_row_cells_take_their_columns(): void
    {
        $receipt = $this->build(
            '<div class="flow esc-row" data-tpl-type="text-row" id="tpl-total-row">'
            .'<div class="flow esc-cell" data-tpl-type="static-text" data-tpl-cols="10" id="tpl-total-label">Total</div>'
            .'<div class="flow esc-cell esc-right" data-tpl-type="data-field" data-tpl-binding="totals.total_due" data-tpl-cols="0" id="tpl-total">1.234,56</div>'
            .'</div>'
        );

        $this->assertSame(['Total'.str_repeat(' ', 27).'1.234,56'], $this->lines($receipt));
        $this->assertSame(['tpl-total-label', 'tpl-total'], $receipt->blocks[0]['sources']);

        // Two cells with no width share the line: 20 and 20.
        $shared = $this->build(
            '<div class="flow esc-row"><div class="flow esc-cell">NIF</div><div class="flow esc-cell esc-right">123456789</div></div>'
        );
        $this->assertSame(['NIF'.str_repeat(' ', 28).'123456789'], $this->lines($shared));
    }

    public function test_a_cell_with_text_and_no_columns_left_is_e04(): void
    {
        // The sized cells take all 40 columns: the auto cell has none, and its short word
        // would silently vanish.
        $receipt = $this->build(
            '<div class="flow esc-row" id="tpl-row"><div class="flow esc-cell" data-tpl-cols="20">A</div>'
            .'<div class="flow esc-cell" data-tpl-cols="20">B</div>'
            .'<div class="flow esc-cell" data-tpl-cols="0" id="tpl-lost">Hi</div></div>'
        );

        $this->assertSame([['E-04', 'no_room', 'tpl-lost']], array_map(
            fn (array $p): array => [$p['code'], $p['key'], $p['component_id']],
            $receipt->problems,
        ));

        // An empty auto cell with no room is fine: nothing is lost.
        $empty = $this->build(
            '<div class="flow esc-row"><div class="flow esc-cell" data-tpl-cols="40">A</div><div class="flow esc-cell"></div></div>'
        );
        $this->assertSame([], $empty->problems);

        // Same in the line table: a 40ch column leaves the auto one nothing.
        $columns = htmlspecialchars((string) json_encode([
            ['key' => 'designation', 'label' => 'Artigo', 'width' => '', 'format' => ''],
            ['key' => 'total', 'label' => 'Total', 'width' => '40ch', 'format' => 'money'],
        ]), ENT_QUOTES);
        $table = $this->build(
            '<div class="block block--lines" data-tpl-type="line-table" data-tpl-columns="'.$columns.'" id="tpl-lines">'
            .'<table class="lines"><tbody><tr><td>Parafuso</td><td>1,00</td></tr></tbody></table></div>'
        );
        $this->assertSame([['E-04', 'no_room', 'tpl-lines']], array_map(
            fn (array $p): array => [$p['code'], $p['key'], $p['component_id']],
            $table->problems,
        ));
        $this->assertStringContainsString('Artigo', $table->problems[0]['message']);
    }

    public function test_multi_row_line_table_prints_each_segment_as_a_line(): void
    {
        $columns = htmlspecialchars((string) json_encode([
            ['key' => 'code', 'label' => 'Código', 'width' => '', 'format' => ''],
            ['key' => 'description', 'label' => 'Designação', 'width' => '', 'format' => ''],
            ['key' => 'unit_price', 'label' => 'Preço', 'width' => '10ch', 'format' => 'money', 'break' => true, 'align' => 'right'],
            ['key' => 'quantity', 'label' => 'Qtd', 'width' => '8ch', 'format' => 'number', 'align' => 'right'],
            ['key' => 'vat_rate', 'label' => 'IVA', 'width' => '6ch', 'format' => 'percent', 'align' => 'right'],
            ['key' => 'amount', 'label' => 'Total', 'width' => '', 'format' => 'money', 'align' => 'right'],
        ]), ENT_QUOTES);

        $item = fn (string $code, string $description, array $values): string => '<tr>'
            .'<td class="lines__text" style="text-align:left">'.$code.'</td>'
            .'<td colspan="3" class="lines__text" style="text-align:left">'.$description.'</td></tr><tr>'
            .implode('', array_map(fn (string $v): string => '<td style="text-align:right">'.$v.'</td>', $values))
            .'</tr>';

        $receipt = $this->build(
            '<div class="block block--lines" data-tpl-type="line-table" data-tpl-binding="lines" data-tpl-columns="'.$columns.'" id="tpl-lines">'
            .'<table class="lines"><colgroup><col style="width:10ch"><col style="width:8ch"><col style="width:6ch"><col></colgroup>'
            .'<thead><tr><th style="text-align:left">Código</th><th colspan="3" style="text-align:left">Designação</th></tr>'
            .'<tr><th style="text-align:right">Preço</th><th style="text-align:right">Qtd</th><th style="text-align:right">IVA</th><th style="text-align:right">Total</th></tr></thead>'
            ."<tbody>\n"
            .$item('A1', 'Parafuso', ['1,50', '2', '23%', '3,00'])."\n"
            .$item('B22', 'Porca', ['0,25', '100', '23%', '25,00'])
            .'</tbody></table></div>'
        );

        // The grid is the longest segment: 10 + 8 + 6 + the rest (16). The first segment's
        // description spans the last three grid columns: 8 + 6 + 16 = 30.
        $this->assertSame([
            'Código    Designação                    ',
            '     Preço     Qtd   IVA           Total',
            'A1        Parafuso                      ',
            '      1,50       2   23%            3,00',
            'B22       Porca                         ',
            '      0,25     100   23%           25,00',
        ], $this->lines($receipt));
        $this->assertSame([], $receipt->problems);

        // The header is bold, as the browser draws a `th`.
        $this->assertSame([[0, 6], [10, 20]], $receipt->blocks[0]['bold']);
    }

    public function test_long_text_wraps_on_words_and_values_never_split(): void
    {
        $address = 'Rua Engenheiro Duarte Pacheco número 123, Bloco B, 2.º Esquerdo Lisboa';
        $this->assertSame(70, mb_strlen($address));

        $receipt = $this->build('<div class="flow" data-tpl-type="data-field" data-tpl-binding="customer.address" id="tpl-address">'.$address.'</div>');

        $this->assertSame([
            'Rua Engenheiro Duarte Pacheco número    ',
            '123, Bloco B, 2.º Esquerdo Lisboa       ',
        ], $this->lines($receipt));
        $this->assertSame([], $receipt->problems);

        // A legal value never breaks: 45 characters in a 30-character cell is E-04.
        $atcud = 'JFJ5KZ2Q-12345678 JFJ5KZ2Q-123456789 JFJ5KZ2Q';
        $this->assertSame(45, strlen($atcud));
        $row = '<div class="flow esc-row"><div class="flow esc-cell" data-tpl-cols="10">ATCUD</div>'
            .'<div class="flow esc-cell esc-right" data-tpl-type="data-field" data-tpl-binding="document.atcud" id="tpl-atcud">'.$atcud.'</div></div>';

        $legal = $this->build($row, 80, [], [['key' => 'document.atcud', 'legal' => true]]);
        $this->assertCount(1, $legal->problems);
        $this->assertSame('E-04', $legal->problems[0]['code']);
        $this->assertSame('tpl-atcud', $legal->problems[0]['component_id']);
        $this->assertStringContainsString('document.atcud', $legal->problems[0]['message']);

        // The same text, not legal, breaks on its words like the browser does.
        $plain = $this->build($row);
        $this->assertSame([], $plain->problems);
        // (A hyphen before a digit is no break opportunity, as in the browser.)
        $this->assertSame([
            'ATCUD'.str_repeat(' ', 18).'JFJ5KZ2Q-12345678',
            str_repeat(' ', 13).'JFJ5KZ2Q-123456789 JFJ5KZ2Q',
        ], $this->lines($plain));

        // The editor's legal mark says it with no field list: three arguments are safe.
        $marked = $this->build(str_replace('id="tpl-atcud"', 'id="tpl-atcud" data-tpl-legal="1"', $row));
        $this->assertSame(['E-04', 'tpl-atcud'], [$marked->problems[0]['code'] ?? null, $marked->problems[0]['component_id'] ?? null]);
        $this->assertStringContainsString('document.atcud', $marked->problems[0]['message']);

        // `esc-nowrap` says the same without a field list.
        $nowrap = $this->build(str_replace('esc-cell esc-right"', 'esc-cell esc-right esc-nowrap"', $row));
        $this->assertSame('E-04', $nowrap->problems[0]['code'] ?? null);
    }

    public function test_qr_slot_becomes_a_qr_block_with_the_dataset_payload(): void
    {
        $payload = 'A:509999999*B:123456789*C:PT*D:FS*E:N*F:20261027*G:FS A/1*H:JFJ5KZ2Q-1*I1:PT';

        $receipt = $this->build(
            '<img class="block block--code" data-tpl-type="code-slot" data-tpl-binding="document.qr" id="tpl-qr" alt="" src="data:image/svg+xml;base64,PHN2Zy8+">',
            80,
            ['document.qr' => $payload],
        );

        $this->assertSame([['kind' => 'qr', 'payload' => $payload, 'binding' => 'document.qr']], $receipt->blocks);
        $this->assertSame([], $receipt->problems);
        $this->assertSame('[QR]', $receipt->text());
    }

    public function test_bold_ranges_follow_esc_bold_cells(): void
    {
        $receipt = $this->build(
            '<div class="flow esc-row"><div class="flow esc-cell esc-bold" data-tpl-cols="10">Total</div>'
            .'<div class="flow esc-cell esc-right esc-bold">1.234,56</div></div>'
            .'<div class="flow">Sem <b>negrito</b> aqui</div>'
            .'<div class="flow esc-bold esc-center">FIM</div>'
        );

        $this->assertSame([[0, 5], [32, 40]], $receipt->blocks[0]['bold']);
        $this->assertSame([[4, 11]], $receipt->blocks[1]['bold']);
        $this->assertSame([[18, 21]], $receipt->blocks[2]['bold']);
    }

    public function test_text_joins_lines_and_marks_codes_and_images(): void
    {
        $png = base64_encode((string) $this->png());

        $receipt = $this->build(
            '<img class="block block--image" data-tpl-type="image" id="tpl-logo" src="data:image/png;base64,'.$png.'">'
            .'<div class="flow" id="tpl-a">A</div>'
            .'<img class="block block--image" data-tpl-type="image" id="tpl-gif" src="data:image/gif;base64,R0lGODlhAQABAAAAACw=">'
        );

        $this->assertSame("[IMG]\nA".str_repeat(' ', 39), $receipt->text());
        $this->assertSame('image', $receipt->blocks[0]['kind']);
        $this->assertSame('E-04', $receipt->problems[0]['code']);
        $this->assertSame('tpl-gif', $receipt->problems[0]['component_id']);
    }

    public function test_a_character_the_printer_lacks_is_e03_naming_the_field(): void
    {
        $receipt = $this->build(
            '<div class="flow" data-tpl-type="data-field" data-tpl-binding="customer.name" id="tpl-name">“Wang” 名 Łukasz</div>'
        );

        $this->assertCount(1, $receipt->problems);
        $this->assertSame('E-03', $receipt->problems[0]['code']);
        $this->assertSame('tpl-name', $receipt->problems[0]['component_id']);
        $this->assertStringContainsString('customer.name', $receipt->problems[0]['message']);
        $this->assertStringContainsString('名', $receipt->problems[0]['message']);
        // The rest is transliterated one for one, before the layout.
        $this->assertSame('"Wang" ? Lukasz', rtrim($this->lines($receipt)[0]));
    }

    public function test_the_composers_own_output_translates(): void
    {
        // The receipt the vocabulary fixture describes, through the real composer (Twig,
        // the code slot's SVG, the fonts and stylesheets in the head).
        $case = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/roll-vocabulary-cases.json'), true)['cases'][0];
        $html = str_replace('document.qr', 'documento.qr', '<body>'.$case['html'].'</body>');
        $base = Templates::type('invoice');
        $pdf  = ['width_mm' => 80] + self::ROLL;
        $type = new TemplateType(
            $base->code, $base->label, $base->output, $base->seeded, $base->layoutRevision,
            $base->bindings, $base->nodes, $base->fixture, $base->fixtureRepeats,
            $base->initialLayout, $pdf, [],
        );
        $dataset = [
            'totals.total_due' => '1.234,56',
            'documento.qr'     => 'A:509999999*B:999999990*C:PT',
            'lines'            => [['designation' => 'Chapa de aço galvanizado com duas faces', 'total' => '1.234,56']],
        ];

        $composed = app(DocumentComposer::class)->compose($html, $case['css'], $type, $dataset);
        $receipt  = (new ReceiptBuilder)->build($composed, $dataset, PageGeometry::fromType($pdf));

        $this->assertSame([], $receipt->problems);
        $this->assertSame(implode("\n", [
            str_repeat(' ', 20),                        // esc-gap-1 of a double block: a double blank line
            str_repeat(' ', 14).'FATURA',               // double, right: 20 columns
            'Total'.str_repeat(' ', 27).'1.234,56',
            str_repeat('-', 40),
            'Artigo'.str_repeat(' ', 29).'Total',       // 28 (the rest) + 12ch
            'Chapa de aço galvanizado com    1.234,56', // the description wraps on words
            'duas faces'.str_repeat(' ', 30),
            '[QR]',
        ]), $receipt->text());
        $this->assertSame('A:509999999*B:999999990*C:PT', $receipt->blocks[7]['payload']);
    }

    private function png(): string|false
    {
        $image = imagecreatetruecolor(8, 2);
        imagefill($image, 0, 0, imagecolorallocate($image, 0, 0, 0));
        ob_start();
        imagepng($image);

        return ob_get_clean();
    }
}
