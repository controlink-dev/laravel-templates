<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Exceptions\UnsafeTemplateException;
use Controlink\Templates\Formulas\AggregateCache;
use Controlink\Templates\Formulas\FormulaSet;
use Controlink\Templates\Output\Pdf\DocumentComposer;
use Controlink\Templates\Rendering\TemplateRenderer;
use Controlink\Templates\Templates;
use Controlink\Templates\Tests\Concerns\BuildsFixtureDataset;
use Controlink\Templates\Tests\Feature\Concerns\BuildsPdfLayouts;
use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Types\TemplateType;

/**
 * Formula fields in the printed document (CGE-37).
 *
 * The compositor evaluates every formula over the whole dataset before it decides the
 * sheets, and takes the formula text out of the markup before the guard and Twig ever
 * see it. These tests start from the invoice type's own initial layout — the shape the
 * editor exports — with formula blocks spliced in, and assert on the composed HTML.
 */
class FormulaRenderTest extends TestCase
{
    use BuildsPdfLayouts;
    use BuildsFixtureDataset;

    private function invoice(): TemplateType
    {
        return Templates::type('invoice');
    }

    /** A formula data field, as the editor exports it, at the top of a zone. */
    private function withField(string $html, string $zone, string $id, string $binding, string $source, string $format = ''): string
    {
        $expression = $format !== '' ? '{{ '.$binding.'|'.$format.' }}' : '{{ '.$binding.' }}';
        $field      = '<div class="block block--field" data-tpl-type="data-field"'
            .' data-tpl-binding="'.htmlspecialchars($binding, ENT_QUOTES).'"'
            .' data-tpl-format="'.$format.'"'
            .' data-tpl-formula="'.htmlspecialchars($source, ENT_QUOTES).'"'
            .' id="'.$id.'">'.$expression.'</div>';

        $opening = '<div class="zone zone--'.$zone.'" data-tpl-zone="'.$zone.'">';
        $this->assertStringContainsString($opening, $html);

        return str_replace($opening, $opening.$field, $html);
    }

    /** A formula column appended to the invoice's line table, header cell and row cell. */
    private function withRowColumn(string $html, string $key, string $source, string $format = ''): string
    {
        $html = preg_replace_callback(
            '/data-tpl-columns="([^"]*)"/',
            function (array $m) use ($key, $source, $format): string {
                $columns   = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
                $columns[] = ['key' => $key, 'label' => 'Formula', 'formula' => $source, 'format' => $format, 'formatArgs' => [], 'align' => 'right', 'width' => '20mm'];

                return 'data-tpl-columns="'.htmlspecialchars(json_encode($columns, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES).'"';
            },
            $html,
            1,
        );

        $value = $format !== '' ? '{{ linha.'.$key.'|'.$format.' }}' : '{{ linha.'.$key.' }}';
        $html  = str_replace('</tr></thead>', '<th>Formula</th></tr></thead>', $html);

        return str_replace('</tr>{% endfor %}', '<td class="t-formula">'.$value.'</td></tr>{% endfor %}', $html);
    }

    private function compose(string $html, ?array $dataset = null): string
    {
        $type = $this->invoice();

        return app(DocumentComposer::class)->compose($html, $type->initialLayout['css'], $type, $dataset ?? $this->fixtureDataset($type));
    }

    /** @return list<string> the printed content of every copy of a block, one per sheet it appears on */
    private function printed(string $document, string $id): array
    {
        preg_match_all('/id="'.preg_quote($id, '/').'"[^>]*>(.*?)<\/div>/s', $document, $matches);

        return $matches[1];
    }

    private function format(mixed $value, string $format): string
    {
        return htmlspecialchars(app(TemplateRenderer::class)->formatValue($value, $format), ENT_QUOTES | ENT_SUBSTITUTE);
    }

    /* -----------------------------------------------------------------------
     * Composition
     * -------------------------------------------------------------------- */

    public function test_single_field_formula_prints_formatted_result(): void
    {
        $html = $this->withField(
            $this->invoice()->initialLayout['html'],
            'header',
            'tpl-f-discount',
            'formula.disc01',
            'IF(totais.total_a_pagar > 100, totais.total_a_pagar * 0.1, 0)',
            'money',
        );

        $printed = $this->printed($this->compose($html), 'tpl-f-discount');

        $this->assertNotEmpty($printed);
        $this->assertSame($this->format(1229999.98 * 0.1, 'money'), $printed[0]);
        $this->assertNotSame('', $printed[0]);
    }

    /**
     * The id the editor draws: a letter, then letters and digits. Digits after the first
     * letter must print (`{{ formula.b3270w4p }}` is a plain Twig path); a digit-led id
     * is not a formula key at all and is refused at save (LayoutValidatorTest).
     */
    public function test_a_letter_led_formula_id_with_digits_renders(): void
    {
        $html = $this->withField(
            $this->invoice()->initialLayout['html'],
            'header',
            'tpl-f-letter',
            'formula.b3270w4p',
            'totais.total_a_pagar * 2',
            'money',
        );

        $this->assertSame([$this->format(1229999.98 * 2, 'money')], array_values(array_unique($this->printed($this->compose($html), 'tpl-f-letter'))));

        $this->assertTrue(FormulaSet::isFieldKey('formula.b3270w4p'));
        $this->assertFalse(FormulaSet::isFieldKey('formula.3b270w4p'));
        $this->assertTrue(FormulaSet::isColumnKey('formula_b3270w4p'));
        $this->assertFalse(FormulaSet::isColumnKey('formula_3b270w4p'));
    }

    /** Review Focus 4: a failed formula prints nothing, whatever the format. */
    public function test_failed_formula_prints_empty_with_money_format(): void
    {
        $html = $this->invoice()->initialLayout['html'];

        foreach (['money', 'number', 'date'] as $format) {
            $html = $this->withField($html, 'header', 'tpl-f-fail-'.$format, 'formula.fail'.$format, '1 / 0', $format);
        }

        $document = $this->compose($html);

        foreach (['money', 'number', 'date'] as $format) {
            $printed = $this->printed($document, 'tpl-f-fail-'.$format);

            $this->assertNotEmpty($printed, $format);
            $this->assertSame([''], array_values(array_unique($printed)), $format);
        }
    }

    /**
     * Review Focus 1: template syntax and guard trigger words inside a text literal
     * never reach the guard or Twig — the source is removed from the markup first.
     *
     * The literal itself is the formula's legitimate result (a missing reference
     * concatenates as empty), so it may print as text; what must not survive is the
     * source in an attribute.
     */
    public function test_formula_source_never_reaches_twig_or_guard(): void
    {
        $source = '"{{ x }} {% for %} javascript: onx=" & a.b';
        $html   = $this->withField($this->invoice()->initialLayout['html'], 'header', 'tpl-f-hostile', 'formula.host01', $source);

        // Saving the layout (V-07) reaches the same verdict as composing it.
        app(DocumentComposer::class)->assertSafe($html, $this->invoice()->initialLayout['css']);

        $document = $this->compose($html);

        $this->assertStringNotContainsString('data-tpl-formula', $document);
        $this->assertStringNotContainsString('&quot;{{ x }}', $document);
        $this->assertStringNotContainsString('&amp; a.b', $document);
        // Printed as plain text: Twig never parsed `{% for %}`, or it would have failed.
        $this->assertSame('{{ x }} {% for %} javascript: onx=', $this->printed($document, 'tpl-f-hostile')[0]);

        // When the literal is not the result, nothing of it is left at all.
        $html = $this->withField(
            $this->invoice()->initialLayout['html'],
            'header',
            'tpl-f-hidden',
            'formula.host02',
            'IF(FALSE, "{{ x }} {% for %} javascript: onx=", "ok")',
        );

        $document = $this->compose($html);

        $this->assertStringNotContainsString('{{ x }}', $document);
        $this->assertStringNotContainsString('javascript:', $document);
        $this->assertStringNotContainsString('data-tpl-formula', $document);
        $this->assertSame('ok', $this->printed($document, 'tpl-f-hidden')[0]);
    }

    /**
     * The same column, with hostile literal text in its source: the source sits in the
     * table's `data-tpl-columns` JSON, and must reach neither the guard nor Twig from there.
     */
    public function test_column_formula_source_never_reaches_twig_or_guard(): void
    {
        $source = '"{{ x }} {% for %} javascript: onx=" & linhas.codigo';
        $html   = $this->withRowColumn($this->invoice()->initialLayout['html'], 'formula_host01', $source);

        app(DocumentComposer::class)->assertSafe($html, $this->invoice()->initialLayout['css']);

        $dataset  = $this->fixtureDataset($this->invoice());
        $document = $this->compose($html, $dataset);

        $this->assertStringNotContainsString('&quot;formula&quot;', $document);
        $this->assertStringNotContainsString('\&quot;{{ x }}', $document);
        $this->assertStringNotContainsString('&amp; linhas.codigo', $document);

        // Each cell is the literal as plain text, escaped once: Twig never parsed `{% for %}`.
        preg_match_all('/<td class="t-formula">(.*?)<\/td>/s', $document, $cells);
        $this->assertSame(
            array_map(fn (array $row): string => htmlspecialchars('{{ x }} {% for %} javascript: onx='.$row['codigo'], ENT_QUOTES | ENT_SUBSTITUTE), $dataset['linhas']),
            $cells[1],
        );

        // When the literal is not the result, nothing of it is left at all.
        $html = $this->withRowColumn(
            $this->invoice()->initialLayout['html'],
            'formula_host02',
            'IF(FALSE, "{{ x }} {% for %} javascript: onx=", "ok")',
        );

        $document = $this->compose($html, $dataset);

        $this->assertStringNotContainsString('{{ x }}', $document);
        $this->assertStringNotContainsString('javascript:', $document);
        $this->assertStringNotContainsString('onx=', $document);
        $this->assertStringNotContainsString('&quot;formula&quot;', $document);

        preg_match_all('/<td class="t-formula">(.*?)<\/td>/s', $document, $cells);
        $this->assertNotSame([], $cells[1]);
        $this->assertSame(array_fill(0, count($dataset['linhas']), 'ok'), $cells[1]);
    }

    /**
     * Leading and trailing whitespace, and line breaks between tokens, print what the
     * validator and the live preview accepted: one normalisation for all three readers.
     */
    public function test_line_breaks_in_a_source_print_like_the_preview_shows(): void
    {
        foreach (["1+1\n" => '2', "\n 1 +\n 2\r\n" => '3', "1 +\n 2" => '3'] as $source => $expected) {
            $html = $this->withField($this->invoice()->initialLayout['html'], 'header', 'tpl-f-lines', 'formula.lines1', $source);
            $this->assertSame($expected, $this->printed($this->compose($html), 'tpl-f-lines')[0], json_encode($source));

            // The same, with the line break written as a character reference.
            $html = $this->withField($this->invoice()->initialLayout['html'], 'header', 'tpl-f-lines', 'formula.lines1', $source);
            $attribute = htmlspecialchars($source, ENT_QUOTES);
            $html      = str_replace($attribute, str_replace(["\r", "\n"], ['&#13;', '&#10;'], $attribute), $html);
            $this->assertSame($expected, $this->printed($this->compose($html), 'tpl-f-lines')[0], json_encode($source));
        }

        $dataset = $this->fixtureDataset($this->invoice());
        $html    = $this->withRowColumn($this->invoice()->initialLayout['html'], 'formula_lines1', "linhas.quantidade *\n 2\n");

        preg_match_all('/<td class="t-formula">(.*?)<\/td>/s', $this->compose($html, $dataset), $cells);

        $this->assertSame(array_map(fn (array $row): string => (string) ($row['quantidade'] * 2), $dataset['linhas']), $cells[1]);
    }

    /** Review Focus 5: the single field is on every sheet, and SUM is the whole collection there. */
    public function test_sum_is_whole_collection_on_every_sheet(): void
    {
        $dataset = $this->fixtureDataset($this->invoice());
        $html    = $this->withField($this->invoice()->initialLayout['html'], 'header', 'tpl-f-sum', 'formula.sum001', 'SUM(linhas.valor)', 'money');

        $document = $this->compose($html, $dataset);
        $sheets   = substr_count($document, '<div class="page">');

        $this->assertGreaterThan(1, $sheets, 'The fixture has to paginate for this to prove anything.');

        $expected = $this->format(array_sum(array_column($dataset['linhas'], 'valor')), 'money');

        $this->assertSame(array_fill(0, $sheets, $expected), $this->printed($document, 'tpl-f-sum'));
    }

    /** CGE-52/53 §9.9: a sentence with a field in the middle, formatted by the server. */
    public function test_invoice_sentence_with_field_in_the_middle(): void
    {
        $html = $this->withField(
            $this->invoice()->initialLayout['html'],
            'footer',
            'tpl-f-sentence',
            'formula.sent01',
            '"Os artigos foram colocados à disposição em " & TEXT(documento.data_bens, "date")',
        );

        $printed = $this->printed($this->compose($html), 'tpl-f-sentence');

        $this->assertNotEmpty($printed);
        $this->assertSame('Os artigos foram colocados à disposição em '.$this->format('2026-12-31', 'date'), $printed[0]);
    }

    /** A per-row column: each line its own product, including the lines of later sheets. */
    public function test_row_formula_column_prints_per_line(): void
    {
        $dataset = $this->fixtureDataset($this->invoice());
        $html    = $this->withRowColumn($this->invoice()->initialLayout['html'], 'formula_line01', 'linhas.quantidade * linhas.preco_unitario', 'money');

        $document = $this->compose($html, $dataset);

        $this->assertGreaterThan(1, substr_count($document, '<div class="page">'));
        $this->assertStringNotContainsString('&quot;formula&quot;', $document);

        preg_match_all('/<td class="t-formula">(.*?)<\/td>/s', $document, $cells);

        $expected = array_map(
            fn (array $row): string => $this->format($row['quantidade'] * $row['preco_unitario'], 'money'),
            $dataset['linhas'],
        );

        $this->assertSame($expected, $cells[1]);
    }

    /**
     * A catalogue column turned into a formula keeps its own key and prints the result
     * under its `formulaKey`: the source goes, the `formulaKey` stays (the cell prints it),
     * and every line of every sheet shows the result, not the raw value.
     */
    public function test_catalogue_column_with_formula_prints_result_on_every_sheet(): void
    {
        $dataset = $this->fixtureDataset($this->invoice());
        $html    = preg_replace_callback(
            '/data-tpl-columns="([^"]*)"/',
            function (array $m): string {
                $columns = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);

                foreach ($columns as &$column) {
                    if ($column['key'] === 'descricao') {
                        $column += ['formula' => 'UPPER(linhas.descricao)', 'formulaKey' => 'formula_dsc12345'];
                    }
                }

                return 'data-tpl-columns="'.htmlspecialchars(json_encode($columns, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES).'"';
            },
            $this->invoice()->initialLayout['html'],
            1,
        );
        $html = str_replace('<td style="text-align:left">{{ linha.descricao }}</td>', '<td class="t-dsc">{{ linha.formula_dsc12345 }}</td>', $html, $count);
        $this->assertSame(1, $count);

        $set = FormulaSet::fromHtml($html);
        $this->assertSame([['collection' => 'linhas', 'key' => 'formula_dsc12345', 'source' => 'UPPER(linhas.descricao)']], $set->columns());
        $this->assertStringContainsString('&quot;formulaKey&quot;:&quot;formula_dsc12345&quot;', $set->strippedHtml());
        $this->assertStringNotContainsString('&quot;formula&quot;', $set->strippedHtml());

        $document = $this->compose($html, $dataset);

        $this->assertGreaterThan(1, substr_count($document, '<div class="page">'));

        preg_match_all('/<td class="t-dsc">(.*?)<\/td>/s', $document, $cells);

        $this->assertSame(
            array_map(fn (array $row): string => htmlspecialchars(mb_strtoupper($row['descricao']), ENT_QUOTES | ENT_SUBSTITUTE), $dataset['linhas']),
            $cells[1],
        );
    }

    /**
     * The result goes under the `formulaKey`, never over the row's own value: a second,
     * plain column on the same field prints the raw value on every sheet, and another
     * formula reading `linhas.descricao` sees the raw value too, not the first one's result.
     */
    public function test_a_catalogue_column_in_formula_leaves_the_raw_value_to_other_columns(): void
    {
        $dataset = $this->fixtureDataset($this->invoice());
        $html    = preg_replace_callback(
            '/data-tpl-columns="([^"]*)"/',
            function (array $m): string {
                $columns = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);

                foreach ($columns as &$column) {
                    if ($column['key'] === 'descricao') {
                        $column += ['formula' => 'UPPER(linhas.descricao)', 'formulaKey' => 'formula_dsc12345'];
                    }
                }
                unset($column);

                $columns[] = ['key' => 'descricao', 'label' => 'Raw', 'format' => '', 'formatArgs' => [], 'align' => 'left', 'width' => ''];
                $columns[] = ['key' => 'formula_raw00001', 'formulaKey' => 'formula_raw00001', 'label' => 'Again', 'formula' => 'linhas.descricao & "!"', 'format' => '', 'formatArgs' => [], 'align' => 'left', 'width' => ''];

                return 'data-tpl-columns="'.htmlspecialchars(json_encode($columns, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES).'"';
            },
            $this->invoice()->initialLayout['html'],
            1,
        );
        $html = str_replace('<td style="text-align:left">{{ linha.descricao }}</td>', '<td class="t-dsc">{{ linha.formula_dsc12345 }}</td>', $html, $count);
        $this->assertSame(1, $count);
        $html = str_replace('</tr></thead>', '<th>Raw</th><th>Again</th></tr></thead>', $html);
        $html = str_replace('</tr>{% endfor %}', '<td class="t-raw">{{ linha.descricao }}</td><td class="t-again">{{ linha.formula_raw00001 }}</td></tr>{% endfor %}', $html);

        $document = $this->compose($html, $dataset);

        $this->assertGreaterThan(1, substr_count($document, '<div class="page">'));

        $escape = fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE);
        $cells  = function (string $class) use ($document): array {
            preg_match_all('/<td class="'.$class.'">(.*?)<\/td>/s', $document, $matches);

            return $matches[1];
        };

        // The fixture's descriptions are not capitals already: the result and the raw value differ.
        $this->assertNotSame($cells('t-dsc'), $cells('t-raw'));
        $this->assertSame(array_map(fn (array $row): string => $escape(mb_strtoupper($row['descricao'])), $dataset['linhas']), $cells('t-dsc'));
        $this->assertSame(array_map(fn (array $row): string => $escape($row['descricao']), $dataset['linhas']), $cells('t-raw'));
        $this->assertSame(array_map(fn (array $row): string => $escape($row['descricao'].'!'), $dataset['linhas']), $cells('t-again'));
    }

    /* -----------------------------------------------------------------------
     * FormulaSet
     * -------------------------------------------------------------------- */

    /** A layout without formulas composes exactly as it did before formulas existed. */
    public function test_a_layout_without_formulas_is_untouched(): void
    {
        $type    = $this->invoice();
        $dataset = $this->fixtureDataset($type);

        foreach ([$type->initialLayout['html'], $this->validPdfLayout($type)['html']] as $html) {
            $set = FormulaSet::fromHtml($html);

            $this->assertSame($html, $set->strippedHtml());
            $this->assertSame($dataset, $set->inject($dataset));
            $this->assertSame([], $set->fields());
            $this->assertSame([], $set->columns());
        }
    }

    public function test_it_reads_fields_and_columns_and_strips_only_the_sources(): void
    {
        $html = $this->withField($this->invoice()->initialLayout['html'], 'header', 'tpl-a', 'formula.abc123', 'totais.total_a_pagar * 2', 'money');
        $html = $this->withField($html, 'header', 'tpl-b', 'formula.BAD', '1 + 1');
        $html = $this->withRowColumn($html, 'formula_col123', 'linhas.valor / 2');

        $set = FormulaSet::fromHtml($html);

        $this->assertSame(['formula.abc123' => 'totais.total_a_pagar * 2'], $set->fields());
        $this->assertSame([['collection' => 'linhas', 'key' => 'formula_col123', 'source' => 'linhas.valor / 2']], $set->columns());

        $stripped = $set->strippedHtml();

        // Every source goes, even one whose key is not a valid formula key.
        $this->assertStringNotContainsString('data-tpl-formula', $stripped);
        $this->assertStringNotContainsString('&quot;formula&quot;', $stripped);
        $this->assertStringContainsString('data-tpl-binding="formula.BAD"', $stripped);
        // The column keeps everything else it declared.
        $this->assertStringContainsString('&quot;key&quot;:&quot;formula_col123&quot;', $stripped);
        $this->assertStringContainsString('&quot;key&quot;:&quot;codigo&quot;', $stripped);

        // Nothing but those two attributes changed.
        $outside = fn (string $h): string => preg_replace(['/ data-tpl-formula="[^"]*"/', '/ data-tpl-columns="[^"]*"/'], '', $h);
        $this->assertSame($outside($html), $outside($stripped));
    }

    /** An unescaped `>` inside a value, or single quotes, still come out whole. */
    public function test_stripping_survives_raw_angle_brackets_and_single_quotes(): void
    {
        $html = '<div class="zone zone--header" data-tpl-zone="header">'
            .'<div data-tpl-type="data-field" data-tpl-binding="formula.gt1234" data-tpl-formula="IF(a.b > 1, &quot;>{{&quot;, 0)" id="a">{{ formula.gt1234 }}</div>'
            .'<div data-tpl-type="data-field" data-tpl-binding="formula.sq1234" data-tpl-formula=\'"x" & "y"\' id="b">{{ formula.sq1234 }}</div>'
            .'</div>';

        $set = FormulaSet::fromHtml($html);

        $this->assertSame(['formula.gt1234' => 'IF(a.b > 1, ">{{", 0)', 'formula.sq1234' => '"x" & "y"'], $set->fields());
        $this->assertSame(
            '<div class="zone zone--header" data-tpl-zone="header">'
            .'<div data-tpl-type="data-field" data-tpl-binding="formula.gt1234" id="a">{{ formula.gt1234 }}</div>'
            .'<div data-tpl-type="data-field" data-tpl-binding="formula.sq1234" id="b">{{ formula.sq1234 }}</div>'
            .'</div>',
            $set->strippedHtml(),
        );
        $this->assertSame('xy', $set->inject([])['formula.sq1234']);
    }

    /** The word "formula" with no source in it goes through strip() and comes out unchanged. */
    public function test_the_word_formula_without_sources_is_byte_identical(): void
    {
        $type = $this->invoice();
        $html = str_replace(
            '<div class="zone zone--header" data-tpl-zone="header">',
            '<div class="zone zone--header" data-tpl-zone="header">'
                .'<div class="block block--formula-note" data-note=\'formula\' title="formula > 1">A formula: {{ documento.numero }}</div>',
            $type->initialLayout['html'],
        );

        $this->assertStringContainsString('formula', $html);
        $this->assertSame($html, FormulaSet::fromHtml($html)->strippedHtml());

        $dataset = $this->fixtureDataset($type);
        $this->assertSame($dataset, FormulaSet::fromHtml($html)->inject($dataset));
    }

    /**
     * A regex engine failure (backtrack limit) must refuse the document, never turn
     * the markup into '' and print a blank one.
     */
    public function test_a_regex_failure_refuses_instead_of_blanking_the_markup(): void
    {
        $html = $this->withField($this->invoice()->initialLayout['html'], 'header', 'tpl-a', 'formula.abc123', '1 + 1');

        $jit       = ini_get('pcre.jit');
        $backtrack = ini_get('pcre.backtrack_limit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1');

        try {
            FormulaSet::fromHtml($html);
            $this->fail('A PCRE failure must throw.');
        } catch (UnsafeTemplateException) {
            $this->addToAssertionCount(1);
        } finally {
            ini_set('pcre.jit', (string) $jit);
            ini_set('pcre.backtrack_limit', (string) $backtrack);
        }

        // And the validator reports it as V-07 rather than crashing.
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1');

        try {
            $this->expectException(UnsafeTemplateException::class);
            app(DocumentComposer::class)->assertSafe($html, '');
        } finally {
            ini_set('pcre.jit', (string) $jit);
            ini_set('pcre.backtrack_limit', (string) $backtrack);
        }
    }

    /**
     * A per-row column that divides by a SUM: the SUM is computed once per pass, not once
     * per line (it was O(n²) in the number of lines), and a failing one is not retried.
     */
    public function test_inject_computes_each_aggregate_once_per_pass(): void
    {
        $html = $this->withField($this->invoice()->initialLayout['html'], 'header', 'tpl-a', 'formula.tot123', 'SUM(linhas.valor) + COUNT(linhas.valor)');
        $html = $this->withRowColumn($html, 'formula_col123', 'ROUND(linhas.valor / SUM(linhas.valor) * 100, 2) & "/" & COUNT(linhas.valor)');

        $rows       = array_map(fn (int $i): array => ['valor' => $i % 7 + 1], range(1, 5000));
        $aggregates = new AggregateCache;
        $out        = FormulaSet::fromHtml($html)->inject(['linhas' => $rows], $aggregates);

        $sum = array_sum(array_column($rows, 'valor'));

        $this->assertSame(2, $aggregates->computed(), 'one SUM(linhas.valor) and one COUNT(linhas), for 5000 rows');
        $this->assertSame($sum + 5000, $out['formula.tot123']);
        $this->assertSame(round(1 / $sum * 100, 2).'/5000', $out['linhas'][6]['formula_col123']);

        // A SUM that fails (a non-numeric cell) fails on every row, and is computed once.
        $html       = $this->withRowColumn($this->invoice()->initialLayout['html'], 'formula_bad123', 'linhas.valor + SUM(linhas.nome)');
        $aggregates = new AggregateCache;
        $out        = FormulaSet::fromHtml($html)->inject(['linhas' => array_fill(0, 500, ['valor' => 1, 'nome' => 'x'])], $aggregates);

        $this->assertSame(1, $aggregates->computed());
        $this->assertSame(array_fill(0, 500, ''), array_column($out['linhas'], 'formula_bad123'));
    }

    public function test_inject_evaluates_over_the_whole_dataset_and_skips_missing_collections(): void
    {
        $html = $this->withField($this->invoice()->initialLayout['html'], 'header', 'tpl-a', 'formula.cnt123', 'COUNT(linhas.valor)');
        $html = $this->withRowColumn($html, 'formula_col123', 'linhas.valor - SUM(linhas.valor)');

        $dataset = ['linhas' => [['valor' => 1], ['valor' => 2], 'not-a-row']];
        $set     = FormulaSet::fromHtml($html);
        $out     = $set->inject($dataset);

        $this->assertSame(3, $out['formula.cnt123']);
        $this->assertSame(-2, $out['linhas'][0]['formula_col123']);
        $this->assertSame(-1, $out['linhas'][1]['formula_col123']);
        $this->assertSame('not-a-row', $out['linhas'][2]);

        // A table whose collection the dataset lacks is left alone (and COUNT of it fails to '').
        $this->assertSame(['formula.cnt123' => ''], $set->inject([]));
    }
}
