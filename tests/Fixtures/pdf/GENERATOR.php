<?php

use App\Services\PrintForms\CodeImageGenerator;
use App\Services\PrintForms\PdfRenderer;
use Illuminate\Contracts\Console\Kernel;

/**
 * Deliberately defective PDFs, one per render rule (RNF-05).
 *
 * Hand-built HTML rather than layouts the editor could produce: the point is a document
 * that already went wrong, and several of these are things the compositor now makes
 * impossible — which is exactly why the check has to be tested against a stored
 * artefact instead of against the current code path.
 */
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
app()->setLocale('pt_PT');

$out  = __DIR__;
$font = base64_encode(file_get_contents('/usr/share/fonts/liberation-sans-fonts/LiberationSans-Regular.ttf'));
$face = '@font-face{font-family:"CGestSans";font-style:normal;font-weight:400;'
    ."src:url(data:font/ttf;base64,{$font}) format(\"truetype\")}";

$qr = (new CodeImageGenerator)->generate(
    'qr',
    'A:999999999*B:888888888*C:PT*D:FR*E:N*F:20261231*G:FR 2026/0000000001*H:JFT7-0000000001',
    30.0,
);
$wrongQr = (new CodeImageGenerator)->generate('qr', 'CONTEUDO-ERRADO', 30.0);

$css = <<<'CSS'
@page{size:A4;margin:0}
html,body{margin:0;padding:0;background:#fff}
.page{position:relative;width:210mm;height:297mm;overflow:hidden;box-sizing:border-box;
      font-family:"CGestSans",sans-serif;color:#000;break-after:page}
.page:last-of-type{break-after:auto}
.b{position:absolute;white-space:nowrap}
.clip{overflow:hidden}
CSS;

/** One page of a plausible invoice; $omit drops a field, $extra adds markup. */
$page = function (array $options = []) use ($qr): string {
    $atcud  = $options['atcud'] ?? 'JFT7-0000000001';
    $blocks = '';

    if (! ($options['omit_atcud'] ?? false)) {
        $style = $options['atcud_style'] ?? 'left:15mm;top:20mm;font-size:8pt';
        $class = ($options['clip_atcud'] ?? false) ? 'b clip' : 'b';
        $blocks .= "<div class=\"{$class}\" style=\"{$style}\">{$atcud}</div>";
    }

    $blocks .= '<div class="b" style="left:15mm;top:12mm;font-size:14pt;font-weight:700">Fatura-recibo</div>';
    $blocks .= '<div class="b" style="left:15mm;top:30mm;font-size:8pt">999999999</div>';
    $blocks .= '<div class="b" style="left:15mm;top:36mm;font-size:8pt">1 229 999,98 &euro;</div>';
    $blocks .= '<div class="b" style="left:15mm;top:270mm;font-size:7pt">Processado por programa certificado n.º 9999/AT</div>';

    // array_key_exists, not `??`: the coalescing operator treats an explicit null as
    // absent, so "no code at all" silently produced a document with the code.
    $code = array_key_exists('qr', $options) ? $options['qr'] : $qr;

    if ($code !== null) {
        $size = $options['qr_size'] ?? '30mm';
        $blocks .= "<img class=\"b\" style=\"left:165mm;top:250mm;width:{$size};height:{$size}\" src=\"{$code}\" alt=\"\">";
    }

    return '<div class="page">'.$blocks.($options['extra'] ?? '').'</div>';
};

$render = function (string $name, string $body) use ($out, $face, $css): void {
    $html = '<!doctype html><html lang="pt-PT"><head><meta charset="utf-8">'
        ."<style>{$face}</style><style>{$css}</style></head><body>{$body}</body></html>";
    file_put_contents("{$out}/{$name}.html", $html);

    $rendered = app(PdfRenderer::class)->render($html);
    file_put_contents("{$out}/{$name}.pdf", $rendered['pdf']);

    printf("%-28s %6d bytes\n", $name.'.pdf', strlen($rendered['pdf']));
};

// The control: everything where it should be.
$render('r-ok', $page().$page());

// R-02: a mandatory value absent from the whole document.
$render('r02-value-missing', $page(['omit_atcud' => true]).$page(['omit_atcud' => true]));

// R-03: present on the first page, gone from the second.
$render('r03-missing-on-page', $page().$page(['omit_atcud' => true]));

// R-04: clipped to a box narrower than the value.
$render('r04-truncated', $page(['clip_atcud' => true, 'atcud_style' => 'left:15mm;top:20mm;font-size:8pt;width:8mm']));

// R-04: pushed outside the printable area.
$render('r04-outside', $page(['atcud_style' => 'left:2mm;top:20mm;font-size:8pt']));

// R-05: no code at all.
$render('r05-code-missing', $page(['qr' => null]));

// R-05: a code that decodes to the wrong content.
$render('r05-code-wrong', $page(['qr' => $wrongQr]));

// R-05 is not a size check: a 6mm code still decodes from a 300dpi raster, which is
// why V-17 checks the printed size separately. This fixture is here to hold that line.
$render('r05-code-tiny', $page(['qr_size' => '6mm']));

// R-06: more pages than any invoice type allows.
$render('r06-too-many-pages', str_repeat($page(), 12));
