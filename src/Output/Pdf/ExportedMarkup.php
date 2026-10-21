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
 * there is nothing to compare, not a mismatch. A non-empty export without the block, or
 * with several elements under its id, answers every one of them — a renamed or duplicated
 * id must not be a way around the comparison.
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

    /**
     * The trimmed text of every element with this id: an empty list when the export has
     * none, null only when there is no export at all.
     *
     * @return list<string>|null
     */
    public function fieldTexts(string $id): ?array
    {
        $elements = $this->elements($id);

        return $elements === null
            ? null
            : array_map(fn (DOMElement $element): string => trim($element->textContent), $elements);
    }

    /**
     * Every `{{ … }}` printed inside the elements with this id: an empty list when the
     * export has none, null only when there is no export at all.
     *
     * @return list<string>|null
     */
    public function tableExpressions(string $id): ?array
    {
        $elements = $this->elements($id);

        if ($elements === null) {
            return null;
        }

        $text = implode("\n", array_map(fn (DOMElement $element): string => $element->textContent, $elements));

        preg_match_all('/\{\{.*?\}\}/s', $text, $matches);

        return array_values(array_unique($matches[0]));
    }

    /** Whether the export has at least one element with this id; null without an export. */
    public function has(string $id): ?bool
    {
        $elements = $this->elements($id);

        return $elements === null ? null : $elements !== [];
    }

    /** @return list<DOMElement>|null every element with this id; null when there is no export */
    private function elements(string $id): ?array
    {
        if ($this->xpath === null) {
            return null;
        }

        if ($id === '') {
            return [];
        }

        $nodes = $this->xpath->query('//*[@id='.$this->literal($id).']');

        if ($nodes === false) {
            return [];
        }

        return array_values(array_filter(
            iterator_to_array($nodes),
            fn (mixed $node): bool => $node instanceof DOMElement,
        ));
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
