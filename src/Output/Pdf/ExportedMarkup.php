<?php

namespace Controlink\Templates\Output\Pdf;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * The editor's exported HTML, read for what a block really says.
 *
 * The project data holds a field's choices as attributes; the exported HTML holds the
 * Twig expression those choices became, and the HTML is what gets rendered. V-01 reads
 * both and compares them, so a hand-edited expression cannot ride on valid attributes.
 *
 * An empty export (a caller validating project data alone) answers null to everything:
 * there is nothing to compare, not a mismatch.
 */
final class ExportedMarkup
{
    private ?DOMXPath $xpath = null;

    public function __construct(string $html)
    {
        if (trim($html) === '') {
            return;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->xpath = new DOMXPath($document);
    }

    /** The trimmed text of the element with this id, or null when there is none. */
    public function fieldText(string $id): ?string
    {
        $element = $this->element($id);

        return $element === null ? null : trim($element->textContent);
    }

    /**
     * Every `{{ … }}` printed inside the element with this id, or null when there is none.
     *
     * @return list<string>|null
     */
    public function tableExpressions(string $id): ?array
    {
        $element = $this->element($id);

        if ($element === null) {
            return null;
        }

        preg_match_all('/\{\{.*?\}\}/s', $element->textContent, $matches);

        return array_values(array_unique($matches[0]));
    }

    private function element(string $id): ?DOMElement
    {
        if ($this->xpath === null || $id === '') {
            return null;
        }

        $nodes = $this->xpath->query('//*[@id='.$this->literal($id).']');
        $node  = $nodes === false ? null : $nodes->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    /** An XPath string literal for any id, quotes included. */
    private function literal(string $value): string
    {
        if (! str_contains($value, "'")) {
            return "'".$value."'";
        }

        return 'concat(\''.str_replace("'", "', \"'\", '", $value).'\')';
    }
}
