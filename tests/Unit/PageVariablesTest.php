<?php

namespace Controlink\Templates\Tests\Unit;

use Controlink\Templates\Output\Pdf\DocumentComposer;
use Controlink\Templates\Tests\TestCase;
use Controlink\Templates\Types\TemplateType;

/**
 * The page variables (margins, header/footer heights) are the geometry's, not the saved
 * layout's (CGE-50). The editor's project CSS carries a `.page{--margin-…}` rule of its
 * own, written after these in the document; with the same specificity it won, so a
 * version's new margins never reached the PDF.
 */
class PageVariablesTest extends TestCase
{
    public function test_the_geometry_outranks_a_page_rule_in_the_layout_css(): void
    {
        $type = TemplateType::fromConfig('document', [
            'output'         => 'pdf',
            'bindings'       => [],
            'pdf'            => ['paper_format' => 'A4', 'margins' => ['top' => 15, 'right' => 7, 'bottom' => 15, 'left' => 7]],
            'initial_layout' => ['zones' => ['header_height' => 55, 'footer_height' => 70]],
        ]);

        $css = (new DocumentComposer)->pageVariables($type);

        // `html .page` (0,1,1) beats the layout's `.page` (0,1,0) whatever the order.
        $this->assertStringStartsWith('html .page{', $css);
        $this->assertStringContainsString('--margin-left:7mm;', $css);
        $this->assertStringContainsString('--footer-height:70mm;', $css);
    }
}
