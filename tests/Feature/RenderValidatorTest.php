<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Output\Pdf\CodeReader;
use Controlink\Templates\Output\Pdf\RenderValidator;
use Controlink\Templates\Rendering\FormatSpec;
use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Types\TemplateType;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Layer 2 of the validation: the rules that read the rendered PDF (spec 8.2).
 *
 * Every case here runs against a **stored** PDF, not against one this code path
 * produces, and that is deliberate. Several of these defects are things the compositor
 * now makes impossible — since pagination happens server-side, an `every_page` field
 * cannot be missing from a page. Testing the rule against the current code path would
 * only prove the current code path is fine; testing it against a document that already
 * went wrong proves the *rule* works, and keeps proving it after the compositor changes.
 *
 * The fixtures and what each one is defective in are described in
 * tests/Fixtures/pdf/README.md.
 *
 * R-01 and R-07 have no fixture: one is an exception thrown while composing and the
 * other is a measured number, and neither is a property of a PDF.
 */
class RenderValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // `RenderedPdf` shells out to three poppler tools (`pdfinfo`, `pdftotext`,
        // `pdftoppm`); checking `pdftotext` alone is not enough; a distribution can
        // carry an unrelated `pdftotext` on PATH (Git for Windows does) while the
        // other two are absent, which would turn every case here into an error
        // instead of the skip a missing dependency should produce.
        $finder = new ExecutableFinder;

        foreach (['pdfinfo', 'pdftotext', 'pdftoppm'] as $tool) {
            if (! $finder->find($tool)) {
                $this->markTestSkipped('poppler-utils not installed');
            }
        }
    }

    /** A template type carrying only the fields the fixtures actually print. */
    private function type(array $pdfOverrides = [], ?array $bindings = null): TemplateType
    {
        $config = [
            'output' => 'pdf',
            'nodes'  => [
                'documento' => ['model' => null],
            ],
            'bindings' => $bindings ?? [
                ['key' => 'documento.atcud', 'label' => 'ATCUD', 'type' => 'text', 'mandatory' => true, 'legal' => true, 'scope' => 'every_page'],
                ['key' => 'documento.qr', 'label' => 'Código QR', 'type' => 'qr', 'mandatory' => true, 'legal' => true, 'scope' => 'any_page'],
            ],
            'pdf' => array_merge([
                'paper_format' => 'A4',
                'margins'      => ['top' => 15.0, 'right' => 15.0, 'bottom' => 15.0, 'left' => 15.0],
                'max_pages'    => 10,
            ], $pdfOverrides),
        ];

        return TemplateType::fromConfig('invoice', $config);
    }

    /**
     * @return array<string, mixed>
     */
    private function dataset(): array
    {
        return [
            'documento.atcud' => 'JFT7-0000000001',
            'documento.qr'    => 'A:999999999*B:888888888*C:PT*D:FR*E:N*F:20261231*G:FR 2026/0000000001*H:JFT7-0000000001',
        ];
    }

    /** Where the code sits on the fixture pages. */
    private function codeSlots(): array
    {
        return ['documento.qr' => ['left' => 165.0, 'top' => 250.0, 'width' => 30.0, 'height' => 30.0]];
    }

    /**
     * @return array<int, array{code: string, page: int|null, message: string}>
     */
    private function validate(string $fixture, ?TemplateType $type = null, float $seconds = 1.0, ?array $codeSlots = null): array
    {
        $pdf = file_get_contents(__DIR__.'/../Fixtures/pdf/'.$fixture.'.pdf');

        $this->assertNotFalse($pdf, "Missing fixture: {$fixture}.pdf");

        return app(RenderValidator::class)->validate(
            $pdf,
            $type ?? $this->type(),
            $this->dataset(),
            $seconds,
            $codeSlots ?? $this->codeSlots(),
        );
    }

    private function codes(array $problems): array
    {
        return array_values(array_unique(array_column($problems, 'code')));
    }

    /* -----------------------------------------------------------------------
     * The control
     * -------------------------------------------------------------------- */

    /**
     * Without this one, every case below could be passing because the validator finds
     * nothing in a PDF.
     */
    public function test_a_correct_document_produces_no_problems(): void
    {
        if (! app(CodeReader::class)->isAvailable()) {
            $this->markTestSkipped('The QR reader is not available in this installation.');
        }

        $this->assertSame([], $this->validate('r-ok'));
    }

    /* -----------------------------------------------------------------------
     * The rules
     * -------------------------------------------------------------------- */

    /** R-02: a mandatory value that is nowhere in the document. */
    public function test_r02_catches_a_value_missing_from_the_whole_document(): void
    {
        $this->assertContains('R-02', $this->codes($this->validate('r02-value-missing')));
    }

    /**
     * R-03: present on one page, absent from another — and the failure has to name the
     * page, because "the ATCUD is missing" without one is not actionable.
     */
    public function test_r03_names_the_page_a_required_value_is_missing_from(): void
    {
        $problems = $this->validate('r03-missing-on-page');
        $r03      = array_values(array_filter($problems, fn (array $p): bool => $p['code'] === 'R-03'));

        $this->assertNotEmpty($r03);
        $this->assertSame(2, $r03[0]['page']);
        $this->assertStringContainsString('2', $r03[0]['message']);
    }

    /**
     * R-04, truncation: the value the fixture printed is clipped, so the extracted text
     * is not the value.
     *
     * This is the check layer 1 deliberately refuses to attempt. A static estimate of
     * whether text fits needs font metrics and a resolved box, and being wrong in either
     * direction is worse than not guessing — here the text has a measured box and there
     * is nothing to estimate.
     */
    public function test_r04_catches_a_truncated_legal_value(): void
    {
        $problems = $this->validate('r04-truncated');

        // A clipped value is not "present", so it surfaces as the presence failure —
        // which is the right answer: what is on the page is not the value.
        $this->assertNotSame([], $problems);
        $this->assertNotEmpty(array_filter(
            $problems,
            fn (array $p): bool => in_array($p['code'], ['R-02', 'R-03', 'R-04'], true),
        ));
    }

    /** R-04, geometry: inside the printable area, measured from the PDF. */
    public function test_r04_catches_a_value_outside_the_printable_area(): void
    {
        $problems = $this->validate('r04-outside', $this->type(bindings: [
            // One page in this fixture, so the every-page rule is not what fires.
            ['key' => 'documento.atcud', 'label' => 'ATCUD', 'type' => 'text', 'mandatory' => true, 'legal' => true, 'scope' => 'any_page'],
        ]));

        $r04 = array_values(array_filter($problems, fn (array $p): bool => $p['code'] === 'R-04'));

        $this->assertNotEmpty($r04, 'Expected R-04. Got: '.implode(', ', $this->codes($problems)));
        $this->assertSame(1, $r04[0]['page']);
    }

    /** R-06: a document longer than its type allows is a runaway layout. */
    public function test_r06_catches_too_many_pages(): void
    {
        $problems = $this->validate('r06-too-many-pages', $this->type(['max_pages' => 3]));

        $this->assertContains('R-06', $this->codes($problems));
    }

    /**
     * R-08 render gate (CGE-25 Peça 1): a page far larger than the geometry asked for is
     * reported as a size mismatch and the per-binding checks are NOT run against it.
     *
     * Rasterising a metres-long page to read its codes is where the 30s timeout happened,
     * so the content is never measured once the page size is already wrong. The absence of
     * R-02 (the mandatory ATCUD this blank page does not carry) proves the early return: a
     * validator that reached the binding loop would report the value as missing.
     */
    public function test_an_oversized_page_is_refused_before_its_content_is_measured(): void
    {
        $problems = $this->validate('r08-oversized', $this->type(bindings: [
            ['key' => 'documento.atcud', 'label' => 'ATCUD', 'type' => 'text', 'mandatory' => true, 'legal' => true, 'scope' => 'any_page'],
        ]));

        $codes = $this->codes($problems);

        $this->assertContains('R-08', $codes, 'The oversized page should be reported as a size mismatch.');
        $this->assertNotContains('R-02', $codes, 'An oversized page must not be measured for its values; the render gate returns first.');
    }

    /** R-07: the time budget, checked before the PDF is even opened. */
    public function test_r07_catches_a_render_over_its_budget(): void
    {
        $type = $this->type(['render_time_limit_seconds' => 2.0]);

        $this->assertContains('R-07', $this->codes($this->validate('r-ok', $type, seconds: 9.5)));
        $this->assertNotContains('R-07', $this->codes($this->validate('r-ok', $type, seconds: 1.5)));
    }

    /* -----------------------------------------------------------------------
     * R-05: the code has to be readable, and to say the right thing
     * -------------------------------------------------------------------- */

    public function test_r05_catches_a_missing_code(): void
    {
        if (! app(CodeReader::class)->isAvailable()) {
            $this->markTestSkipped('The QR reader is not available in this installation.');
        }

        $this->assertContains('R-05', $this->codes($this->validate('r05-code-missing')));
    }

    /**
     * A code that decodes to something else is worse than one that does not decode: it
     * points the reader at the wrong document.
     */
    public function test_r05_catches_a_code_with_the_wrong_content(): void
    {
        if (! app(CodeReader::class)->isAvailable()) {
            $this->markTestSkipped('The QR reader is not available in this installation.');
        }

        $problems = $this->validate('r05-code-wrong');
        $r05      = array_values(array_filter($problems, fn (array $p): bool => $p['code'] === 'R-05'));

        $this->assertNotEmpty($r05);
        $this->assertSame(1, $r05[0]['page']);
    }

    /**
     * R-05 is not a size check, and this test exists to hold that line.
     *
     * A 6mm code decodes perfectly from a 300dpi raster — a software decoder is not a
     * phone camera at arm's length. Proving the code is *correct* is this rule's job;
     * proving it is *big enough* is V-17's, in the static pass. Neither substitutes for
     * the other, and reading this test as a bug would be reading it backwards.
     */
    public function test_r05_does_not_reject_a_code_that_is_merely_small(): void
    {
        if (! app(CodeReader::class)->isAvailable()) {
            $this->markTestSkipped('The QR reader is not available in this installation.');
        }

        $this->assertNotContains('R-05', $this->codes($this->validate('r05-code-tiny')));
    }

    /**
     * A check that cannot run has not passed.
     *
     * If the reader is missing from an installation, R-05 fails rather than staying
     * quiet — otherwise a deployment without the library would activate every layout
     * without ever looking at a code.
     */
    public function test_r05_fails_when_the_reader_is_unavailable(): void
    {
        $this->app->instance(CodeReader::class, new class extends CodeReader
        {
            public function isAvailable(): bool
            {
                return false;
            }
        });

        $problems = $this->validate('r-ok');

        $this->assertContains('R-05', $this->codes($problems));
    }

    /**
     * A fixed-text code slot carries no binding at all, so it is checked through the
     * same R-05 machinery keyed by its own component id instead — the box comes from
     * `LayoutValidator::codeSlotBoxes()` exactly as a dynamic slot's does, and the
     * expected content comes from `CodePayload::fromAttributes()` in place of the
     * dataset.
     */
    public function test_r05_checks_a_fixed_text_code_by_its_component_id(): void
    {
        if (! app(CodeReader::class)->isAvailable()) {
            $this->markTestSkipped('The QR reader is not available in this installation.');
        }

        // No bindings: the fixture's QR is checked purely as a fixed-text slot here,
        // isolating the new pass from the binding-based one already covered above.
        $problems = $this->validate('r-ok', $this->type(bindings: []), codeSlots: [
            'tpl-fixed-slot' => [
                'left'       => 165.0, 'top' => 250.0, 'width' => 30.0, 'height' => 30.0,
                'attributes' => [
                    'data-tpl-code-source' => 'fixed',
                    'data-tpl-code-type'   => 'text',
                    'data-tpl-code-text'   => $this->dataset()['documento.qr'],
                ],
            ],
        ]);

        $this->assertSame([], $problems);
    }

    /** A fixed-text code that decodes to something other than its own declared content fails, the same way a mismatched dynamic one does. */
    public function test_r05_catches_a_fixed_text_code_with_the_wrong_content(): void
    {
        if (! app(CodeReader::class)->isAvailable()) {
            $this->markTestSkipped('The QR reader is not available in this installation.');
        }

        $problems = $this->validate('r05-code-wrong', $this->type(bindings: []), codeSlots: [
            'tpl-fixed-slot' => [
                'left'       => 165.0, 'top' => 250.0, 'width' => 30.0, 'height' => 30.0,
                'attributes' => [
                    'data-tpl-code-source' => 'fixed',
                    'data-tpl-code-type'   => 'text',
                    'data-tpl-code-text'   => 'This is not what the embedded code decodes to',
                ],
            ],
        ]);

        $this->assertContains('R-05', $this->codes($problems));
    }

    /* -----------------------------------------------------------------------
     * R-02 against the formats actually placed (CGE-49)
     * -------------------------------------------------------------------- */

    /**
     * The fixture prints the ATCUD in capitals. With the dataset in lower case, the
     * binding's own (empty) default format expects lower case and R-02 fires; the field
     * placed with `upper` is what the PDF really carries, so it does not.
     *
     * @param  array<string, list<FormatSpec|null>>  $placed
     */
    private function validateLowercase(array $placed, bool $legal = false): array
    {
        $pdf = (string) file_get_contents(__DIR__.'/../Fixtures/pdf/r-ok.pdf');

        return app(RenderValidator::class)->validate(
            $pdf,
            $this->type(bindings: [
                ['key' => 'documento.atcud', 'label' => 'ATCUD', 'type' => 'text', 'mandatory' => true, 'legal' => $legal],
            ]),
            ['documento.atcud' => 'jft7-0000000001'],
            1.0,
            [],
            $placed,
        );
    }

    public function test_r02_without_placed_formats_uses_the_binding_default(): void
    {
        $this->assertContains('R-02', $this->codes($this->validateLowercase([])));
    }

    public function test_r02_uses_placed_field_format(): void
    {
        $upper = FormatSpec::fromAttributes(['data-tpl-format' => 'upper']);

        $this->assertNotContains('R-02', $this->codes($this->validateLowercase(['documento.atcud' => [$upper]])));
    }

    public function test_r02_any_placed_format_suffices(): void
    {
        $upper = FormatSpec::fromAttributes(['data-tpl-format' => 'upper']);

        $this->assertNotContains('R-02', $this->codes($this->validateLowercase(['documento.atcud' => [null, $upper]])));
        $this->assertContains('R-02', $this->codes($this->validateLowercase(['documento.atcud' => [null]])));
    }

    /**
     * CGE-69: a field placed only inside a formula has no text of its own to look for
     * (the formula may wrap or reformat it), so R-02 has nothing to check. A legal
     * field never counts as placed by a formula, so its value is still looked for.
     */
    public function test_r02_skips_a_field_placed_only_through_a_formula(): void
    {
        $this->assertNotContains('R-02', $this->codes($this->validateLowercase(['documento.atcud' => []])));
        $this->assertContains('R-02', $this->codes($this->validateLowercase(['documento.atcud' => []], legal: true)));
    }
}
