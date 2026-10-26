<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Contracts\BindingResolver;
use Controlink\Templates\Output\Pdf\Colour;
use Controlink\Templates\Output\Pdf\LayoutDocument;
use Controlink\Templates\Output\Pdf\LayoutValidator;
use Controlink\Templates\Templates;
use Controlink\Templates\Tests\Concerns\BuildsLayouts;
use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Toolkit\Documents;
use Controlink\Templates\Types\TemplateType;

/**
 * The static validation, layer 1 (spec 8.1).
 *
 * One case per rule. Most of these never touch the database: the validator is a pure
 * traversal of the document. Non-declared bindings go through the `BindingResolver`
 * contract (declared-only by default); schema-derived resolution is the host's concern.
 *
 * Deliberately absent: truncation has no static rule at all — it depends on font
 * metrics and resolved widths, and R-04 measures it exactly on the rendered PDF.
 */
class LayoutValidatorTest extends TestCase
{
    use BuildsLayouts;

    private const TYPE_CODE = 'invoice';

    /* -----------------------------------------------------------------------
     * The baseline
     * -------------------------------------------------------------------- */

    /**
     * Without this one, every rule below could be passing because the validator finds
     * nothing at all.
     */
    public function test_a_correct_layout_produces_no_problems(): void
    {
        $this->assertSame([], $this->problems($this->validLayout()));
    }

    /* -----------------------------------------------------------------------
     * Structure
     * -------------------------------------------------------------------- */

    public function test_v01_rejects_a_binding_outside_the_catalogue(): void
    {
        $layout = $this->layoutWith(
            'emitente.nif',
            fn (array $block): array => [...$block, 'binding' => 'emitente.invented'],
        );

        $this->assertHasProblem('V-01', $layout);
    }

    public function test_v01_rejects_a_format_outside_the_closed_list(): void
    {
        $layout = $this->layoutWith(
            'emitente.nif',
            fn (array $block): array => [...$block, 'format' => 'roman'],
        );

        $this->assertHasProblem('V-01', $layout);
    }

    public function test_v01_rejects_unknown_format_argument(): void
    {
        $this->assertHasProblem('V-01', $this->layoutWithDate('Y', "{{ %s|date('Y') }}"));
    }

    public function test_v01_rejects_expression_not_matching_attributes(): void
    {
        $this->assertHasProblem('V-01', $this->layoutWithDate('dd/MM/yyyy', "{{ %s|date('yyyy-MM-dd') }}"));
    }

    public function test_v01_accepts_matching_expression(): void
    {
        $this->assertSame([], $this->problems($this->layoutWithDate('dd/MM/yyyy', "{{ %s|date('dd/MM/yyyy') }}")));
    }

    public function test_v01_rejects_a_field_missing_from_the_export(): void
    {
        $layout         = $this->layoutWithDate('dd/MM/yyyy', "{{ %s|date('dd/MM/yyyy') }}");
        $layout['html'] = preg_replace('/id="tpl-test-(\d+)"/', 'id="renamed-$1"', $layout['html']);

        $this->assertHasProblem('V-01', $layout);
    }

    public function test_v01_compares_every_element_sharing_an_id(): void
    {
        $layout = $this->layoutWithDate('dd/MM/yyyy', "{{ %s|date('dd/MM/yyyy') }}");

        // A second element with the same id, printing another pattern.
        preg_match('/<div id="([^"]+)" class="block block--field" data-tpl-type="data-field">\{\{ (\S+)\|date\(\'dd\/MM\/yyyy\'\) \}\}<\/div>/', $layout['html'], $match);
        $layout['html'] = str_replace('</body>', '<div id="'.$match[1].'">{{ '.$match[2]."|date('yyyy-MM-dd') }}</div></body>", $layout['html']);

        $this->assertHasProblem('V-01', $layout);
    }

    public function test_v01_rejects_a_column_argument_outside_the_list(): void
    {
        $this->assertHasProblem('V-01', $this->layoutWithColumn(['format' => 'money', 'formatArgs' => ['symbol' => 'left']]));
    }

    public function test_v01_rejects_a_cell_argument_outside_the_list(): void
    {
        $this->assertHasProblem('V-01', $this->layoutWithColumn(
            ['format' => 'money', 'formatArgs' => ['decimals' => 2, 'symbol' => 'code']],
            "{{ linha.%s|money(9, 'code') }}",
        ));
    }

    public function test_v01_rejects_a_hand_built_cell_expression(): void
    {
        $this->assertHasProblem('V-01', $this->layoutWithColumn(
            ['format' => 'money', 'formatArgs' => ['decimals' => 2, 'symbol' => 'code']],
            "{{ linha.%s|money(2,'code') }}",
        ));
    }

    public function test_v01_accepts_a_column_with_matching_arguments(): void
    {
        $this->assertSame([], $this->problems($this->layoutWithColumn(
            ['format' => 'money', 'formatArgs' => ['decimals' => 2, 'symbol' => 'code']],
            "{{ linha.%s|money(2, 'code') }}",
        )));
    }

    /**
     * The valid layout with its first date field given a pattern, and the field's
     * exported expression forced to `$expression` (`%s` is the binding).
     */
    private function layoutWithDate(string $pattern, string $expression): array
    {
        $blocks = $this->defaultBlocks(self::TYPE_CODE);

        foreach ($blocks as &$block) {
            if (($block['format'] ?? '') === 'date') {
                $block['field_attributes'] = ['data-tpl-format-pattern' => $pattern];
                $block['expression']       = sprintf($expression, $block['binding']);

                break;
            }
        }

        unset($block);

        return $this->layout($blocks);
    }

    /**
     * The valid layout with its line table's first column changed, and the cell's
     * expression either derived from the change or forced (`%s` is the column key).
     *
     * @param  array<string, mixed>  $change
     */
    private function layoutWithColumn(array $change, ?string $expression = null): array
    {
        $blocks = $this->defaultBlocks(self::TYPE_CODE);

        foreach ($blocks as &$block) {
            if (($block['type'] ?? null) !== 'line-table') {
                continue;
            }

            $columns    = json_decode($block['attributes']['data-tpl-columns'], true);
            $columns[0] = array_merge($columns[0], $change);

            if ($expression !== null) {
                $columns[0]['expression'] = sprintf($expression, $columns[0]['key']);
            }

            $block['attributes']['data-tpl-columns'] = json_encode($columns);
        }

        unset($block);

        return $this->layout($blocks);
    }

    public function test_v02_requires_a_mandatory_field_that_is_not_legal(): void
    {
        $layout = $this->layoutWithout('totais.total_iliquido');

        $this->assertHasProblem('V-02', $layout);
    }

    /**
     * A legal field that is absent is one defect, so it produces one message — V-10,
     * the one that says it is a legal requirement. V-10 is a reinforcement of V-02;
     * reporting both would put two lines in the panel for the same missing field.
     */
    public function test_a_missing_legal_field_is_reported_once(): void
    {
        $codes = array_column($this->problems($this->layoutWithout('documento.atcud')), 'code');

        $this->assertSame(['V-10'], array_values(array_unique($codes)));
    }

    /**
     * CGE-69: a mandatory field the law does not require may be printed through a
     * formula — the art. 36 sentence carrying the supply date — and counts as placed.
     */
    public function test_mandatory_field_used_in_a_formula_counts_as_placed(): void
    {
        $layout = $this->layoutReplacing('totais.total_iliquido', $this->formulaField(
            'formula.abc123',
            '"Total sem IVA: " & TEXT(totais.total_iliquido, "money")',
        ));

        $this->assertNotContains('V-02', array_column($this->problems($layout), 'code'));
    }

    /** A legal field is never placed by a formula: it has to be a block of its own. */
    public function test_legal_field_used_in_a_formula_is_still_missing(): void
    {
        $layout = $this->layoutReplacing('documento.atcud', $this->formulaField(
            'formula.abc123',
            '"ATCUD: " & documento.atcud',
        ));

        $this->assertHasProblem('V-10', $layout);
    }

    /** Naming a field inside a table column's formula does not place it on the document. */
    public function test_column_formula_does_not_place_a_field(): void
    {
        $blocks = $this->blocksWithout('totais.total_iliquido');

        foreach ($blocks as &$block) {
            if (($block['type'] ?? '') === 'line-table') {
                $block['attributes']['data-tpl-columns'] = json_encode([
                    ['key' => 'formula_abc123', 'label' => 'Calc', 'width' => '', 'align' => 'right', 'format' => '', 'formula' => 'totais.total_iliquido'],
                ]);
            }
        }

        unset($block);

        $this->assertHasProblem('V-02', $this->layout($blocks));
    }

    public function test_v04_rejects_a_block_that_runs_outside_its_zone(): void
    {
        $layout = $this->layoutWith(
            'emitente.nif',
            fn (array $block): array => [...$block, 'style' => [...$block['style'], 'left' => '-40mm']],
        );

        $this->assertHasProblem('V-04', $layout);
    }

    public function test_v05_rejects_an_image_with_no_file(): void
    {
        $layout = $this->layoutPlus([
            'type'       => 'image',
            'zone'       => 'header',
            'id'         => 'tpl-logo',
            'attributes' => ['src' => ''],
            'style'      => ['position' => 'absolute', 'left' => '150mm', 'top' => '0mm', 'width' => '20mm', 'height' => '20mm'],
        ]);

        $this->assertHasProblem('V-05', $layout);
    }

    public function test_v05_rejects_an_image_over_the_size_limit(): void
    {
        $payload = base64_encode(str_repeat('a', (int) config('templates.image_max_bytes') + 1024));

        $layout = $this->layoutPlus([
            'type'       => 'image',
            'zone'       => 'header',
            'id'         => 'tpl-logo',
            'attributes' => ['src' => 'data:image/png;base64,'.$payload],
            'style'      => ['position' => 'absolute', 'left' => '150mm', 'top' => '0mm', 'width' => '20mm', 'height' => '20mm'],
        ]);

        $this->assertHasProblem('V-05', $layout);
    }

    /**
     * The editor offers no way to write script, so its presence means the payload was
     * tampered with on the way in — the guards refuse rather than strip, and V-07 is
     * that refusal surfaced as a validation problem.
     */
    public function test_v07_rejects_markup_the_editor_could_not_have_produced(): void
    {
        $layout         = $this->validLayout();
        $layout['html'] = '<body><div class="page"><script>fetch("/")</script></div></body>';

        $this->assertHasProblem('V-07', $layout);
    }

    /* -----------------------------------------------------------------------
     * Legal field visibility
     * -------------------------------------------------------------------- */

    public function test_v10_requires_every_legal_field(): void
    {
        $this->assertHasProblem('V-10', $this->layoutWithout('documento.atcud'));
    }

    public function test_v11_rejects_a_font_below_the_minimum(): void
    {
        $minimum = (float) (Templates::type(self::TYPE_CODE)->pdf['visibility_limits']['font_size_min_pt'] ?? 7);

        $layout = $this->layoutWith(
            'emitente.nif',
            fn (array $block): array => [...$block, 'style' => [...$block['style'], 'font-size' => ($minimum - 1).'pt']],
        );

        $this->assertHasProblem('V-11', $layout);
    }

    /** A size at the minimum passes: the rule is "at least", not "more than". */
    public function test_v11_accepts_a_font_exactly_at_the_minimum(): void
    {
        $minimum = (float) (Templates::type(self::TYPE_CODE)->pdf['visibility_limits']['font_size_min_pt'] ?? 7);

        $layout = $this->layoutWith(
            'emitente.nif',
            fn (array $block): array => [...$block, 'style' => [...$block['style'], 'font-size' => $minimum.'pt']],
        );

        $this->assertSame([], $this->problems($layout));
    }

    public function test_v12_rejects_white_on_white(): void
    {
        $layout = $this->layoutWith(
            'emitente.nif',
            fn (array $block): array => [...$block, 'style' => [...$block['style'], 'color' => '#ffffff']],
        );

        $this->assertHasProblem('V-12', $layout);
    }

    /**
     * The background is resolved up the ancestor chain, so a coloured header band is
     * what the text is measured against — not the white of the page underneath it.
     */
    public function test_v12_measures_against_the_zone_background(): void
    {
        $layout = $this->validLayout();

        // A dark header with dark text: legible against paper, not against the band.
        $layout['project_data']['styles'][] = [
            'selectors' => ['zone--header'],
            'style'     => ['background-color' => '#1f2937'],
        ];

        $this->assertHasProblem('V-12', $layout);
    }

    public function test_v13_rejects_a_transparent_field(): void
    {
        $layout = $this->layoutWith(
            'emitente.nif',
            fn (array $block): array => [...$block, 'style' => [...$block['style'], 'opacity' => '0.4']],
        );

        $this->assertHasProblem('V-13', $layout);
    }

    public function test_v13_rejects_a_hidden_field(): void
    {
        $layout = $this->layoutWith(
            'emitente.nif',
            fn (array $block): array => [...$block, 'style' => [...$block['style'], 'display' => 'none']],
        );

        $this->assertHasProblem('V-13', $layout);
    }

    /** An ancestor hiding the field hides the field, which is the sneakier version. */
    public function test_v13_rejects_a_field_inside_a_hidden_zone(): void
    {
        $layout = $this->validLayout();

        $layout['project_data']['styles'][] = [
            'selectors' => ['zone--header'],
            'style'     => ['visibility' => 'hidden'],
        ];

        $this->assertHasProblem('V-13', $layout);
    }

    public function test_v14_rejects_a_field_outside_the_printable_area(): void
    {
        $layout = $this->layoutWith(
            'emitente.nif',
            fn (array $block): array => [...$block, 'style' => [...$block['style'], 'left' => '-40mm']],
        );

        $this->assertHasProblem('V-14', $layout);
    }

    /**
     * The tolerance exists because the engine snaps fills to the CSS pixel grid.
     * Without it a block deliberately flush with the margin would be refused for
     * rounding rather than for placement.
     */
    public function test_v14_tolerates_the_engine_rounding(): void
    {
        $tolerance = (float) config('templates.geometry_tolerance_mm');

        $layout = $this->layoutWith(
            'emitente.nif',
            fn (array $block): array => [...$block, 'style' => [...$block['style'], 'left' => (-$tolerance / 2).'mm']],
        );

        $this->assertSame([], $this->problems($layout));
    }

    /**
     * A field whose left is itself past the printable right edge is outside it even
     * with no stated width: the origin alone decides it. Missed, the field survives
     * validation and the layout renders to a page metres wide, whose rasterising and
     * cropping ran activation past its time limit — the layout has to be refused here.
     */
    public function test_v14_rejects_a_field_whose_origin_is_past_the_printable_edge(): void
    {
        $layout = $this->layoutWith(
            'emitente.nif',
            function (array $block): array {
                unset($block['style']['width'], $block['style']['height']);
                $block['style']['left'] = '300mm';

                return $block;
            },
        );

        $this->assertHasProblem('V-14', $layout);
    }

    public function test_v15_rejects_a_field_covered_by_an_opaque_block(): void
    {
        $this->assertHasProblem('V-15', $this->layoutWithRectangleOverTheIssuerVatNumber('#000000'));
    }

    /** A translucent rectangle does not hide what is under it. */
    public function test_v15_ignores_a_translucent_block(): void
    {
        $this->assertSame([], $this->problems(
            $this->layoutWithRectangleOverTheIssuerVatNumber('rgba(0, 0, 0, 0.5)'),
        ));
    }

    /** A rectangle beside the field is not covering it. */
    public function test_v15_ignores_a_block_that_does_not_overlap(): void
    {
        $this->assertSame([], $this->problems(
            $this->layoutWithRectangleOverTheIssuerVatNumber('#000000', '0mm'),
        ));
    }

    /* -----------------------------------------------------------------------
     * Occlusion (CGE-27): V-15 blocks a covered legal field, V-18 warns otherwise
     * -------------------------------------------------------------------- */

    public function test_v15_rejects_an_image_over_a_legal_field(): void
    {
        $this->assertHasProblem('V-15', $this->layoutWithCover(
            'emitente.nif',
            $this->image(['left' => '90mm', 'top' => '0mm', 'width' => '60mm', 'height' => '6mm']),
            ['width' => '40mm', 'height' => '4mm'],
        ));
    }

    public function test_v15_rejects_an_image_over_the_legal_qr(): void
    {
        $layout = $this->layoutWithCover(
            'documento.qr',
            $this->image(['left' => '150mm', 'top' => '0mm', 'width' => '20mm', 'height' => '20mm'], 'footer'),
        );

        $occluded = array_values(array_filter(
            $this->problems($layout),
            fn (array $problem): bool => $problem['code'] === 'V-15',
        ));

        $this->assertCount(1, $occluded);
        $this->assertSame('tpl-code-'.md5('documento.qr'), $occluded[0]['component_id']);
    }

    /** A covered field that the law does not require is the user's call: warned, not refused. */
    public function test_v18_warns_but_does_not_block_a_covered_non_legal_field(): void
    {
        $layout = $this->layoutWithCover(
            'documento.data_bens',
            $this->image(['left' => '0mm', 'top' => '0mm', 'width' => '300mm', 'height' => '40mm']),
            ['width' => '40mm', 'height' => '4mm'],
        );

        $codes = array_column($this->problems($layout), 'code');
        $this->assertNotContains('V-18', $codes, 'validate() returns blocking problems only.');

        $occlusions = $this->occlusions($layout);
        $warned     = array_values(array_filter($occlusions, fn (array $o): bool => $o['code'] === 'V-18'));

        $this->assertCount(1, $warned);
        $this->assertStringContainsString('Data dos bens/serviços', $warned[0]['message']);
        $this->assertNotContains('V-15', array_column($occlusions, 'code'));
    }

    public function test_v18_warns_on_a_covered_non_legal_code_slot(): void
    {
        $layout = $this->layout(array_merge($this->defaultBlocks(self::TYPE_CODE), [
            [
                'type'       => 'code-slot',
                'zone'       => 'footer',
                'id'         => 'tpl-fixed-code',
                'attributes' => ['data-tpl-code-type' => 'qr'],
                'style'      => ['position' => 'absolute', 'left' => '100mm', 'top' => '0mm', 'width' => '20mm', 'height' => '20mm'],
            ],
            $this->image(['left' => '100mm', 'top' => '0mm', 'width' => '20mm', 'height' => '20mm'], 'footer'),
        ]));

        $warned = array_values(array_filter(
            $this->occlusions($layout),
            fn (array $o): bool => $o['code'] === 'V-18' && $o['component_id'] === 'tpl-fixed-code',
        ));

        $this->assertCount(1, $warned);
        $this->assertStringContainsString(__('templates::editor.blocks.code_slot'), $warned[0]['message']);
    }

    /** The B4 case: a grey band over the total, in the body, on a field with no height. */
    public function test_occlusion_is_detected_in_the_body_zone(): void
    {
        $this->assertHasProblem('V-15', $this->layoutWithCover(
            'totais.total_a_pagar',
            $this->rectangle(['left' => '140mm', 'top' => '171mm', 'width' => '40mm', 'height' => '5mm'], '#d9d9d9', 'body'),
            ['left' => '145mm', 'top' => '172mm', 'width' => '35mm'],
            'body',
        ));
    }

    /**
     * A field with no stated height is one line of its own font (blocks never wrap), so
     * a band starting just under that line does not cover it. At the 12pt default the
     * line would reach it.
     */
    public function test_a_field_with_no_height_is_one_line_tall(): void
    {
        $layout = $this->layoutWithCover(
            'totais.total_a_pagar',
            $this->rectangle(['left' => '140mm', 'top' => '175mm', 'width' => '40mm', 'height' => '5mm'], '#000000', 'body'),
            ['left' => '145mm', 'top' => '172mm', 'width' => '35mm'],
            'body',
        );

        $this->assertNotContains('V-15', array_column($this->problems($layout), 'code'));
    }

    /** The editor drops every image with a transparent pixel until a file is chosen. */
    public function test_the_empty_image_placeholder_does_not_occlude(): void
    {
        $layout = $this->layoutWithCover(
            'emitente.nif',
            $this->image(
                ['left' => '90mm', 'top' => '0mm', 'width' => '60mm', 'height' => '6mm'],
                'header',
                'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7',
            ),
            ['width' => '40mm', 'height' => '4mm'],
        );

        $this->assertNotContains('V-15', array_column($this->problems($layout), 'code'));
    }

    public function test_a_translucent_image_does_not_occlude(): void
    {
        $layout = $this->layoutWithCover(
            'emitente.nif',
            $this->image(['left' => '90mm', 'top' => '0mm', 'width' => '60mm', 'height' => '6mm', 'opacity' => '0.5']),
            ['width' => '40mm', 'height' => '4mm'],
        );

        $this->assertNotContains('V-15', array_column($this->problems($layout), 'code'));
    }

    /** A separator line grazing the bottom of the field is within the engine's tolerance. */
    public function test_a_rule_touching_a_legal_field_does_not_occlude(): void
    {
        $layout = $this->layoutWithCover(
            'emitente.nif',
            $this->rectangle(['left' => '90mm', 'top' => '3.7mm', 'width' => '60mm', 'height' => '0.26mm'], '#000000'),
            ['width' => '40mm', 'height' => '4mm'],
        );

        $this->assertNotContains('V-15', array_column($this->problems($layout), 'code'));
    }

    /**
     * Text over text hides nothing (no V-15), but the two print on top of each other and
     * come out illegible: CGE-90 warns about it (V-20) — even over a legal field, as a
     * warning, since nothing paints the legal text away.
     */
    public function test_text_over_text_warns_overlap(): void
    {
        $layout = $this->layoutWithCover('emitente.nif', $this->text(['left' => '90mm', 'top' => '0mm', 'width' => '60mm', 'height' => '6mm']), ['width' => '40mm', 'height' => '4mm']);

        $this->assertNotContains('V-15', array_column($this->problems($layout), 'code'));
        $this->assertNotContains('V-20', array_column($this->problems($layout), 'code'), 'a warning never refuses a save');

        $overlaps = array_values(array_filter($this->occlusions($layout), fn (array $o): bool => $o['code'] === 'V-20'));

        $this->assertCount(1, $overlaps, 'one problem per pair');
        $this->assertSame('tpl-cover', $overlaps[0]['component_id'], 'reported on the block on top');
        $this->assertStringContainsString('NIF', $overlaps[0]['message']);
    }

    /** A line or an unfilled rectangle around or under text is design, not an overlap. */
    public function test_rule_over_text_is_not_an_overlap(): void
    {
        foreach (['transparent', 'rgba(0, 0, 0, 0)'] as $background) {
            $layout = $this->layoutWithCover(
                'emitente.nif',
                $this->rectangle(['left' => '88mm', 'top' => '-1mm', 'width' => '50mm', 'height' => '6mm'], $background),
                ['width' => '40mm', 'height' => '4mm'],
            );

            $this->assertNotContains('V-20', array_column($this->occlusions($layout), 'code'));
        }
    }

    /**
     * What overlaps is the printed text, not the box (CGE-90): a wide right-aligned value
     * next to a label — the seeded invoice's header — prints clear of it.
     */
    public function test_a_right_aligned_value_clear_of_its_label_is_not_an_overlap(): void
    {
        $label = ['type' => 'static-text', 'zone' => 'header', 'id' => 'tpl-label', 'text' => 'EMISSÃO:',
            'style' => ['position' => 'absolute', 'left' => '0mm', 'top' => '35mm', 'width' => '30mm', 'font-size' => '8pt']];
        $value = fn (string $text): array => ['type' => 'static-text', 'zone' => 'header', 'id' => 'tpl-value', 'text' => $text,
            'style' => ['position' => 'absolute', 'left' => '10mm', 'top' => '35mm', 'width' => '40mm', 'font-size' => '9pt', 'text-align' => 'right']];

        $clear = $this->layout([...$this->defaultBlocks(self::TYPE_CODE), $label, $value('1.229.999,98 EUR')]);
        $this->assertNotContains('V-20', array_column($this->occlusions($clear), 'code'));

        // Text long enough to run back over the label does overlap it.
        $long = $this->layout([...$this->defaultBlocks(self::TYPE_CODE), $label, $value('1.229.999.999.999.999.999,98 EUR')]);
        $this->assertContains('V-20', array_column($this->occlusions($long), 'code'));

        // Centred text sits in the middle of its box.
        $centred = $this->layout([...$this->defaultBlocks(self::TYPE_CODE), $label,
            [...$value('ABC'), 'style' => [...$value('ABC')['style'], 'text-align' => 'center']]]);
        $this->assertNotContains('V-20', array_column($this->occlusions($centred), 'code'));
    }

    /** A data field is measured by the sample value it prints, formatted as placed. */
    public function test_a_data_field_is_measured_by_its_sample_value(): void
    {
        $note = fn (string $left): array => $this->text(['left' => $left, 'top' => '0mm', 'width' => '20mm', 'height' => '4mm']);

        // 999999999 at 8pt is about 15mm wide: the box runs to 150mm, the text to ~105mm.
        $clear = $this->layoutWithCover('emitente.nif', [...$note('120mm'), 'text' => 'Nota'], ['width' => '60mm', 'height' => '4mm', 'font-size' => '8pt']);
        $this->assertNotContains('V-20', array_column($this->occlusions($clear), 'code'));

        $over = $this->layoutWithCover('emitente.nif', [...$note('95mm'), 'text' => 'Nota'], ['width' => '60mm', 'height' => '4mm', 'font-size' => '8pt']);
        $this->assertContains('V-20', array_column($this->occlusions($over), 'code'));
    }

    /**
     * The save's warnings measure data fields by the sample the host hands in: without it
     * a value is its whole box, and a wide value box would warn where its text is clear.
     */
    public function test_layout_warnings_measure_data_fields_with_the_given_sample(): void
    {
        $layout   = $this->layoutWithCover('emitente.nif', [...$this->text(['left' => '120mm', 'top' => '0mm', 'width' => '20mm', 'height' => '4mm']), 'text' => 'Nota'], ['width' => '60mm', 'height' => '4mm', 'font-size' => '8pt']);
        $type     = Templates::type(self::TYPE_CODE);
        $fields   = array_values($type->bindings);
        $geometry = $type->pdf + ['zones' => $type->initialLayout['zones'] ?? []];

        $this->assertSame(['V-20'], array_column(app(Documents::class)->layoutWarnings($layout, $fields, $geometry), 'code'));
        $this->assertSame([], app(Documents::class)->layoutWarnings($layout, $fields, $geometry, $type->fixture));
    }

    /** Blocks that only touch, within the engine's tolerance, do not overlap. */
    public function test_touching_blocks_within_tolerance_do_not_overlap(): void
    {
        $layout = $this->layoutWithCover('emitente.nif', $this->text(['left' => '90mm', 'top' => '3.7mm', 'width' => '60mm', 'height' => '4mm']), ['width' => '40mm', 'height' => '4mm']);

        $this->assertNotContains('V-20', array_column($this->occlusions($layout), 'code'));
    }

    /** Boxes are relative to their zone: the same numbers in another zone are elsewhere. */
    public function test_blocks_in_different_zones_do_not_overlap(): void
    {
        $layout = $this->layoutWithCover('emitente.nif', $this->text(['left' => '90mm', 'top' => '0mm', 'width' => '60mm', 'height' => '6mm'], 'footer'), ['width' => '40mm', 'height' => '4mm']);

        $this->assertNotContains('V-20', array_column($this->occlusions($layout), 'code'));
    }

    /** An opaque block over a field is occlusion (V-15/V-18), said once, not also V-20. */
    public function test_an_occluded_field_is_not_also_an_overlap(): void
    {
        $layout = $this->layoutWithCover('emitente.nif', $this->image(['left' => '90mm', 'top' => '0mm', 'width' => '60mm', 'height' => '6mm']), ['width' => '40mm', 'height' => '4mm']);

        $codes = array_column($this->occlusions($layout), 'code');

        $this->assertContains('V-15', $codes);
        $this->assertNotContains('V-20', $codes);
    }

    /** The host asks for confirmation on V-20 as on V-18; activation never sees it. */
    public function test_layout_warnings_include_overlap(): void
    {
        $layout   = $this->layoutWithCover('emitente.nif', $this->text(['left' => '90mm', 'top' => '0mm', 'width' => '60mm', 'height' => '6mm']), ['width' => '40mm', 'height' => '4mm']);
        $type     = Templates::type(self::TYPE_CODE);
        $fields   = array_values($type->bindings);
        $geometry = $type->pdf + ['zones' => $type->initialLayout['zones'] ?? []];

        $this->assertSame(['V-20'], array_column(app(Documents::class)->layoutWarnings($layout, $fields, $geometry), 'code'));
        $this->assertSame([], app(Documents::class)->legalOcclusions($layout, $fields, $geometry));
    }

    /**
     * A talão is a fiscal invoice too. Its blocks stay absolutely positioned inside their
     * zone (a roll only pads the page), so two blocks of one zone overlap there exactly as
     * on a sheet.
     */
    public function test_occlusion_is_detected_on_a_roll(): void
    {
        $layout = $this->layoutWithRectangleOverTheIssuerVatNumber('#000000');

        $codes = array_column(
            app(LayoutValidator::class)->validate($layout['project_data'], $this->rollType(), $layout['html'], $layout['css']),
            'code',
        );

        $this->assertContains('V-15', $codes);
    }

    /**
     * An underline drawn in the descender band of a field with no height is not covering
     * it: the field's box is its em box, not a line box padded below the ink. Medium
     * (0.53mm) and thick (0.79mm) rules exceed the tolerance by their thickness alone.
     */
    public function test_a_thick_rule_under_a_field_with_no_height_does_not_occlude(): void
    {
        foreach (['0.53mm' => '2.8mm', '0.79mm' => '2.6mm'] as $thickness => $top) {
            $layout = $this->layoutWithCover(
                'emitente.nif',
                $this->rectangle(['left' => '90mm', 'top' => $top, 'width' => '60mm', 'height' => $thickness], '#000000'),
                ['width' => '40mm'],
            );

            $this->assertNotContains('V-15', array_column($this->problems($layout), 'code'), "A {$thickness} rule at {$top}.");
        }
    }

    public function test_documents_split_occlusions_by_severity(): void
    {
        $layout = $this->layout(array_merge(
            array_map(function (array $block): array {
                if (in_array($block['binding'] ?? null, ['emitente.nif', 'documento.data_bens'], true)) {
                    $block['style']['width']  = '40mm';
                    $block['style']['height'] = '4mm';
                }

                return $block;
            }, $this->defaultBlocks(self::TYPE_CODE)),
            [$this->image(['left' => '0mm', 'top' => '0mm', 'width' => '180mm', 'height' => '40mm'])],
        ));

        $type     = Templates::type(self::TYPE_CODE);
        $fields   = array_values($type->bindings);
        $geometry = $type->pdf + ['zones' => $type->initialLayout['zones'] ?? []];

        $legal    = app(Documents::class)->legalOcclusions($layout, $fields, $geometry);
        $warnings = app(Documents::class)->layoutWarnings($layout, $fields, $geometry);

        $this->assertNotSame([], $legal);
        $this->assertNotSame([], $warnings);
        $this->assertSame(['V-15'], array_values(array_unique(array_column($legal, 'code'))));
        $this->assertSame(['V-18'], array_values(array_unique(array_column($warnings, 'code'))));
    }

    /**
     * Two tables over the same collection would print the lines twice, and the
     * compositor paginates against one table.
     */
    public function test_v03_rejects_a_second_table_over_the_same_collection(): void
    {
        $layout = $this->layoutPlus([
            'type'       => 'line-table',
            'zone'       => 'body',
            'id'         => 'tpl-lines-again',
            'attributes' => ['data-tpl-collection' => 'linhas', 'data-tpl-columns' => '[]'],
            'style'      => ['position' => 'absolute', 'left' => '0mm', 'top' => '80mm', 'width' => '180mm'],
        ]);

        $this->assertHasProblem('V-03', $layout);
    }

    /** Tables over different collections are the multi-table case, not a duplicate. */
    public function test_v03_accepts_tables_over_different_collections(): void
    {
        $layout = $this->layoutPlus($this->extraTable('tpl-other', 'outra'));

        $this->assertNotContains('V-03', array_column($this->problems($layout), 'code'));
    }

    /** One table per collection is the normal case. */
    public function test_v03_accepts_a_single_table(): void
    {
        $this->assertNotContains('V-03', array_column($this->problems($this->layout($this->defaultBlocks('invoice'))), 'code'));
    }

    /** A duplicate is an error for any collection, declared or not, and falls back to the key as label. */
    public function test_v03_rejects_a_second_table_over_an_undeclared_collection(): void
    {
        $layout = $this->layout(array_merge($this->defaultBlocks('invoice'), [
            $this->extraTable('tpl-a', 'outra'),
            $this->extraTable('tpl-b', 'outra'),
        ]));

        $this->assertHasProblem('V-03', $layout);
    }

    /** Unbound tables are skipped by the multi-table flow, so two of them are not a V-03. */
    public function test_v03_ignores_unbound_tables(): void
    {
        $layout = $this->layout(array_merge($this->defaultBlocks('invoice'), [
            $this->extraTable('tpl-u1', ''),
            $this->extraTable('tpl-u2', ''),
        ]));

        $this->assertNotContains('V-03', array_column($this->problems($layout), 'code'));
    }

    /** @return array<string, mixed> */
    private function extraTable(string $id, string $collection): array
    {
        return [
            'type'       => 'line-table',
            'zone'       => 'body',
            'id'         => $id,
            'attributes' => ['data-tpl-collection' => $collection, 'data-tpl-columns' => '[]'],
            'style'      => ['position' => 'absolute', 'left' => '0mm', 'top' => '80mm', 'width' => '180mm'],
        ];
    }

    /** The table paginates, and only the body paginates. */
    public function test_v06_rejects_the_line_table_outside_the_body(): void
    {
        $layout = $this->layoutPlus([
            'type'       => 'line-table',
            'zone'       => 'header',
            'id'         => 'tpl-lines-header',
            'attributes' => ['data-tpl-collection' => 'linhas', 'data-tpl-columns' => '[]'],
            'style'      => ['position' => 'absolute', 'left' => '0mm', 'top' => '0mm', 'width' => '100mm'],
        ]);

        $this->assertHasProblem('V-06', $layout);
    }

    /** A page number in the body would appear once, in the middle of the lines. */
    public function test_v06_rejects_a_page_number_in_the_body(): void
    {
        $layout = $this->layoutPlus([
            'type'       => 'page-number',
            'zone'       => 'body',
            'id'         => 'tpl-pageno',
            'attributes' => ['data-tpl-format' => 'x'],
            'style'      => ['position' => 'absolute', 'left' => '0mm', 'top' => '150mm', 'font-size' => '8pt'],
        ]);

        $this->assertHasProblem('V-06', $layout);
    }

    /**
     * The minimum is about the slot, which is the only size the layout states — and a
     * QR spends four modules of quiet zone on each side, so a 30mm slot prints about
     * 24mm of actual code.
     */
    public function test_v17_rejects_a_code_slot_below_the_minimum_side(): void
    {
        $minimum = (float) (Templates::type(self::TYPE_CODE)->pdf['visibility_limits']['qr_size_min_mm'] ?? 30);

        $layout = $this->layoutWith(
            'documento.qr',
            fn (array $block): array => [...$block, 'style' => [
                ...$block['style'],
                'width'  => ($minimum - 10).'mm',
                'height' => ($minimum - 10).'mm',
            ]],
        );

        $this->assertHasProblem('V-17', $layout);
    }

    /** A slot with no stated size has not stated the thing the rule is about. */
    public function test_v17_rejects_a_code_slot_with_no_size(): void
    {
        $layout = $this->layoutWith(
            'documento.qr',
            function (array $block): array {
                unset($block['style']['width'], $block['style']['height']);

                return $block;
            },
        );

        $this->assertHasProblem('V-17', $layout);
    }

    /** The legal code has to be there at all, and it is a slot rather than a field. */
    public function test_v10_requires_the_legal_code_slot(): void
    {
        $this->assertHasProblem('V-10', $this->layoutWithout('documento.qr'));
    }

    /** A mandatory collection has to be bound by a table, not by a data field. */
    public function test_v02_requires_the_line_table(): void
    {
        $this->assertHasProblem('V-02', $this->layoutWithout('linhas'));
    }

    public function test_v16_rejects_an_every_page_field_in_the_body(): void
    {
        $layout = $this->layoutWith(
            'documento.atcud',
            fn (array $block): array => [...$block, 'zone' => 'body'],
        );

        $this->assertHasProblem('V-16', $layout);
    }

    public function test_v16_is_skipped_on_a_roll_but_kept_on_a_sheet(): void
    {
        $layout = $this->layoutWith(
            'documento.atcud',
            fn (array $block): array => [...$block, 'zone' => 'body'],
        );

        $base = Templates::type(self::TYPE_CODE);
        $roll = new TemplateType(
            $base->code, $base->label, $base->output, $base->seeded, $base->layoutRevision,
            $base->bindings, $base->nodes, $base->fixture, $base->fixtureRepeats,
            $base->initialLayout,
            ['medium' => 'roll', 'width_mm' => 80, 'fit' => 'measure', 'max_height_mm' => 4000,
                'margins' => ['top' => 3, 'right' => 3, 'bottom' => 3, 'left' => 3]],
            [],
        );

        $validate = fn (TemplateType $type): array => array_column(
            app(LayoutValidator::class)->validate($layout['project_data'], $type, $layout['html'], $layout['css']),
            'code',
        );

        $this->assertNotContains('V-16', $validate($roll));
        $this->assertContains('V-16', $validate($base));
    }

    /* -----------------------------------------------------------------------
     * The shape the caller depends on
     *
     * The host has no HTTP save endpoint or persistence layer in this package; what
     * survives here is the part of the acceptance criterion the validator itself owns.
     * -------------------------------------------------------------------- */

    /** Every problem carries the three keys a caller renders a panel from. */
    public function test_a_failing_layout_reports_every_problem_with_a_code_component_and_message(): void
    {
        $problems = $this->problems($this->layoutWithout('documento.atcud'));

        $this->assertContains('V-10', array_column($problems, 'code'));

        foreach ($problems as $problem) {
            $this->assertArrayHasKey('code', $problem);
            $this->assertArrayHasKey('component_id', $problem);
            $this->assertArrayHasKey('message', $problem);
        }
    }

    /** Fix the layout and the same validation goes through clean. */
    public function test_correcting_the_layout_produces_no_problems(): void
    {
        $broken = $this->layoutWith(
            'emitente.nif',
            fn (array $block): array => [...$block, 'style' => [...$block['style'], 'font-size' => '4pt']],
        );

        $this->assertNotSame([], $this->problems($broken));
        $this->assertSame([], $this->problems($this->validLayout()));
    }

    /* -----------------------------------------------------------------------
     * The pieces the rules are built on
     * -------------------------------------------------------------------- */

    public function test_contrast_follows_wcag(): void
    {
        $white = Colour::parse('#ffffff');

        $this->assertSame(21.0, round(Colour::parse('rgb(0, 0, 0)')->contrastWith($white), 2));
        $this->assertSame(1.0, round($white->contrastWith($white), 2));

        // The canonical AA boundary for normal text.
        $this->assertSame(4.54, round(Colour::parse('#767676')->contrastWith($white), 2));

        // A colour notation the editor cannot produce stays unknown rather than being
        // guessed, so the rule can choose to say nothing.
        $this->assertNull(Colour::parse('chartreuse'));
    }

    /** A semi-transparent colour is measured as it will appear, not as declared. */
    public function test_contrast_composites_transparency(): void
    {
        $white = Colour::parse('#ffffff');
        $faded = Colour::parse('rgba(0, 0, 0, 0.3)')->over($white);

        $this->assertLessThan(3.0, $faded->contrastWith($white));
    }

    /**
     * GrapesJS rewrites a plain `0` to `0px`, so a millimetre layout still contains
     * pixels and a rule that only understood millimetres would read half a document.
     */
    public function test_lengths_convert_from_every_unit_the_editor_emits(): void
    {
        $this->assertSame(0.0, LayoutDocument::millimetres('0px'));
        $this->assertSame(13.0, LayoutDocument::millimetres('13mm'));
        $this->assertSame(10.0, LayoutDocument::millimetres('1cm'));
        $this->assertSame(25.4, LayoutDocument::millimetres('1in'));
        $this->assertSame(9.0, round((float) LayoutDocument::points('9pt'), 6));

        // A unit that says nothing on its own must not be turned into a number.
        $this->assertNull(LayoutDocument::millimetres('50%'));
        $this->assertNull(LayoutDocument::millimetres('auto'));
        $this->assertNull(LayoutDocument::millimetres(null));
    }

    /** The id rule wins over the class rule, as it does in the browser. */
    public function test_an_id_rule_overrides_a_class_rule(): void
    {
        $document = LayoutDocument::fromProjectData([
            'styles' => [
                ['selectors' => ['block'], 'style' => ['font-size' => '6pt', 'color' => '#111111']],
                ['selectors' => ['#tpl-one'], 'style' => ['font-size' => '12pt']],
            ],
            'pages' => [['frames' => [['component' => [
                'type'       => 'wrapper',
                'components' => [[
                    'classes'    => ['block'],
                    'attributes' => ['id' => 'tpl-one'],
                ]],
            ]]]]],
        ]);

        $style = $document->styleOf($document->blocks()[0]);

        $this->assertSame('12pt', $style['font-size']);
        $this->assertSame('#111111', $style['color']);
    }

    /**
     * With the default (declared-only) resolver a key the type never declared is a
     * configuration error: the toolkit has no schema to resolve it from.
     */
    public function test_a_binding_the_type_never_declared_is_refused_by_the_default_resolver(): void
    {
        $layout = $this->layoutPlus(['binding' => 'adquirente.inexistente', 'format' => '']);

        $this->assertHasProblem('V-01', $layout);
    }

    /**
     * A host-provided BindingResolver makes a non-declared key valid, and only that key.
     */
    public function test_a_host_resolver_can_resolve_a_non_declared_binding(): void
    {
        $this->app->bind(BindingResolver::class, fn () => new class implements BindingResolver {
            public function resolve(string $key, TemplateType $type): ?array
            {
                return $key === 'adquirente.vat' ? [
                    'key' => $key, 'label' => 'VAT', 'type' => 'text',
                    'mandatory' => false, 'legal' => false, 'scope' => null, 'default_format' => '',
                ] : null;
            }
        });

        $resolved = $this->problems($this->layoutPlus(['binding' => 'adquirente.vat', 'format' => '']));
        $this->assertNotContains('V-01', array_column($resolved, 'code'));

        $unknown = $this->problems($this->layoutPlus(['binding' => 'adquirente.inexistente', 'format' => '']));
        $this->assertContains('V-01', array_column($unknown, 'code'));
    }

    /* -----------------------------------------------------------------------
     * Formulas (CGE-37): V-01 accepts their keys, V-19 reports their problems
     * -------------------------------------------------------------------- */

    public function test_formula_field_is_a_known_binding(): void
    {
        $this->assertSame([], $this->problems($this->layoutPlus(
            $this->formulaField('formula.abc123', 'totais.total_a_pagar * 2 & " EUR"'),
        )));
    }

    public function test_formula_field_may_sum_a_collection_and_read_dates(): void
    {
        $this->assertSame([], $this->problems($this->layoutPlus(
            $this->formulaField('formula.abc123', 'IF(SUM(linhas.valor) > 0, YEAR(documento.data_emissao), COUNT(linhas.codigo))'),
        )));
    }

    /** Enter in the formula box: line breaks are whitespace, and the export still matches. */
    public function test_formula_with_line_breaks_and_trailing_newline_is_valid(): void
    {
        $this->assertSame([], $this->problems($this->layoutPlus(
            $this->formulaField('formula.abc123', "totais.total_a_pagar *\r\n\t2\n"),
        )));
    }

    public function test_formula_text_literal_third_argument_is_checked_at_save(): void
    {
        $this->assertSame([], $this->problems($this->layoutPlus(
            $this->formulaField('formula.abc123', 'TEXT(totais.total_a_pagar, "money", 2) & TEXT(documento.data_emissao, "date", "dd/MM/yyyy")'),
        )));
        $this->assertFormulaProblem('formula_syntax', $this->layoutPlus($this->formulaField('formula.abc123', 'TEXT(totais.total_a_pagar, "money", 9)')));
        $this->assertFormulaProblem('formula_syntax', $this->layoutPlus($this->formulaField('formula.abc123', 'TEXT(documento.data_emissao, "date", "d MMM")')));
    }

    public function test_formula_invalid_formula_is_v19_syntax(): void
    {
        $this->assertFormulaProblem('formula_syntax', $this->layoutPlus($this->formulaField('formula.abc123', 'totais.total_a_pagar +')));
    }

    public function test_formula_wrong_argument_count_is_v19_syntax(): void
    {
        $this->assertFormulaProblem('formula_syntax', $this->layoutPlus($this->formulaField('formula.abc123', 'IF(totais.total_a_pagar > 0, 1)')));
    }

    public function test_formula_unknown_function_is_v19(): void
    {
        $this->assertFormulaProblem('formula_unknown_function', $this->layoutPlus($this->formulaField('formula.abc123', 'TODAY()')));
    }

    public function test_formula_unknown_reference_is_v19(): void
    {
        $this->assertFormulaProblem('formula_unknown_reference', $this->layoutPlus($this->formulaField('formula.abc123', 'emitente.inventado & "x"')));
    }

    /** No chaining: one formula cannot read another's result. */
    public function test_formula_reference_to_another_formula_is_v19(): void
    {
        $layout = $this->layoutWithFormulaFields([
            ['formula.abc123', 'totais.total_a_pagar * 2'],
            ['formula.def456', 'formula.abc123 + 1'],
        ]);

        $this->assertFormulaProblem('formula_unknown_reference', $layout);
    }

    public function test_formula_collection_column_outside_sum_is_v19_scope(): void
    {
        $this->assertFormulaProblem('formula_scope', $this->layoutPlus($this->formulaField('formula.abc123', 'linhas.valor * 2')));
    }

    /** Review Focus 3: a copied formula field keeps the key, and the second one is refused. */
    public function test_formula_two_fields_same_formula_key_is_v19_duplicate(): void
    {
        $layout   = $this->layoutWithFormulaFields([
            ['formula.abc123', 'totais.total_a_pagar * 2'],
            ['formula.abc123', 'totais.total_a_pagar * 2'],
        ]);
        $problems = $this->problems($layout);

        $this->assertSame(['V-19'], array_column($problems, 'code'));
        $this->assertSame($this->formulaMessage('formula_duplicate_key'), $problems[0]['message']);
        $this->assertSame('tpl-formula-1', $problems[0]['component_id'], 'Reported on the second field, not the first.');
    }

    public function test_formula_key_without_source_is_v19_and_not_v01(): void
    {
        $problems = $this->problems($this->layoutPlus($this->formulaField('formula.abc123', '  ')));

        $this->assertSame(['V-19'], array_column($problems, 'code'));
        $this->assertSame($this->formulaMessage('formula_without_source'), $problems[0]['message']);
    }

    /** The source attribute only means something on a key of the formula shape. */
    public function test_formula_source_on_a_catalogue_binding_is_ignored(): void
    {
        $problems = $this->problems($this->layoutPlus($this->formulaField('emitente.inventado', 'totais.total_a_pagar')));

        $this->assertSame(['V-01'], array_column($problems, 'code'));
    }

    public function test_formula_key_of_the_wrong_shape_is_v01(): void
    {
        $problems = $this->problems($this->layoutPlus($this->formulaField('formula.ABC', 'totais.total_a_pagar')));

        $this->assertSame(['V-01'], array_column($problems, 'code'));
    }

    /**
     * A digit right after the dot would print as `{{ formula.3b270w4p }}`, which Twig reads
     * as `formula.3` followed by a name and refuses: the layout saved, the PDF failed. A
     * key of that shape is not a formula key, so the save refuses it as an unknown field.
     */
    public function test_formula_key_with_a_leading_digit_is_refused_at_save(): void
    {
        $problems = $this->problems($this->layoutPlus($this->formulaField('formula.3b270w4p', 'totais.total_a_pagar')));

        $this->assertSame(['V-01'], array_column($problems, 'code'));

        // The same id led by a letter is a formula like any other.
        $this->assertSame([], $this->problems($this->layoutPlus($this->formulaField('formula.b3270w4p', 'totais.total_a_pagar'))));
    }

    /** The column side of the same rule: `formula_3…` is not a formula column, so it blocks. */
    public function test_formula_column_key_with_a_leading_digit_is_refused_at_save(): void
    {
        $problems = $this->problems($this->layoutWithFormulaColumns([['formula_3b270w4p', 'linhas.quantidade * 2']]));

        $this->assertSame(['V-01'], array_column($problems, 'code'));
        $this->assertSame(__('templates::editor.validation.unknown_binding', ['binding' => 'formula_3b270w4p']), $problems[0]['message']);

        $this->assertSame([], $this->problems($this->layoutWithFormulaColumns([['formula_b3270w4p', 'linhas.quantidade * 2']])));
    }

    public function test_formula_row_formula_column_checked_against_its_collection(): void
    {
        $this->assertSame([], $this->problems($this->layoutWithFormulaColumns([
            ['formula_abc123', 'linhas.quantidade * linhas.preco_unitario / SUM(linhas.valor)'],
        ])));

        $this->assertFormulaProblem('formula_unknown_reference', $this->layoutWithFormulaColumns([
            ['formula_abc123', 'linhas.inexistente * 2'],
        ]), 'Calculado');

        // A column cannot read another formula column either.
        $this->assertFormulaProblem('formula_unknown_reference', $this->layoutWithFormulaColumns([
            ['formula_abc123', 'linhas.quantidade * 2'],
            ['formula_def456', 'linhas.formula_abc123 + 1'],
        ]), 'Calculado');
    }

    public function test_formula_columns_with_the_same_key_are_v19_duplicate(): void
    {
        $this->assertFormulaProblem('formula_duplicate_key', $this->layoutWithFormulaColumns([
            ['formula_abc123', 'linhas.quantidade * 2'],
            ['formula_abc123', 'linhas.quantidade * 2'],
        ]), 'Calculado');
    }

    public function test_formula_column_without_source_is_v19(): void
    {
        $this->assertFormulaProblem('formula_without_source', $this->layoutWithFormulaColumns([
            ['formula_abc123', ''],
        ]), 'Calculado');
    }

    /**
     * Fiscal guarantee with no new code: a legal field turned into a formula is a legal
     * field that is no longer in the document, and V-10 says so.
     */
    public function test_formula_legal_field_replaced_by_formula_still_reports_v10(): void
    {
        $layout = $this->layoutWith(
            'documento.atcud',
            fn (array $block): array => [
                ...$block,
                'binding'          => 'formula.abc123',
                'legal'            => false,
                'field_attributes' => ['data-tpl-formula' => 'documento.numero'],
            ],
        );

        $this->assertHasProblem('V-10', $layout);
    }

    /** A referenced field the type never declared resolves through the host, like a placed one. */
    public function test_formula_reference_resolved_by_the_host_resolver(): void
    {
        $layout = $this->layoutPlus($this->formulaField('formula.abc123', 'adquirente.vat & "x"'));

        $this->assertFormulaProblem('formula_unknown_reference', $layout);

        $this->app->bind(BindingResolver::class, fn () => new class implements BindingResolver {
            public function resolve(string $key, TemplateType $type): ?array
            {
                return $key === 'adquirente.vat' ? [
                    'key' => $key, 'label' => 'VAT', 'type' => 'text',
                    'mandatory' => false, 'legal' => false, 'scope' => null, 'default_format' => '',
                ] : null;
            }
        });

        $this->assertSame([], $this->problems($layout));
    }

    /**
     * A model-backed collection declares no columns (the host's graph offers them), so a
     * referenced column of it is valid when the host resolver knows `<collection>.<column>`.
     */
    public function test_formula_column_of_a_model_backed_collection_resolves_through_the_host(): void
    {
        $base = Templates::type(self::TYPE_CODE);
        $type = new TemplateType(
            $base->code, $base->label, $base->output, $base->seeded, $base->layoutRevision,
            [...$base->bindings, [
                'key' => 'artigos', 'label' => 'Artigos', 'type' => 'collection', 'mandatory' => false,
                'legal' => false, 'scope' => null, 'default_format' => null, 'columns' => null,
            ]],
            $base->nodes, $base->fixture, $base->fixtureRepeats, $base->initialLayout, $base->pdf, [],
        );

        $layout   = $this->layoutPlus($this->formulaField('formula.abc123', 'SUM(artigos.preco)'));
        $validate = fn (): array => app(LayoutValidator::class)->validate($layout['project_data'], $type, $layout['html'], $layout['css']);

        $this->assertSame(['V-19'], array_column($validate(), 'code'));

        $this->app->bind(BindingResolver::class, fn () => new class implements BindingResolver {
            public function resolve(string $key, TemplateType $type): ?array
            {
                return $key === 'artigos.preco' ? [
                    'key' => $key, 'label' => 'Preço', 'type' => 'money',
                    'mandatory' => false, 'legal' => false, 'scope' => null, 'default_format' => '',
                ] : null;
            }
        });

        $this->assertSame([], $validate());
    }

    /**
     * What is validated is what prints: the compositor evaluates the sources in the
     * exported markup, so a payload whose markup carries another formula is refused.
     */
    public function test_formula_field_source_differing_from_the_export_is_blocked(): void
    {
        $layout = $this->layoutPlus($this->formulaField('formula.abc123', 'totais.total_a_pagar * 2'));

        $this->assertSame([], $this->problems($layout));

        $layout['html'] = str_replace('data-tpl-formula="totais.total_a_pagar * 2"', 'data-tpl-formula="TODAY()"', $layout['html'], $count);
        $this->assertSame(1, $count);

        $problems = $this->problems($layout);
        $this->assertSame(['V-01'], array_column($problems, 'code'));
        $this->assertSame('tpl-formula', $problems[0]['component_id']);
        $this->assertSame(__('templates::editor.validation.format_mismatch', ['format' => '']), $problems[0]['message']);
    }

    public function test_formula_field_only_in_the_export_is_blocked(): void
    {
        $layout         = $this->validLayout();
        $layout['html'] = str_replace('</body>', '<div data-tpl-type="data-field" data-tpl-binding="formula.abc123" data-tpl-formula="TODAY()">{{ formula.abc123 }}</div></body>', $layout['html']);

        $this->assertHasProblem('V-01', $layout);
    }

    public function test_formula_column_source_differing_from_the_export_is_blocked(): void
    {
        $layout = $this->layoutWithFormulaColumns([['formula_abc123', 'linhas.quantidade * 2']]);

        $this->assertSame([], $this->problems($layout));

        $layout['html'] = str_replace('linhas.quantidade * 2', 'TODAY()', $layout['html'], $count);
        $this->assertSame(1, $count);

        $problems = $this->problems($layout);
        $this->assertSame(['V-01'], array_column($problems, 'code'));
        $this->assertSame('tpl-lines', $problems[0]['component_id']);
    }

    /** Unreadable columns in the project data hide the formulas the markup still carries. */
    public function test_formula_malformed_columns_in_the_project_data_are_blocked(): void
    {
        $layout = $this->layoutWithFormulaColumns([['formula_abc123', 'linhas.quantidade * 2']]);

        array_walk_recursive($layout['project_data'], function (mixed &$value, string|int $attribute): void {
            if ($attribute === 'data-tpl-columns') {
                $value = '[{"key":';
            }
        });

        $problems = $this->problems($layout);
        $this->assertContains('V-01', array_column($problems, 'code'));
        $this->assertSame(['tpl-lines'], array_values(array_unique(array_column($problems, 'component_id'))));
    }

    /**
     * A catalogue column turned into a formula keeps its key and prints under `formulaKey`:
     * valid, and its source is checked as a row of the table's collection.
     */
    public function test_catalogue_column_formula_is_valid(): void
    {
        $layout = $this->layoutWithColumns([
            ['key' => 'descricao', 'label' => 'Descrição', 'width' => '', 'align' => 'left', 'format' => '', 'formula' => 'UPPER(linhas.descricao)', 'formulaKey' => 'formula_dsc12345'],
        ]);

        $this->assertStringContainsString('{{ linha.formula_dsc12345 }}', $layout['html']);
        $this->assertSame([], $this->problems($layout));

        $this->assertFormulaProblem('formula_unknown_reference', $this->layoutWithColumns([
            ['key' => 'descricao', 'label' => 'Descrição', 'width' => '', 'align' => 'left', 'format' => '', 'formula' => 'UPPER(linhas.inexistente)', 'formulaKey' => 'formula_dsc12345'],
        ]), 'Descrição');
    }

    /** `formulaKey` is printed as a Twig path, so it has the formula column key's shape. */
    public function test_formula_key_not_matching_pattern_is_v01(): void
    {
        $problems = $this->problems($this->layoutWithColumns([
            ['key' => 'descricao', 'label' => 'Descrição', 'width' => '', 'align' => 'left', 'format' => '', 'formula' => 'UPPER(linhas.descricao)', 'formulaKey' => 'formula_3b270w4p'],
        ]));

        $this->assertSame(['V-01'], array_column($problems, 'code'));
        $this->assertSame(__('templates::editor.validation.unknown_binding', ['binding' => 'formula_3b270w4p']), $problems[0]['message']);
    }

    /** A formula column keeps a key of its collection, or its own `formulaKey`; nothing else. */
    public function test_formula_on_unknown_catalogue_key_is_v01(): void
    {
        $problems = $this->problems($this->layoutWithColumns([
            ['key' => 'nope', 'label' => 'Nope', 'width' => '', 'align' => 'left', 'format' => '', 'formula' => 'UPPER(linhas.descricao)', 'formulaKey' => 'formula_dsc12345'],
        ]));

        $this->assertSame(['V-01'], array_column($problems, 'code'));
        $this->assertSame(__('templates::editor.validation.unknown_binding', ['binding' => 'nope']), $problems[0]['message']);

        // A calculated column whose key is its own formulaKey is no catalogue column.
        $this->assertSame([], $this->problems($this->layoutWithColumns([
            ['key' => 'formula_dsc12345', 'label' => 'Calculado', 'width' => '', 'align' => 'left', 'format' => '', 'formula' => 'UPPER(linhas.descricao)', 'formulaKey' => 'formula_dsc12345'],
        ])));
    }

    /**
     * A model-backed collection declares no columns: the catalogue column is one the host
     * resolves, and the table's own column list never vouches for itself.
     */
    public function test_catalogue_column_formula_of_a_model_backed_collection_resolves_through_the_host(): void
    {
        $base = Templates::type(self::TYPE_CODE);
        $type = new TemplateType(
            $base->code, $base->label, $base->output, $base->seeded, $base->layoutRevision,
            [...$base->bindings, [
                'key' => 'artigos', 'label' => 'Artigos', 'type' => 'collection', 'mandatory' => false,
                'legal' => false, 'scope' => null, 'default_format' => null, 'columns' => null,
            ]],
            $base->nodes, $base->fixture, $base->fixtureRepeats, $base->initialLayout, $base->pdf, [],
        );

        $layoutFor = function (string $key): array {
            $table = $this->extraTable('tpl-artigos', 'artigos');

            $table['attributes']['data-tpl-columns'] = json_encode([
                ['key' => $key, 'label' => 'Artigo', 'width' => '', 'align' => 'left', 'format' => '', 'formula' => 'UPPER(artigos.descricao)', 'formulaKey' => 'formula_art12345'],
            ]);

            return $this->layoutPlus($table);
        };
        $validate = fn (array $layout): array => app(LayoutValidator::class)->validate($layout['project_data'], $type, $layout['html'], $layout['css']);

        $this->app->bind(BindingResolver::class, fn () => new class implements BindingResolver {
            public function resolve(string $key, TemplateType $type): ?array
            {
                return $key === 'artigos.descricao' ? [
                    'key' => $key, 'label' => 'Descrição', 'type' => 'text',
                    'mandatory' => false, 'legal' => false, 'scope' => null, 'default_format' => '',
                ] : null;
            }
        });

        $this->assertSame([], $validate($layoutFor('descricao')));

        $problems = $validate($layoutFor('nope'));
        $this->assertSame(['V-01'], array_column($problems, 'code'));
        $this->assertSame(__('templates::editor.validation.unknown_binding', ['binding' => 'nope']), $problems[0]['message']);
    }

    /**
     * The shape saved before `formulaKey` existed still validates, and a catalogue key
     * carrying a formula without one is still refused: it would print the raw value.
     */
    public function test_legacy_formula_column_without_formula_key_still_valid(): void
    {
        $this->assertSame([], $this->problems($this->layoutWithColumns([
            ['key' => 'formula_abc123', 'label' => 'Calculado', 'width' => '', 'align' => 'right', 'format' => '', 'formula' => 'linhas.quantidade * 2'],
        ])));

        $problems = $this->problems($this->layoutWithColumns([
            ['key' => 'descricao', 'label' => 'Descrição', 'width' => '', 'align' => 'left', 'format' => '', 'formula' => 'UPPER(linhas.descricao)'],
        ]));

        $this->assertSame(['V-01'], array_column($problems, 'code'));
        $this->assertSame(__('templates::editor.validation.unknown_binding', ['binding' => 'descricao']), $problems[0]['message']);
    }

    /** One `formulaKey` per result, across every table: a copied column that kept it is V-19. */
    public function test_duplicate_formula_key_across_tables_is_v19(): void
    {
        $second = $this->extraTable('tpl-other', 'outra');

        $second['attributes']['data-tpl-columns'] = json_encode([
            ['key' => 'formula_dsc12345', 'label' => 'Outra', 'width' => '', 'align' => 'left', 'format' => '', 'formula' => '1 + 1', 'formulaKey' => 'formula_dsc12345'],
        ]);

        $layout = $this->layoutWithColumns([
            ['key' => 'descricao', 'label' => 'Descrição', 'width' => '', 'align' => 'left', 'format' => '', 'formula' => 'UPPER(linhas.descricao)', 'formulaKey' => 'formula_dsc12345'],
        ], [$second]);

        $this->assertFormulaProblem('formula_duplicate_key', $layout, 'Outra');
    }

    /** A table bound to no collection has no rows: the compositor would print the column empty. */
    public function test_formula_column_in_a_table_without_collection_is_v19_scope(): void
    {
        $layout = $this->layoutPlus([
            'type'       => 'line-table',
            'zone'       => 'body',
            'id'         => 'tpl-unbound',
            'attributes' => ['data-tpl-columns' => json_encode([
                ['key' => 'formula_abc123', 'label' => 'Calculado', 'width' => '', 'align' => 'right', 'format' => '', 'formula' => '1 + 1'],
            ])],
            'style' => ['position' => 'absolute', 'left' => '0mm', 'top' => '50mm', 'width' => '180mm'],
        ]);

        $this->assertFormulaProblem('formula_scope', $layout, 'Calculado');
    }

    /** The formula's descriptor reaches the occlusion check too: a covered formula is warned about. */
    public function test_formula_field_covered_by_a_block_is_a_v18_warning(): void
    {
        $field = $this->formulaField('formula.abc123', 'totais.total_a_pagar * 2');

        $field['style'] += ['width' => '40mm', 'height' => '4mm'];

        $layout = $this->layout(array_merge($this->defaultBlocks(self::TYPE_CODE), [
            $field,
            $this->rectangle(['left' => '0mm', 'top' => '47mm', 'width' => '60mm', 'height' => '6mm'], '#000000'),
        ]));

        $warned = array_values(array_filter($this->occlusions($layout), fn (array $o): bool => $o['code'] === 'V-18'));

        $this->assertCount(1, $warned);
        $this->assertSame('tpl-formula', $warned[0]['component_id']);
    }

    /**
     * The default blocks without the one bound to `$binding`.
     *
     * @return list<array<string, mixed>>
     */
    private function blocksWithout(string $binding): array
    {
        return array_values(array_filter(
            $this->defaultBlocks(self::TYPE_CODE),
            fn (array $block): bool => $this->bindingOf($block) !== $binding,
        ));
    }

    /**
     * The default layout with the block bound to `$binding` swapped for another.
     *
     * @param  array<string, mixed>  $block
     * @return array{project_data: array<string, mixed>, html: string, css: string}
     */
    private function layoutReplacing(string $binding, array $block): array
    {
        return $this->layout([...$this->blocksWithout($binding), $block]);
    }

    /**
     * A data field bound to a formula key, in the header below the default grid.
     *
     * @return array<string, mixed>
     */
    private function formulaField(string $key, string $source, string $id = 'tpl-formula'): array
    {
        return [
            'binding'          => $key,
            'format'           => '',
            'zone'             => 'header',
            'id'               => $id,
            'field_attributes' => ['data-tpl-formula' => $source],
            'style'            => [
                'position'  => 'absolute',
                'left'      => '0mm',
                'top'       => '48mm',
                'font-size' => '8pt',
                'color'     => 'rgb(0, 0, 0)',
            ],
        ];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $fields  key and source of each formula field
     * @return array{project_data: array<string, mixed>, html: string, css: string}
     */
    private function layoutWithFormulaFields(array $fields): array
    {
        $blocks = $this->defaultBlocks(self::TYPE_CODE);

        foreach ($fields as $index => [$key, $source]) {
            $blocks[] = $this->formulaField($key, $source, 'tpl-formula-'.$index);
        }

        return $this->layout($blocks);
    }

    /**
     * The valid layout with formula columns appended to its line table.
     *
     * @param  list<array{0: string, 1: string}>  $columns  key and source of each formula column
     * @return array{project_data: array<string, mixed>, html: string, css: string}
     */
    private function layoutWithFormulaColumns(array $columns): array
    {
        $blocks = $this->defaultBlocks(self::TYPE_CODE);

        foreach ($blocks as &$block) {
            if (($block['type'] ?? null) !== 'line-table') {
                continue;
            }

            $declared = json_decode($block['attributes']['data-tpl-columns'], true);

            foreach ($columns as [$key, $source]) {
                $declared[] = ['key' => $key, 'label' => 'Calculado', 'width' => '', 'align' => 'right', 'format' => '', 'formula' => $source];
            }

            $block['attributes']['data-tpl-columns'] = json_encode($declared);
        }

        unset($block);

        return $this->layout($blocks);
    }

    /**
     * The valid layout with columns of its line table replaced by key (or appended), plus
     * any extra blocks.
     *
     * @param  list<array<string, mixed>>  $columns
     * @param  list<array<string, mixed>>  $extra
     * @return array{project_data: array<string, mixed>, html: string, css: string}
     */
    private function layoutWithColumns(array $columns, array $extra = []): array
    {
        $blocks = $this->defaultBlocks(self::TYPE_CODE);

        foreach ($blocks as &$block) {
            if (($block['type'] ?? null) !== 'line-table') {
                continue;
            }

            $declared = json_decode($block['attributes']['data-tpl-columns'], true);

            foreach ($columns as $column) {
                $index = array_search($column['key'], array_column($declared, 'key'), true);

                if ($index === false) {
                    $declared[] = $column;
                } else {
                    $declared[$index] = $column;
                }
            }

            $block['attributes']['data-tpl-columns'] = json_encode($declared);
        }

        unset($block);

        return $this->layout([...$blocks, ...$extra]);
    }

    /**
     * @param  array{project_data: array<string, mixed>, html: string, css: string}  $layout
     */
    private function assertFormulaProblem(string $reason, array $layout, ?string $label = null): void
    {
        $this->assertHasProblem('V-19', $layout);

        $messages = array_column(array_filter(
            $this->problems($layout),
            fn (array $problem): bool => $problem['code'] === 'V-19',
        ), 'message');

        $this->assertContains($this->formulaMessage($reason, $label), $messages, 'Got: '.implode(' | ', $messages));
    }

    /** A field is named by the generic label, a column by its own header. */
    private function formulaMessage(string $reason, ?string $label = null): string
    {
        return __('templates::editor.validation.'.$reason, ['formula' => $label ?? __('templates::editor.formula.label')]);
    }

    /* -----------------------------------------------------------------------
     * Helpers
     * -------------------------------------------------------------------- */

    /**
     * @param  array{project_data: array<string, mixed>, html: string, css: string}  $layout
     * @return array<int, array{code: string, component_id: string, message: string}>
     */
    private function problems(array $layout): array
    {
        return app(LayoutValidator::class)->validate(
            $layout['project_data'],
            Templates::type(self::TYPE_CODE),
            $layout['html'],
            $layout['css'],
        );
    }

    /**
     * @param  array{project_data: array<string, mixed>, html: string, css: string}  $layout
     */
    private function assertHasProblem(string $code, array $layout): void
    {
        $problems = $this->problems($layout);
        $codes    = array_column($problems, 'code');

        $this->assertContains(
            $code,
            $codes,
            "Expected {$code}. Got: ".($codes === [] ? 'nothing' : implode(', ', $codes)),
        );

        foreach ($problems as $problem) {
            $this->assertNotSame('', $problem['message'], "{$problem['code']} has no message.");
            $this->assertStringNotContainsString('templates::', $problem['message'], 'Untranslated message.');
        }
    }

    /**
     * A sized legal field with a rectangle over it, later in the document.
     *
     * Both boxes have to be fully stated for occlusion to be decidable without
     * rendering — which is exactly the limit of the static rule.
     *
     * @return array{project_data: array<string, mixed>, html: string, css: string}
     */
    private function layoutWithRectangleOverTheIssuerVatNumber(string $background, string $left = '90mm'): array
    {
        $blocks = array_map(
            function (array $block): array {
                if (($block['binding'] ?? null) === 'emitente.nif') {
                    $block['style']['width']  = '40mm';
                    $block['style']['height'] = '4mm';
                }

                return $block;
            },
            $this->defaultBlocks(self::TYPE_CODE),
        );

        return $this->layout(array_merge($blocks, [[
            'type'  => 'rule',
            'zone'  => 'header',
            'id'    => 'tpl-cover',
            'style' => [
                'position'         => 'absolute',
                'left'             => $left,
                'top'              => '0mm',
                'width'            => '60mm',
                'height'           => '6mm',
                'background-color' => $background,
            ],
        ]]));
    }

    /**
     * The default layout with one block restyled (and optionally moved to another zone)
     * and a covering block appended after it, so the cover is later in document order.
     *
     * @param  array<string, mixed>  $cover
     * @param  array<string, string>  $fieldStyle
     * @return array{project_data: array<string, mixed>, html: string, css: string}
     */
    private function layoutWithCover(string $binding, array $cover, array $fieldStyle = [], ?string $zone = null): array
    {
        $blocks = array_map(
            function (array $block) use ($binding, $fieldStyle, $zone): array {
                if ($this->bindingOf($block) !== $binding) {
                    return $block;
                }

                $block['style'] = array_merge($block['style'] ?? [], $fieldStyle);

                return $zone === null ? $block : [...$block, 'zone' => $zone];
            },
            $this->defaultBlocks(self::TYPE_CODE),
        );

        return $this->layout(array_merge($blocks, [$cover]));
    }

    /**
     * An image block with a real (opaque) file unless another source is given.
     *
     * @param  array<string, string>  $style
     * @return array<string, mixed>
     */
    private function image(array $style, string $zone = 'header', ?string $source = null): array
    {
        return [
            'type'       => 'image',
            'zone'       => $zone,
            'id'         => 'tpl-cover',
            'attributes' => ['src' => $source ?? 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='],
            'style'      => ['position' => 'absolute'] + $style,
        ];
    }

    /**
     * A static text block, appended after the default ones so it paints on top.
     *
     * @param  array<string, string>  $style
     * @return array<string, mixed>
     */
    private function text(array $style, string $zone = 'header'): array
    {
        return [
            'type'  => 'static-text',
            'zone'  => $zone,
            'id'    => 'tpl-cover',
            'style' => ['position' => 'absolute'] + $style,
        ];
    }

    /**
     * @param  array<string, string>  $style
     * @return array<string, mixed>
     */
    private function rectangle(array $style, string $background, string $zone = 'header'): array
    {
        return [
            'type'  => 'rule',
            'zone'  => $zone,
            'id'    => 'tpl-cover',
            'style' => ['position' => 'absolute', 'background-color' => $background] + $style,
        ];
    }

    /**
     * @param  array{project_data: array<string, mixed>, html: string, css: string}  $layout
     * @return array<int, array{code: string, component_id: string, message: string}>
     */
    private function occlusions(array $layout): array
    {
        return app(LayoutValidator::class)->occlusions($layout['project_data'], Templates::type(self::TYPE_CODE));
    }

    /** The invoice type laid out on an 80mm roll. */
    private function rollType(): TemplateType
    {
        $base = Templates::type(self::TYPE_CODE);

        return new TemplateType(
            $base->code, $base->label, $base->output, $base->seeded, $base->layoutRevision,
            $base->bindings, $base->nodes, $base->fixture, $base->fixtureRepeats,
            $base->initialLayout,
            ['medium' => 'roll', 'width_mm' => 80, 'fit' => 'measure', 'max_height_mm' => 4000,
                'margins' => ['top' => 3, 'right' => 3, 'bottom' => 3, 'left' => 3]],
            [],
        );
    }

}
