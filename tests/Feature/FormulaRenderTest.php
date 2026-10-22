<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Exceptions\UnsafeTemplateException;
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
