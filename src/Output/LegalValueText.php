<?php

namespace Controlink\Templates\Output;

use Controlink\Templates\Rendering\FormatSpec;
use Controlink\Templates\Rendering\TemplateRenderer;
use Throwable;

/**
 * The texts a field's value can take in a printed document, the one place both the PDF
 * check (R-02) and the receipt check (E-01, CGE-97) read, so they look for the same thing.
 *
 * Rendered through the same filters the template uses, because the fixture holds raw
 * values: comparing the page against `1229999.98` would fail on a document correctly
 * showing `1 229 999,98 €`.
 */
final class LegalValueText
{
    /** Stands for "the binding's `default_format`" where no placed field is known. */
    public const DEFAULT_FORMAT = 'binding-default';

    public function __construct(private readonly TemplateRenderer $renderer) {}

    /**
     * Each distinct, non-empty text the value is printed as, the first being the one to
     * name when none is found.
     *
     * CGE-49: what the placed fields print, each with its own format and arguments. Any one
     * of them will do: the same binding placed twice in two formats is one value shown
     * twice. With no placement known (a caller that did not pass the layout), the
     * binding's default format.
     *
     * @param  array<string, mixed>  $binding
     * @param  array<string, mixed>  $dataset
     * @param  array<string, list<FormatSpec|null>>  $placedFormats  by binding key
     * @return list<string>
     */
    public function candidates(array $binding, array $dataset, array $placedFormats = []): array
    {
        $specs = array_key_exists($binding['key'], $placedFormats)
            ? $placedFormats[$binding['key']]
            : [self::DEFAULT_FORMAT];

        // CGE-69: an empty list is a field named only inside a formula, which prints it
        // its own way — nothing to look for. Never for a legal field: a formula does not
        // place one, so its value is still looked for as the binding prints it.
        if ($specs === [] && ($binding['legal'] ?? false)) {
            $specs = [self::DEFAULT_FORMAT];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (FormatSpec|string|null $spec): string => $this->text($binding, $dataset, $spec),
            $specs,
        ), fn (string $text): bool => $text !== '')));
    }

    /**
     * The exact string the value takes in one format.
     *
     * @param  array<string, mixed>  $binding
     * @param  array<string, mixed>  $dataset
     */
    public function text(array $binding, array $dataset, FormatSpec|string|null $spec = self::DEFAULT_FORMAT): string
    {
        if ($spec === self::DEFAULT_FORMAT) {
            $format = $binding['default_format'] ?? '';

            $template = $format !== ''
                ? '{{ '.$binding['key'].'|'.$format.' }}'
                : '{{ '.$binding['key'].' }}';
        } else {
            $template = $spec instanceof FormatSpec
                ? $spec->expression($binding['key'])
                : '{{ '.$binding['key'].' }}';
        }

        try {
            return html_entity_decode(
                $this->renderer->render($template, $dataset),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8',
            );
        } catch (Throwable) {
            // A value the dataset does not carry is a dataset problem, not a layout
            // one, and it is not this rule's business to report it.
            return '';
        }
    }
}
