<?php

namespace Controlink\Templates\Tests\Unit\EscPos;

use Controlink\Templates\Output\EscPos\EscPosValidator;
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

        // 45 characters with spaces in a 30-character cell break on their words, legal or
        // not: a legal text without a format wraps as the PDF wraps it.
        $atcud = 'JFJ5KZ2Q-12345678 JFJ5KZ2Q-123456789 JFJ5KZ2Q';
        $this->assertSame(45, strlen($atcud));
        $row = '<div class="flow esc-row"><div class="flow esc-cell" data-tpl-cols="10">ATCUD</div>'
            .'<div class="flow esc-cell esc-right" data-tpl-type="data-field" data-tpl-binding="document.atcud" id="tpl-atcud">'.$atcud.'</div></div>';
        // (A hyphen after a letter, before a digit, is a break opportunity, as in the
        // browser: `Q-|1`, measured in RollRenderTest::HYPHEN_CASES.)
        $wrapped = [
            'ATCUD'.str_repeat(' ', 8).'JFJ5KZ2Q-12345678 JFJ5KZ2Q-',
            str_repeat(' ', 22).'123456789 JFJ5KZ2Q',
        ];

        $plain = $this->build($row);
        $this->assertSame([], $plain->problems);
        $this->assertSame($wrapped, $this->lines($plain));

        // Legal by the field list or by the editor's mark: the same lines.
        $legal = $this->build($row, 80, [], [['key' => 'document.atcud', 'legal' => true]]);
        $this->assertSame([], $legal->problems);
        $this->assertSame($wrapped, $this->lines($legal));

        $marked = $this->build(str_replace('id="tpl-atcud"', 'id="tpl-atcud" data-tpl-legal="1"', $row));
        $this->assertSame([], $marked->problems);
        $this->assertSame($wrapped, $this->lines($marked));

        // `esc-nowrap` keeps it whole: it does not fit, so it is E-04, never a cut.
        $nowrap = $this->build(str_replace('esc-cell esc-right"', 'esc-cell esc-right esc-nowrap"', $row));
        $this->assertSame(['E-04', 'tpl-atcud'], [$nowrap->problems[0]['code'] ?? null, $nowrap->problems[0]['component_id'] ?? null]);
        $this->assertStringContainsString('document.atcud', $nowrap->problems[0]['message']);
    }

    public function test_a_long_legal_name_wraps_on_words(): void
    {
        $name = 'Sociedade Comercial de Equipamentos Industriais e Agricolas de Leiria, Unip.';
        $this->assertSame(76, strlen($name));

        $receipt = $this->build('<div class="flow" data-tpl-type="data-field" data-tpl-binding="customer.name" data-tpl-format="" data-tpl-legal="1" id="tpl-customer-name">'.$name.'</div>');

        $this->assertSame([], $receipt->problems);
        $this->assertSame([
            'Sociedade Comercial de Equipamentos     ',
            'Industriais e Agricolas de Leiria, Unip.',
        ], $this->lines($receipt));
        $this->assertSame(['tpl-customer-name'], $receipt->blocks[1]['sources']);

        // Both lines are one block's: E-01 reads them as one text.
        $this->assertSame($receipt->blocks[0]['group'] ?? null, $receipt->blocks[1]['group'] ?? 'none');

        // Two texts under one id'd container share their source, never their group.
        $two = $this->build('<div id="tpl-box"><div class="flow">Ana</div><div class="flow">Maria</div></div>');
        $this->assertSame([['tpl-box'], ['tpl-box']], array_column($two->blocks, 'sources'));
        $this->assertNotSame($two->blocks[0]['group'] ?? null, $two->blocks[1]['group'] ?? null);
    }

    public function test_a_legal_text_breaks_at_a_hyphen_like_the_pdf(): void
    {
        $name  = 'Empresa Comercial de Construções Metalo-Mecânicas, Lda';
        $field = ['key' => 'customer.name', 'label' => 'Nome', 'type' => 'text', 'mandatory' => true, 'legal' => true];

        $receipt = $this->build('<div class="flow" data-tpl-type="data-field" data-tpl-binding="customer.name" data-tpl-format="" data-tpl-legal="1" id="tpl-customer-name">'.$name.'</div>');

        // The browser's line: «…Metalo-» fills the 40 columns, «Mecânicas, Lda» goes on.
        $this->assertSame([], $receipt->problems);
        $this->assertSame([
            'Empresa Comercial de Construções Metalo-',
            'Mecânicas, Lda'.str_repeat(' ', 26),
        ], $this->lines($receipt));
        $this->assertSame([' ', ''], array_column($receipt->blocks, 'joint'));

        // E-01 reads it back as «Metalo-Mecânicas», not «Metalo- Mecânicas».
        $this->assertSame([], app(EscPosValidator::class)->validate($receipt, [$field], ['customer.name' => $name]));
    }

    public function test_a_mandatory_value_split_at_a_hyphen_is_found(): void
    {
        // Not legal: an ordinary text, broken at its hyphen as the browser breaks it.
        $name  = 'Ana Maria Albuquerque Vasconcelos Silva-Santos';
        $field = ['key' => 'cliente.nome', 'label' => 'Nome', 'type' => 'text', 'mandatory' => true, 'legal' => false];

        $receipt = $this->build('<div class="flow" data-tpl-type="data-field" data-tpl-binding="cliente.nome" id="tpl-nome">'.$name.'</div>');

        $this->assertSame([
            'Ana Maria Albuquerque Vasconcelos Silva-',
            'Santos'.str_repeat(' ', 34),
        ], $this->lines($receipt));
        $this->assertSame([], app(EscPosValidator::class)->validate($receipt, [$field], ['cliente.nome' => $name]));
    }

    public function test_a_postal_code_breaks_at_its_hyphen_like_the_pdf(): void
    {
        // The seeded issuer address: «4200-» reaches the 40th column, and Chromium breaks
        // there (RollRenderTest::test_roll_pdf_breaks_at_hyphens_where_escpos_does).
        $address = 'Rua Doutor António Bernardino de Almeida, n.º 1234, 5.º Esquerdo, 4200-072 Porto';
        $field   = ['key' => 'issuer.address', 'label' => 'Sede do emitente', 'type' => 'text', 'mandatory' => true, 'legal' => true];

        $receipt = $this->build('<div class="flow" data-tpl-type="data-field" data-tpl-binding="issuer.address" data-tpl-format="" data-tpl-legal="1" id="tpl-issuer-address">'.$address.'</div>');

        $this->assertSame([], $receipt->problems);
        $this->assertSame([
            'Rua Doutor António Bernardino de        ',
            'Almeida, n.º 1234, 5.º Esquerdo, 4200-  ',
            '072 Porto'.str_repeat(' ', 31),
        ], $this->lines($receipt));
        $this->assertSame([' ', ' ', ''], array_column($receipt->blocks, 'joint'));

        // E-01 reads it back whole: «4200-072», not «4200- 072».
        $this->assertSame([], app(EscPosValidator::class)->validate($receipt, [$field], ['issuer.address' => $address]));
    }

    /**
     * Where Chromium breaks after a hyphen, measured through Gotenberg (the cases of
     * RollRenderTest::HYPHEN_CASES, each with its hyphen at the line's edge): before a
     * letter, always; before an ASCII digit, only after an ASCII letter or digit (a minus
     * sign stays on its number); before a full stop, never.
     */
    public function test_hyphen_breaks_follow_the_browser(): void
    {
        $x = fn (int $n): string => str_repeat('x', $n);

        foreach ([
            [$x(33).' 4200-072 q', [$x(33).' 4200-', '072 q']],
            [$x(33).' ABC-12345 q', [$x(33).' ABC-', '12345 q']],
            [$x(33).' 123-abcde q', [$x(33).' 123-', 'abcde q']],
            [$x(33).' ab-12,00 q', [$x(33).' ab-', '12,00 q']],
            [$x(33).' ab-(123 q', [$x(33).' ab-', '(123 q']],
            [$x(33).' )-abcde q', [$x(33).' )-', 'abcde q']],
            [$x(35).' -abcdefg q', [$x(35).' -', 'abcdefg q']],
            [$x(33).' ã-12345 q', [$x(33), 'ã-12345 q']],
            [$x(33).' )-12345 q', [$x(33), ')-12345 q']],
            [$x(33).' .-12345 q', [$x(33), '.-12345 q']],
            [$x(35).' ab-.1234 q', [$x(35), 'ab-.1234 q']],
            [$x(36).' -12,00 q', [$x(36), '-12,00 q']],
        ] as [$text, $expected]) {
            $receipt = $this->build('<div class="flow" id="tpl-text">'.$text.'</div>');

            $this->assertSame($expected, array_map('rtrim', $this->lines($receipt)), $text);
        }

        // A value that must stay whole stays whole: a formatted one and an esc-nowrap one
        // move to the next line instead of breaking at their hyphen.
        foreach ([
            '<span data-tpl-type="data-field" data-tpl-binding="v" data-tpl-format="money" id="tpl-v">4200-072</span>',
            '<span class="esc-nowrap" id="tpl-v">4200-072</span>',
        ] as $value) {
            $receipt = $this->build('<div class="flow" id="tpl-text">'.$x(33).' '.$value.' q</div>');

            $this->assertSame([$x(33), '4200-072 q'], array_map('rtrim', $this->lines($receipt)), $value);
        }
    }

    public function test_a_legal_token_wider_than_the_line_is_e04(): void
    {
        $token = str_repeat('ABCDEFGHI', 5);
        $this->assertSame(45, strlen($token));

        foreach ([
            $token,
            // A hyphen is no break inside a legal text: a token stays a token.
            'ABCDEFGHIJKLMNOPQRSTU-vwxyzabcdefghijklmnopq',
        ] as $value) {
            $receipt = $this->build('<div class="flow" data-tpl-type="data-field" data-tpl-binding="document.atcud" data-tpl-format="" data-tpl-legal="1" id="tpl-atcud">'.$value.'</div>');

            $this->assertSame(['E-04'], array_column($receipt->problems, 'code'), $value);
            $this->assertSame('value_too_wide', $receipt->problems[0]['key']);
            $this->assertSame('tpl-atcud', $receipt->problems[0]['component_id']);
            $this->assertSame('document.atcud', $receipt->problems[0]['subject']);
        }
    }

    public function test_a_formatted_legal_value_never_wraps(): void
    {
        // A formatted value stays whole even with spaces in it: legal or not, and inside
        // a legal text that may itself wrap.
        $value = '1 234 567 890 123 456 789 012 345 678,90 EUR';
        $this->assertSame(44, strlen($value));
        $field = '<span data-tpl-type="data-field" data-tpl-binding="totals.total" data-tpl-format="money" id="tpl-total">'.$value.'</span>';

        foreach ([
            str_replace('<span ', '<div class="flow" data-tpl-legal="1" ', str_replace('</span>', '</div>', $field)),
            str_replace('<span ', '<div class="flow" ', str_replace('</span>', '</div>', $field)),
            '<div class="flow" data-tpl-legal="1" id="tpl-outer">Total '.$field.'</div>',
        ] as $html) {
            $receipt = $this->build($html);

            $this->assertSame(['E-04'], array_column($receipt->problems, 'code'), $html);
            $this->assertSame('value_too_wide', $receipt->problems[0]['key']);
            $this->assertSame('tpl-total', $receipt->problems[0]['component_id']);
            $this->assertSame('totals.total', $receipt->problems[0]['subject']);
        }
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

    public function test_a_decomposed_letter_prints_as_one_character(): void
    {
        // "c" + combining cedilla is one ç on the receipt, as it is one glyph in the PDF:
        // no E-03, and the line keeps its 40 characters.
        $receipt = $this->build(
            "<div class=\"flow\" data-tpl-type=\"data-field\" data-tpl-binding=\"customer.name\" id=\"tpl-name\">Grac\u{0327}a</div>"
        );

        $this->assertSame([], $receipt->problems);
        $this->assertSame('Graça'.str_repeat(' ', 35), $this->lines($receipt)[0]);
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
