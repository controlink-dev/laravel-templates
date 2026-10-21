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

    public function test_text_over_text_does_not_occlude(): void
    {
        $layout = $this->layoutWithCover(
            'emitente.nif',
            [
                'type'  => 'static-text',
                'zone'  => 'header',
                'id'    => 'tpl-cover',
                'style' => ['position' => 'absolute', 'left' => '90mm', 'top' => '0mm', 'width' => '60mm', 'height' => '6mm'],
            ],
            ['width' => '40mm', 'height' => '4mm'],
        );

        $this->assertNotContains('V-15', array_column($this->problems($layout), 'code'));
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
