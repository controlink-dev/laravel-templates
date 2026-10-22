<?php

namespace Controlink\Templates\Tests\Feature\Concerns;

use Controlink\Templates\Types\TemplateType;

/**
 * Builds `project_data` documents and their exported HTML for the PDF compositor
 * tests.
 *
 * The shape here is the shape the real editor produces — `pages[].frames[].component`,
 * a flat `styles` array whose selectors are `#id`, lengths in millimetres — carried over
 * from the host's own fidelity spike rather than assumed. A builder written against a
 * guessed structure would produce documents the compositor walks without finding
 * anything, and every test would pass for the wrong reason.
 */
trait BuildsPdfLayouts
{
    /**
     * A layout that carries a block for every mandatory placeable binding, all in the
     * header, plus the line table and the code slot.
     *
     * @return array{project_data: array<string, mixed>, html: string, css: string}
     */
    private function validPdfLayout(TemplateType $type): array
    {
        return $this->pdfLayout($this->defaultBlocks($type));
    }

    /**
     * The same layout with one binding's block removed.
     *
     * @return array{project_data: array<string, mixed>, html: string, css: string}
     */
    private function pdfLayoutWithout(TemplateType $type, string $binding): array
    {
        return $this->pdfLayout(array_values(array_filter(
            $this->defaultBlocks($type),
            fn (array $block): bool => $this->bindingOf($block) !== $binding,
        )));
    }

    /**
     * The same layout with an extra block appended.
     *
     * @param  array<string, mixed>  $block
     * @return array{project_data: array<string, mixed>, html: string, css: string}
     */
    private function pdfLayoutPlus(TemplateType $type, array $block): array
    {
        return $this->pdfLayout(array_merge($this->defaultBlocks($type), [$block]));
    }

    /**
     * A block for every mandatory binding the compositor can place: data fields in a
     * two column grid inside the header band, the line table in the body, and the code
     * slot in the footer at the legal minimum size.
     *
     * @return array<int, array<string, mixed>>
     */
    private function defaultBlocks(TemplateType $type): array
    {
        $bindings = $type->bindings;
        $limits   = (array) ($type->pdf['visibility_limits'] ?? []);

        $fields = array_values(array_filter(
            $bindings,
            fn (array $binding): bool => ($binding['mandatory'] ?? false)
                && in_array($binding['type'], ['text', 'number', 'date', 'money'], true),
        ));

        $blocks = [];

        foreach ($fields as $position => $binding) {
            $blocks[] = [
                'binding' => $binding['key'],
                'format'  => $binding['default_format'] ?? '',
                'legal'   => (bool) ($binding['legal'] ?? false),
                'zone'    => 'header',
                'style'   => [
                    'position'  => 'absolute',
                    'left'      => ($position % 2 === 0 ? '0mm' : '90mm'),
                    'top'       => (intdiv($position, 2) * 5).'mm',
                    'font-size' => '8pt',
                    'color'     => 'rgb(0, 0, 0)',
                ],
            ];
        }

        foreach ($bindings as $binding) {
            if (! ($binding['mandatory'] ?? false)) {
                continue;
            }

            if ($binding['type'] === 'collection') {
                $blocks[] = [
                    'type'       => 'line-table',
                    'zone'       => 'body',
                    'id'         => 'tpl-lines',
                    'attributes' => [
                        'data-tpl-collection' => $binding['key'],
                        'data-tpl-columns'    => json_encode(array_map(
                            fn (array $column): array => [
                                'key'    => $column['key'],
                                'label'  => $column['label'],
                                'width'  => '',
                                'align'  => 'left',
                                'format' => $column['default_format'] ?? '',
                            ],
                            (array) ($binding['columns'] ?? []),
                        )),
                    ],
                    'style' => ['position' => 'absolute', 'left' => '0mm', 'top' => '0mm', 'width' => '180mm'],
                ];
            }

            if (in_array($binding['type'], ['qr', 'barcode'], true)) {
                $side = (float) ($limits['qr_size_min_mm'] ?? 30);

                $blocks[] = [
                    'type'       => 'code-slot',
                    'zone'       => 'footer',
                    'id'         => 'tpl-code-'.md5($binding['key']),
                    'attributes' => ['data-tpl-binding' => $binding['key']],
                    'style'      => [
                        'position' => 'absolute',
                        'left'     => '150mm',
                        'top'      => '0mm',
                        'width'    => $side.'mm',
                        'height'   => $side.'mm',
                    ],
                ];
            }
        }

        return $blocks;
    }

    /**
     * Whichever field a block is bound to, wherever it keeps it: a data field in its
     * own key, a code slot and a table in their attributes.
     *
     * @param  array<string, mixed>  $block
     */
    private function bindingOf(array $block): ?string
    {
        return $block['binding']
            ?? $block['attributes']['data-tpl-binding']
            ?? $block['attributes']['data-tpl-collection']
            ?? null;
    }

    /**
     * The markup a block exports, as its component's `toHTML()` produces it.
     *
     * @param  array<string, string>  $attributes
     */
    private function markupFor(string $type, array $attributes): string
    {
        $pairs = '';

        foreach ($attributes as $name => $value) {
            $pairs .= ' '.$name.'="'.htmlspecialchars((string) $value, ENT_QUOTES).'"';
        }

        if ($type === 'code-slot') {
            return '<img class="block block--code"'.$pairs.' alt="">';
        }

        if ($type === 'line-table') {
            $columns = json_decode($attributes['data-tpl-columns'] ?? '[]', true) ?: [];
            $head    = '';
            $cells   = '';

            foreach ($columns as $column) {
                $head .= '<th>'.htmlspecialchars((string) $column['label'], ENT_QUOTES).'</th>';

                // A formula column prints its result, under `formulaKey` when it has one.
                $printed = $column['formulaKey'] ?? $column['key'];
                $value   = ($column['format'] ?? '') !== ''
                    ? '{{ linha.'.$printed.'|'.$column['format'].' }}'
                    : '{{ linha.'.$printed.' }}';

                $cells .= '<td>'.$value.'</td>';
            }

            return '<div class="block block--lines"'.$pairs.'><table class="lines">'
                .'<thead><tr>'.$head.'</tr></thead>'
                .'<tbody>{% for linha in linhas %}<tr>'.$cells.'</tr>{% endfor %}</tbody>'
                .'</table></div>';
        }

        return '<div class="block"'.$pairs.'></div>';
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array{project_data: array<string, mixed>, html: string, css: string}
     */
    private function pdfLayout(array $blocks): array
    {
        $zones  = ['header' => [], 'body' => [], 'footer' => []];
        $styles = [];
        $markup = ['header' => '', 'body' => '', 'footer' => ''];

        foreach ($blocks as $index => $block) {
            $id   = $block['id'] ?? 'tpl-test-'.$index;
            $zone = $block['zone'] ?? 'body';

            if (($block['type'] ?? 'data-field') === 'data-field') {
                $zones[$zone][] = [
                    'type'       => 'data-field',
                    'classes'    => ['block', 'block--field'],
                    'attributes' => [
                        'id'               => $id,
                        'data-tpl-type'    => 'data-field',
                        'data-tpl-binding' => $block['binding'],
                        'data-tpl-format'  => (string) ($block['format'] ?? ''),
                        'data-tpl-legal'   => ($block['legal'] ?? false) ? '1' : null,
                    ],
                    'components' => [['type' => 'textnode', 'content' => 'x']],
                ];

                $expression = ($block['format'] ?? '') !== ''
                    ? '{{ '.$block['binding'].'|'.$block['format'].' }}'
                    : '{{ '.$block['binding'].' }}';

                $markup[$zone] .= '<div id="'.$id.'" class="block block--field" data-tpl-type="data-field">'.$expression.'</div>';
            } else {
                $attributes = array_merge(
                    ['id' => $id, 'data-tpl-type' => $block['type']],
                    $block['attributes'] ?? [],
                );

                $zones[$zone][] = [
                    'type'       => $block['type'],
                    'classes'    => ['block'],
                    'attributes' => $attributes,
                ];

                // The markup has to carry the attributes too, not only the project
                // data: the compositor reads the exported HTML, and a code slot whose
                // binding lives solely in the project data reaches it bound to nothing.
                $markup[$zone] .= $this->markupFor($block['type'], $attributes);
            }

            $styles[] = ['selectors' => ['#'.$id], 'style' => $block['style'] ?? []];
        }

        $styles[] = [
            'selectors' => ['page'],
            'style'     => [
                '--margin-top'    => '15mm',
                '--margin-right'  => '15mm',
                '--margin-bottom' => '15mm',
                '--margin-left'   => '15mm',
                '--header-height' => '40mm',
                '--footer-height' => '18mm',
            ],
        ];

        $zoneComponents = [];
        $zoneMarkup     = '';

        foreach (['header', 'body', 'footer'] as $zone) {
            $zoneComponents[] = [
                'classes'    => ['zone', 'zone--'.$zone],
                'attributes' => ['data-tpl-zone' => $zone],
                'components' => $zones[$zone],
            ];

            $zoneMarkup .= '<div class="zone zone--'.$zone.'" data-tpl-zone="'.$zone.'">'.$markup[$zone].'</div>';
        }

        return [
            'project_data' => [
                'assets'  => [],
                'styles'  => $styles,
                'symbols' => [],
                'pages'   => [[
                    'id'     => 'page-1',
                    'frames' => [[
                        'id'        => 'frame-1',
                        'component' => [
                            'type'       => 'wrapper',
                            'components' => [[
                                'classes'    => ['page'],
                                'components' => $zoneComponents,
                            ]],
                        ],
                    ]],
                ]],
            ],
            'html' => '<body><div class="page">'.$zoneMarkup.'</div></body>',
            'css'  => '',
        ];
    }
}
