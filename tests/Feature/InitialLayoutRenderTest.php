<?php

namespace Controlink\Templates\Tests\Feature;

use Controlink\Templates\Contracts\MoneyFormatter;
use Controlink\Templates\Data\FixtureDataset;
use Controlink\Templates\Output\Pdf\DocumentComposer;
use Controlink\Templates\Output\Pdf\PdfRenderer;
use Controlink\Templates\Output\Pdf\RenderValidator;
use Controlink\Templates\Output\Pdf\RenderedPdf;
use Controlink\Templates\Templates;
use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Types\TemplateType;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\ExecutableFinder;

/**
 * The starting layout is activatable.
 *
 * Every other test on the initial layout stops at the composed document. This one takes
 * it the rest of the way — through the engine and back — because that is where the only
 * question that matters is answered: can a host declare a document type and put its
 * catalogue layout into production without having to redraw it first.
 *
 * Ported from the host's original initial-layout render test (pre-extraction), against
 * `Templates::type()` instead of the raw config array it read directly.
 *
 * Needs the real engine and poppler, and skips itself without either: a developer with
 * no container running, or with poppler-utils absent, should not be blocked, and a
 * machine with both gets the check. This test genuinely needs a real rendered PDF to
 * measure — a faked engine response would either not be a valid PDF at all, or would be
 * a fixed document unrelated to the initial layout under test, so the render call is
 * left real rather than faked with `Http::fake()`.
 */
class InitialLayoutRenderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $finder = new ExecutableFinder;

        foreach (['pdfinfo', 'pdftotext', 'pdftoppm'] as $tool) {
            if (! $finder->find($tool)) {
                $this->markTestSkipped('poppler-utils not installed');
            }
        }
    }

    public function test_the_initial_layout_survives_the_engine_and_the_render_rules(): void
    {
        Http::preventStrayRequests(false);

        if (! $this->engineIsReachable()) {
            $this->markTestSkipped('The PDF engine is not reachable at '.config('templates.pdf.gotenberg.url').'.');
        }

        // No tenant here, so the money formatter has no licence to ask for its main
        // currency. The stand-in only has to produce a plausible string of the right
        // length: what is being measured is whether the value fits its box.
        $this->app->instance(MoneyFormatter::class, new class implements MoneyFormatter
        {
            public function format(float|string|null $value): string
            {
                return number_format((float) $value, 2, ',', ' ').' €';
            }
        });

        $type    = Templates::type('invoice');
        $dataset = app(FixtureDataset::class)->for($type);

        $document = app(DocumentComposer::class)->compose(
            $type->initialLayout['html'],
            $type->initialLayout['css'],
            $type,
            $dataset,
        );

        $started  = microtime(true);
        $rendered = app(PdfRenderer::class)->render($document, $type);
        $seconds  = microtime(true) - $started;

        /*
         * An empty problem list is only good news if something was actually rendered
         * and actually looked at. A blank PDF passes every render rule that works by
         * looking for a value on the page, so the shape of the render is asserted
         * first: the fixture repeats its rows until the document spills over several
         * sheets, and the QR is one of the things being checked.
         */
        RenderedPdf::open($rendered['pdf'], function (RenderedPdf $pdf) use ($type): void {
            $this->assertGreaterThan(1, $pdf->pageCount(), 'The fixture no longer paginates.');
            $this->assertLessThanOrEqual((int) ($type->pdf['max_pages'] ?? 0), $pdf->pageCount());
            $this->assertNotEmpty($pdf->wordsOn(1), 'The first page carries no text.');
        });

        $this->assertNotEmpty($this->codeSlotBoxes($type), 'No code slot was found to crop.');

        $problems = app(RenderValidator::class)->validate(
            $rendered['pdf'],
            $type,
            $dataset,
            $seconds,
            $this->codeSlotBoxes($type),
        );

        $this->assertSame(
            [],
            array_map(
                fn (array $problem): string => $problem['code'].' '.$problem['message'],
                $problems,
            ),
            'The initial layout renders, but the render rules refuse it.',
        );
    }

    /**
     * Where each code sits on the page, in millimetres.
     *
     * Normally `LayoutValidator::codeSlotBoxes()` reads this off `project_data`, but a
     * catalogue layout has none: `project_data` is what the editor writes on the first
     * save, and this test deliberately never opens one. So the box is derived from the
     * same numbers the stylesheet uses — the page, the margins, the zone band and the
     * slot's own side.
     *
     * @return array<string, array{left: float, top: float, width: float, height: float}>
     */
    private function codeSlotBoxes(TemplateType $type): array
    {
        $margins = $type->pdf['margins'] ?? [];
        $footer  = (float) ($type->initialLayout['zones']['footer_height'] ?? 0);
        $css     = $type->initialLayout['css'];
        $boxes   = [];

        preg_match_all(
            '/<img[^>]*data-tpl-type="code-slot"[^>]*>/',
            $type->initialLayout['html'],
            $slots,
        );

        foreach ($slots[0] as $slot) {
            if (preg_match('/data-tpl-binding="([^"]+)"/', $slot, $binding) !== 1) {
                continue;
            }

            if (preg_match('/width\s*:\s*([\d.]+)mm/', $slot, $width) !== 1) {
                continue;
            }

            $side = (float) $width[1];
            $id   = preg_match('/id="([^"]+)"/', $slot, $found) === 1 ? $found[1] : '';

            preg_match('/#'.preg_quote($id, '/').'\{([^}]*)\}/', $css, $rule);
            $declarations = $rule[1] ?? '';

            $fromTop = preg_match('/top\s*:\s*([\d.]+)mm/', $declarations, $top) === 1
                ? (float) $top[1]
                : 0.0;

            // `right:0` in the initial layout: the slot is flush with the right margin.
            $left    = 210.0 - (float) ($margins['right'] ?? 0) - $side;
            $bandTop = 297.0 - (float) ($margins['bottom'] ?? 0) - $footer;

            $boxes[$binding[1]] = [
                'left'   => $left,
                'top'    => $bandTop + $fromTop,
                'width'  => $side,
                'height' => $side,
            ];
        }

        return $boxes;
    }

    private function engineIsReachable(): bool
    {
        try {
            return Http::timeout(3)
                ->get(rtrim((string) config('templates.pdf.gotenberg.url'), '/').'/health')
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
