<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Codes\CodeImageGenerator;
use Controlink\Templates\Data\ColumnDescriptor;
use Controlink\Templates\Data\DocumentDataset;
use Controlink\Templates\Data\DocumentGraph;
use Controlink\Templates\Data\FixtureDataset;
use Controlink\Templates\Data\FixtureValue;
use Controlink\Templates\Exceptions\UnsafeTemplateException;
use Controlink\Templates\Output\Pdf\DocumentComposer;
use Controlink\Templates\Output\Pdf\PageStructure;
use Controlink\Templates\Rendering\TemplateRenderer;
use Controlink\Templates\Templates;
use Controlink\Templates\Tests\Feature\Concerns\BuildsPdfLayouts;
use Controlink\Templates\Tests\Fixtures\Models\Party;
use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Types\TemplateType;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Pagination, the line table, the code slots and the Twig sandbox.
 *
 * The engine does not paginate this module, and that is the single most consequential
 * decision `Output\Pdf` makes. Its pagination was measured and it fails twice over: when
 * the last page carries only a few lines Chromium stops emitting the repeated header
 * group, so an invoice that ends with three lines prints its last page with no ATCUD;
 * and padding the body to fix that pushes out a blank page when the last page is nearly
 * full. Neither shows up in extracted text, so a render-only validation would have
 * passed both. The numbers are in the host's docs/NOTAS-FIDELIDADE.md.
 *
 * So the compositor decides the sheets, and these tests are about that: the arithmetic
 * that replaces the engine's judgement, and the fact that every sheet carries the bands.
 */
class PdfRenderTest extends TestCase
{
    use BuildsPdfLayouts;

    private const DOCUMENT_TYPE = 'invoice';

    /* -----------------------------------------------------------------------
     * Pagination
     * -------------------------------------------------------------------- */

    /**
     * Rows per page is arithmetic, not measurement — which is the whole reason the row
     * height is fixed.
     */
    public function test_rows_per_page_comes_from_the_band_heights_and_the_row_height(): void
    {
        $type = Templates::type(self::DOCUMENT_TYPE);

        $bodyHeight = 297
            - $type->pdf['margins']['top'] - $type->pdf['margins']['bottom']
            - $type->initialLayout['zones']['header_height']
            - $type->initialLayout['zones']['footer_height'];

        // The band the totals occupy at the bottom of the body is not the table's to
        // use: without it the last sheet's final rows print over the total to pay.
        $bodyHeight -= $type->pdf['body_reserve_mm'];

        $rowHeight = $type->pdf['line_row_height_mm'];
        $expected  = (int) floor(($bodyHeight - $rowHeight) / $rowHeight);

        $this->assertSame($expected, app(PageStructure::class)->rowsPerPage($type));

        // A table the user pushed further down the body fits fewer rows.
        $this->assertLessThan(
            $expected,
            app(PageStructure::class)->rowsPerPage($type, 20.0),
        );
    }

    /** Nothing in a form without a line table can grow, so it is one sheet. */
    public function test_a_form_with_no_line_table_is_a_single_page(): void
    {
        $type   = Templates::type(self::DOCUMENT_TYPE);
        $layout = $this->pdfLayoutWithout($type, 'linhas');

        $structure = app(PageStructure::class)->build(
            $this->bodyOf($layout['html']),
            $type,
            app(FixtureDataset::class)->for($type),
        );

        $this->assertCount(1, $structure['pages']);
    }

    /**
     * The collection is split across sheets, and each sheet's dataset carries only its
     * own slice — which is what lets `{% for %}` stay the one tag the sandbox allows.
     */
    public function test_the_lines_are_chunked_one_slice_per_page(): void
    {
        $type    = Templates::type(self::DOCUMENT_TYPE);
        $dataset = app(FixtureDataset::class)->for($type);
        $layout  = $this->validPdfLayout($type);

        $structure = app(PageStructure::class)->build($this->bodyOf($layout['html']), $type, $dataset);

        $perPage = app(PageStructure::class)->rowsPerPage($type);
        $total   = count($dataset['linhas']);

        $this->assertCount((int) ceil($total / $perPage), $structure['pages']);

        $seen = 0;

        foreach ($structure['pages'] as $index => $page) {
            $slice = $page['dataset']['linhas'];

            $this->assertLessThanOrEqual($perPage, count($slice));
            $this->assertSame($index + 1, $page['dataset']['pagina.numero']);
            $this->assertSame(count($structure['pages']), $page['dataset']['pagina.total']);

            $seen += count($slice);
        }

        // Every line appears exactly once across the sheets: none lost, none repeated.
        $this->assertSame($total, $seen);
    }

    /**
     * Every sheet carries the header and the footer. This is the criterion the engine
     * failed, and the reason the compositor took the job over.
     */
    public function test_every_page_carries_the_header_and_the_footer(): void
    {
        $type   = Templates::type(self::DOCUMENT_TYPE);
        $layout = $this->validPdfLayout($type);

        $structure = app(PageStructure::class)->build(
            $this->bodyOf($layout['html']),
            $type,
            app(FixtureDataset::class)->for($type),
        );

        $this->assertGreaterThan(2, count($structure['pages']), 'The fixture has to paginate for this to prove anything.');

        foreach ($structure['pages'] as $index => $page) {
            $this->assertStringContainsString('zone--header', $page['template'], "page {$index}");
            $this->assertStringContainsString('zone--footer', $page['template'], "page {$index}");
            $this->assertStringContainsString('documento.atcud', $page['template'], "page {$index}");
        }
    }

    /**
     * A totals block belongs on the last sheet, not on all of them. The choice is made
     * in PHP because the sandbox allows no `if` — conditional logic in templates is
     * excluded from the module (spec 2, 7.2).
     */
    public function test_body_blocks_other_than_the_table_appear_only_on_the_last_page(): void
    {
        $type   = Templates::type(self::DOCUMENT_TYPE);
        $layout = $this->pdfLayoutPlus($type, [
            'binding' => 'totais.total_iliquido',
            'format'  => 'money',
            'zone'    => 'body',
            'id'      => 'tpl-totals',
            'style'   => ['position' => 'absolute', 'right' => '0', 'bottom' => '0', 'font-size' => '10pt'],
        ]);

        $structure = app(PageStructure::class)->build(
            $this->bodyOf($layout['html']),
            $type,
            app(FixtureDataset::class)->for($type),
        );

        $pages = $structure['pages'];
        $last  = count($pages) - 1;

        foreach ($pages as $index => $page) {
            $carriesTotals = str_contains($page['template'], 'tpl-totals');

            $index === $last
                ? $this->assertTrue($carriesTotals, 'the last page must carry the totals')
                : $this->assertFalse($carriesTotals, "page {$index} must not carry the totals");
        }
    }

    /** One `.page` per sheet in the composed document, and a break between them. */
    public function test_the_composed_document_has_one_page_element_per_sheet(): void
    {
        $type     = Templates::type(self::DOCUMENT_TYPE);
        $layout   = $this->validPdfLayout($type);
        $dataset  = app(FixtureDataset::class)->for($type);
        $document = app(DocumentComposer::class)->compose($layout['html'], $layout['css'], $type, $dataset);

        $sheets = app(PageStructure::class)->build($this->bodyOf($layout['html']), $type, $dataset);

        $this->assertSame(
            count($sheets['pages']),
            substr_count($document, '<div class="page">'),
        );
        $this->assertStringContainsString('break-after:page', $document);

        // Nothing left for the engine to interpret, and no template expression escaped.
        $this->assertStringNotContainsString('{{', $document);
        $this->assertStringNotContainsString('{%', $document);
    }

    /**
     * Every weight the layouts use has a font file behind it.
     *
     * A weight with no file is synthesised by the engine, and its glyph box is nothing
     * the layout controls: measured on the host, a synthesised bold reported a
     * noticeably taller and offset box than the real face. That is enough to make a
     * legibility rule fail on a font, not on a layout — and it means the printed weight
     * is a smear rather than the typeface.
     */
    public function test_every_weight_the_layouts_use_has_a_face_of_its_own(): void
    {
        $type   = Templates::type(self::DOCUMENT_TYPE);
        $layout = $this->validPdfLayout($type);

        $document = app(DocumentComposer::class)->compose(
            $layout['html'],
            $layout['css'],
            $type,
            app(FixtureDataset::class)->for($type),
        );

        // The weights the template's own initial layout asks for.
        preg_match_all('/font-weight:\s*(\d+)/', $type->initialLayout['css'], $matches);
        $used = array_unique(array_merge(['400'], $matches[1]));

        foreach ($used as $weight) {
            $this->assertMatchesRegularExpression(
                '/@font-face\{[^}]*font-weight:\s*'.$weight.'\b/',
                $document,
                "The composed document declares no face for font-weight {$weight}.",
            );
        }

        // And each of them carries a real file, not a reference the engine cannot fetch.
        $this->assertSame(
            count($used),
            preg_match_all('/src:url\(data:font/', $document),
            'A declared face has no embedded font file.',
        );
    }

    /**
     * `print.css` ships with a placeholder family (`CGestSans`, the original host's
     * own). `pageVariables()` overrides it with whatever `templates.fonts` actually
     * declares, so a host that never touches that config still gets a document whose
     * `.page` asks for the very family `fontFaces()` embedded — never an unpredictable
     * Chromium fallback.
     */
    public function test_the_page_declares_the_configured_font_family(): void
    {
        $type    = Templates::type(self::DOCUMENT_TYPE);
        $layout  = $this->validPdfLayout($type);
        $dataset = app(FixtureDataset::class)->for($type);

        $document = app(DocumentComposer::class)->compose($layout['html'], $layout['css'], $type, $dataset);

        $this->assertStringContainsString('font-family:"LiberationSans",sans-serif', $document);

        $regularFace = config('templates.fonts.LiberationSans.weights.400');

        config(['templates.fonts' => [
            'Other' => ['weights' => [400 => $regularFace]],
        ]]);

        $document = app(DocumentComposer::class)->compose($layout['html'], $layout['css'], $type, $dataset);

        $this->assertStringContainsString('font-family:"Other"', $document);
    }

    /* -----------------------------------------------------------------------
     * The CSS safety guard
     * -------------------------------------------------------------------- */

    /**
     * `compose()` wraps `$css` in `<style>…</style>` with nothing escaping it. A
     * literal `</style>` in the payload closes that element early and hands
     * everything after it to the HTML parser as ordinary markup — the very
     * `<script>` the HTML guard exists to refuse, smuggled in through the CSS side
     * instead.
     */
    public function test_css_that_closes_its_enclosing_style_tag_is_refused(): void
    {
        $this->expectException(UnsafeTemplateException::class);

        $this->assertSafe('<div class="page"></div>', '</style><script>alert(1)</script><style>');
    }

    /** `@import` reaches outside the document exactly like an external `url()` would. */
    public function test_css_import_is_refused(): void
    {
        $this->expectException(UnsafeTemplateException::class);

        $this->assertSafe('<div class="page"></div>', '@import "http://evil.example/x.css";');
    }

    /** An ordinary embedded asset still passes both new checks. */
    public function test_a_normal_data_uri_url_still_passes_the_guard(): void
    {
        $this->assertSafe('<div class="page"></div>', '.page{background:url(data:image/png;base64,AAAA)}');

        $this->addToAssertionCount(1);
    }

    private function assertSafe(string $html, string $css): void
    {
        app(DocumentComposer::class)->assertSafe($html, $css);
    }

    /* -----------------------------------------------------------------------
     * The Twig sandbox (spec 7.2)
     * -------------------------------------------------------------------- */

    /** The four formats in the closed list, and nothing else. */
    public function test_the_allowed_filters_format_values(): void
    {
        $renderer = app(TemplateRenderer::class);

        $this->assertSame('31/12/2026', $renderer->render('{{ d|date }}', ['d' => '2026-12-31']));
        $this->assertSame('FATURA', $renderer->render('{{ d|upper }}', ['d' => 'Fatura']));
        $this->assertStringContainsString('999', $renderer->render('{{ d|number }}', ['d' => 1999.5]));
    }

    /**
     * A template can only have come from the editor, which offers no way to write
     * template syntax — so a policy violation is evidence of tampering, not a user
     * mistake, and it is refused rather than reported as validation.
     *
     * @param  string  $template  something the editor could never produce
     */
    #[DataProvider('forbiddenTemplates')]
    public function test_the_sandbox_refuses_everything_outside_the_policy(string $template): void
    {
        $this->expectException(RuntimeException::class);

        app(TemplateRenderer::class)->render($template, ['x' => 'y', 'linhas' => []]);
    }

    /** @return array<string, array{0: string}> */
    public static function forbiddenTemplates(): array
    {
        return [
            // Conditional logic is excluded from the module by section 2, and allowing
            // the tag would undo the whole of that exclusion.
            'the if tag'                => ['{% if x %}a{% endif %}'],
            'the include tag'           => ['{% include "other" %}'],
            'the set tag'               => ['{% set a = 1 %}'],
            // Not in `templates.formats`: `upper` is, deliberately, so this proves the
            // policy rejects a filter it does not know rather than every filter Twig has.
            'a filter outside the list' => ['{{ x|trim }}'],
            'any function'              => ['{{ dump() }}'],
            // A binding the dataset does not carry is a system error, not a blank space
            // (spec 7.3).
            'an unknown binding' => ['{{ nothing.here }}'],
        ];
    }

    /** A value carrying markup reaches the page as text. */
    public function test_values_are_escaped(): void
    {
        $this->assertSame(
            'Alfa &amp; &lt;b&gt;Beta&lt;/b&gt;',
            app(TemplateRenderer::class)->render('{{ x }}', ['x' => 'Alfa & <b>Beta</b>']),
        );
    }

    /** The line loop is the one thing `for` exists for. */
    public function test_the_line_loop_renders_each_row(): void
    {
        $rendered = app(TemplateRenderer::class)->render(
            '{% for linha in linhas %}[{{ linha.codigo }}]{% endfor %}',
            ['linhas' => [['codigo' => 'A'], ['codigo' => 'B']]],
        );

        $this->assertSame('[A][B]', $rendered);
    }

    /* -----------------------------------------------------------------------
     * Code slots (spec 7.1 step 5)
     * -------------------------------------------------------------------- */

    public function test_a_qr_slot_is_generated_and_embedded(): void
    {
        $html = '<div><img data-tpl-type="code-slot" data-tpl-binding="documento.qr" style="width:30mm;height:30mm" alt=""></div>';

        $filled = app(CodeImageGenerator::class)->inject(
            $html,
            ['documento.qr' => 'A:999999999*B:888888888'],
            ['documento.qr' => ['key' => 'documento.qr', 'type' => 'qr']],
        );

        $this->assertStringContainsString('src="data:image/svg+xml;base64,', $filled);

        // Embedded rather than linked: the engine has no network, so a referenced code
        // would never reach the document.
        $this->assertStringNotContainsString('src="http', $filled);
    }

    /** A slot bound to a field the catalogue does not declare is a configuration error. */
    public function test_a_slot_bound_to_an_unknown_field_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        app(CodeImageGenerator::class)->inject(
            '<img data-tpl-type="code-slot" data-tpl-binding="documento.invented" alt="">',
            [],
            [],
        );
    }

    /**
     * Barcodes are declared unsupported out loud.
     *
     * No template binds one, so there is nothing to generate and no reason to carry a
     * dependency that renders nothing — but a silent blank image would be far worse
     * than a refusal.
     */
    public function test_a_barcode_slot_says_it_is_not_implemented(): void
    {
        $this->expectExceptionMessage('Barcode slots are not implemented');

        app(CodeImageGenerator::class)->generate('barcode', 'x', 30.0);
    }

    /* -----------------------------------------------------------------------
     * The fixture (spec 7.3)
     * -------------------------------------------------------------------- */

    /**
     * The fixture has to paginate, or the acceptance criterion for pagination could
     * pass on a document that never breaks a page.
     */
    public function test_the_fixture_spans_more_than_two_pages(): void
    {
        $type    = Templates::type(self::DOCUMENT_TYPE);
        $dataset = app(FixtureDataset::class)->for($type);
        $pages   = (int) ceil(count($dataset['linhas']) / app(PageStructure::class)->rowsPerPage($type));

        $this->assertGreaterThanOrEqual(3, $pages);
    }

    /** Values stay raw: the filters are what format them at render time. */
    public function test_the_fixture_keeps_values_raw(): void
    {
        $dataset = app(FixtureDataset::class)->for(Templates::type(self::DOCUMENT_TYPE));

        $this->assertIsFloat($dataset['totais.total_a_pagar']);
        $this->assertSame('2026-12-31', $dataset['documento.data_emissao']);
    }

    /** No two repeated lines are identical, or a misplaced page break would be invisible. */
    public function test_repeated_fixture_lines_are_distinguishable(): void
    {
        $dataset = app(FixtureDataset::class)->for(Templates::type(self::DOCUMENT_TYPE));

        $codes = array_column($dataset['linhas'], 'codigo');

        $this->assertSame(count($codes), count(array_unique($codes)));
    }

    /**
     * A text field's fixture fills the column, because a truncation rule only means
     * anything if the sample is the longest value the column accepts.
     */
    public function test_a_generated_text_fixture_fills_the_column(): void
    {
        $value = FixtureValue::for(new ColumnDescriptor(name: 'name', bindingType: 'text', length: 40));

        $this->assertSame(40, mb_strlen($value));
    }

    /**
     * A decimal's fixture is nines to the column's scale: the widest number the column can
     * hold, which is what a box has to survive.
     */
    public function test_a_generated_decimal_fixture_is_nines_to_the_scale(): void
    {
        $value = FixtureValue::for(new ColumnDescriptor(name: 'amount', bindingType: 'number', scale: 3));

        $this->assertMatchesRegularExpression('/^9+\.999$/', $value);

        $date = FixtureValue::for(new ColumnDescriptor(name: 'issued_on', bindingType: 'date'));

        $this->assertSame('2026-12-31', $date);

        $boolean = FixtureValue::for(new ColumnDescriptor(name: 'flag', bindingType: 'text', isBoolean: true));

        $this->assertSame(__('templates::editor.fixtures.yes'), $boolean);
    }

    /**
     * A driver that reports no length falls back to a declared default rather than to an
     * empty string — measured: SQLite reports `varchar`, not `varchar(150)`. An empty
     * fixture would pass every legibility rule by having nothing to break.
     */
    public function test_a_column_with_no_reported_length_still_gets_a_fixture(): void
    {
        $value = FixtureValue::for(new ColumnDescriptor(name: 'name', bindingType: 'text', length: null));

        $this->assertSame(FixtureValue::DEFAULT_TEXT_LENGTH, mb_strlen($value));
        $this->assertNotSame('', $value);
    }

    /**
     * The declared fixture wins, always. Only a key nobody declared gets a value
     * generated from its column (spec 4.5) — exercised here against `Party`, the
     * package's own minimal test model, since the `invoice` fixture type's nodes are
     * otherwise declared with no model at all.
     */
    public function test_the_declared_fixture_is_never_overwritten_by_a_generated_one(): void
    {
        Schema::create('parties', function ($table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('phone', 20)->nullable();
        });

        try {
            $config = Templates::type(self::DOCUMENT_TYPE)->toArray();
            $config['nodes']['adquirente'] = array_merge($config['nodes']['adquirente'], ['model' => Party::class]);
            $type = TemplateType::fromConfig(self::DOCUMENT_TYPE, $config);

            $declared = $type->fixture;
            $dataset  = app(FixtureDataset::class)->for($type);

            foreach ($declared as $key => $value) {
                if (is_array($value)) {
                    continue;
                }

                $this->assertSame($value, $dataset[$key], "The generated fixture overwrote the declared value of {$key}.");
            }

            // And a dynamic key that nobody declared now HAS a value.
            $this->assertArrayHasKey('adquirente.phone', $dataset);
            $this->assertNotSame('', (string) $dataset['adquirente.phone']);
        } finally {
            Schema::dropIfExists('parties');
            DocumentGraph::flush();
        }
    }

    /**
     * `data_get($root, $node['via'])` is the whole of the traversal: whatever object
     * graph a host hands in, an owned node's fields are read straight off it, nested
     * objects included. Decryption itself is the host model's own concern — a cast or a
     * trait on ITS model — and is out of the package's reach to exercise without an
     * `App\`-coupled fixture, which `src/` may never import; what this proves is that
     * the traversal itself does not flatten or lose a nested path.
     */
    public function test_the_dataset_resolves_the_paths_and_decrypts_on_the_way(): void
    {
        $type = $this->typeWithOwnedAdquirente();

        $entity = (object) [
            'nome'      => 'Cliente Cifrado, Lda',
            'nif'       => '999999990',
            'morada'    => 'Rua de Testes, 1',
            'addresses' => [(object) ['city' => 'Porto']],
        ];
        $root = (object) ['entity' => $entity];

        $dataset = app(DocumentDataset::class)->for($root, $type);

        $this->assertSame('Cliente Cifrado, Lda', $dataset['adquirente.nome']);
        $this->assertSame('999999990', $dataset['adquirente.nif']);
        $this->assertSame('Porto', $dataset['adquirente_morada.city']);
    }

    /**
     * A path that runs into a null halfway down yields an empty value and never an
     * exception: an entity with no address is an ordinary state, and the document prints
     * the field blank. The key still has to be PRESENT — `TemplateRenderer` renders with
     * `strict_variables => true`, so an absent key is a render failure, not a blank
     * space (spec 7.3). `?? ''` would pass identically whether the key is present and
     * empty or missing outright, so this asserts presence first, on both a node reached
     * by a single-segment `via` (`adquirente`) and one reached by a longer path
     * (`adquirente_morada`, `via` = `entity.addresses.0`).
     */
    public function test_a_path_that_hits_a_null_yields_an_empty_value(): void
    {
        $type = $this->typeWithOwnedAdquirente();

        $root = (object) ['entity' => null];

        $dataset = app(DocumentDataset::class)->for($root, $type);

        $this->assertArrayHasKey('adquirente.nome', $dataset);
        $this->assertSame('', (string) $dataset['adquirente.nome']);

        $this->assertArrayHasKey('adquirente_morada.city', $dataset);
        $this->assertSame('', (string) $dataset['adquirente_morada.city']);
    }

    /**
     * The `invoice` fixture type with its `adquirente` and `adquirente_morada` nodes
     * given a `via` path so `DocumentDataset` treats them as owned by the document
     * (`isOwned()`), plus a declared binding for the one field `adquirente_morada` did
     * not otherwise carry. Neither node is given a `model`, so `DocumentGraph` never
     * tries to introspect a schema for them — only the declared fields resolve.
     */
    private function typeWithOwnedAdquirente(): TemplateType
    {
        $config = Templates::type(self::DOCUMENT_TYPE)->toArray();

        $config['nodes']['adquirente']['via'] = 'entity';
        $config['nodes']['adquirente_morada'] = array_merge(
            $config['nodes']['adquirente_morada'],
            ['via' => 'entity.addresses.0'],
        );
        $config['bindings'][] = ['key' => 'adquirente_morada.city', 'label' => 'City', 'type' => 'text'];

        return TemplateType::fromConfig(self::DOCUMENT_TYPE, $config);
    }

    /** The editor's export wraps the document in `<body>`; the compositor unwraps it. */
    private function bodyOf(string $html): string
    {
        return (string) preg_replace('/^<body[^>]*>|<\/body>$/', '', trim($html));
    }
}
