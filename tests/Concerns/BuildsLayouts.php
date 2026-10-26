<?php

namespace Controlink\Templates\Tests\Concerns;

use Controlink\Templates\Templates;

/**
 * Builds `project_data` documents for the layout validation tests.
 *
 * The shape here is the shape the real editor produces — `pages[].frames[].component`,
 * a flat `styles` array whose selectors are `#id`, lengths in millimetres — captured
 * from a running GrapesJS rather than assumed. A builder written against a guessed
 * structure would produce documents the validator walks without finding anything, and
 * every rule would pass for the wrong reason.
 */
trait BuildsLayouts
{
    /**
     * A layout that passes every static rule: one block per mandatory placeable
     * binding, all in the header so the geometric rules actually apply to them, black
     * on white, above the minimum font size.
     *
     * @return array{project_data: array<string, mixed>, html: string, css: string}
     */
    private function validLayout(string $typeCode = 'invoice'): array
    {
        return $this->layout($this->defaultBlocks($typeCode));
    }

    /**
     * The same layout with one block changed, to aim a test at a single rule.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     * @return array{project_data: array<string, mixed>, html: string, css: string}
     */
    private function layoutWith(string $binding, callable $mutate, string $typeCode = 'invoice'): array
    {
        $blocks = array_map(
            fn (array $block): array => $this->bindingOf($block) === $binding ? $mutate($block) : $block,
            $this->defaultBlocks($typeCode),
        );

        return $this->layout($blocks);
    }

    /**
     * The same layout with one block removed, for the "field is absent" rules.
     *
     * @return array{project_data: array<string, mixed>, html: string, css: string}
     */
    private function layoutWithout(string $binding, string $typeCode = 'invoice'): array
    {
        return $this->layout(array_values(array_filter(
            $this->defaultBlocks($typeCode),
            // Addressed by binding, and the table and the code slot carry theirs in a
            // different attribute — so a request to drop one of those names it too.
            fn (array $block): bool => $this->bindingOf($block) !== $binding,
        )));
    }

    /**
     * The same layout with an extra block appended to a zone, for occlusion and for
     * blocks that are not data fields.
     *
     * @param  array<string, mixed>  $block
     * @return array{project_data: array<string, mixed>, html: string, css: string}
     */
    private function layoutPlus(array $block, string $typeCode = 'invoice'): array
    {
        return $this->layout(array_merge($this->defaultBlocks($typeCode), [$block]));
    }

    /**
     * A block for every mandatory binding, placed by whichever block type can bind it:
     * data fields in a two column grid inside the header band, the line table in the
     * body, and the code slot in the footer at the legal minimum size.
     *
     * All of them, not just the text ones — the validator asks the right block for each
     * kind of binding, so a layout missing the table or the code is a layout that
     * legitimately fails.
     *
     * @return array<int, array<string, mixed>>
     */
    private function defaultBlocks(string $typeCode): array
    {
        $config   = Templates::type($typeCode)->toArray();
        $bindings = (array) ($config['bindings'] ?? []);
        $limits   = (array) ($config['pdf']['visibility_limits'] ?? []);

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
     * Faithful on purpose: the line table has to carry its `{% for %}` and its header
     * row, and the code slot has to be an `img` with its binding, or the compositor is
     * being tested against markup the editor never emits.
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
                $value   = $column['expression'] ?? (($column['format'] ?? '') !== ''
                    ? '{{ linha.'.$printed.'|'.$column['format'].' }}'
                    : '{{ linha.'.$printed.' }}');

                $cells .= '<td>'.$value.'</td>';
            }

            return '<div class="block block--lines"'.$pairs.'><table class="lines">'
                .'<thead><tr>'.$head.'</tr></thead>'
                .'<tbody>{% for linha in linhas %}<tr>'.$cells.'</tr>{% endfor %}</tbody>'
                .'</table></div>';
        }

        if ($type === 'page-number') {
            $expression = ($attributes['data-tpl-format'] ?? 'x') === 'x-of-y'
                ? '{{ pagina.numero }} de {{ pagina.total }}'
                : '{{ pagina.numero }}';

            return '<div class="block block--page-number"'.$pairs.'>'.$expression.'</div>';
        }

        return '<div class="block"'.$pairs.'></div>';
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array{project_data: array<string, mixed>, html: string, css: string}
     */
    private function layout(array $blocks): array
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
                    ] + ($block['field_attributes'] ?? []),
                    'components' => [['type' => 'textnode', 'content' => 'x']],
                ];

                $expression = $block['expression'] ?? (($block['format'] ?? '') !== ''
                    ? '{{ '.$block['binding'].'|'.$block['format'].' }}'
                    : '{{ '.$block['binding'].' }}');

                // A formula field exports its binding and source (CGE-37): the compositor
                // evaluates what the markup carries, and layer 1 checks the two agree.
                $formula = isset($block['field_attributes']['data-tpl-formula'])
                    ? ' data-tpl-binding="'.htmlspecialchars((string) $block['binding'], ENT_QUOTES).'"'
                        .' data-tpl-formula="'.htmlspecialchars((string) $block['field_attributes']['data-tpl-formula'], ENT_QUOTES).'"'
                    : '';

                $markup[$zone] .= '<div id="'.$id.'" class="block block--field" data-tpl-type="data-field"'.$formula.'>'.$expression.'</div>';
            } else {
                $attributes = array_merge(
                    ['id' => $id, 'data-tpl-type' => $block['type']],
                    $block['attributes'] ?? [],
                );

                $zones[$zone][] = [
                    'type'       => $block['type'],
                    'classes'    => ['block'],
                    'attributes' => $attributes,
                ] + (isset($block['text']) ? ['components' => [['type' => 'textnode', 'content' => $block['text']]]] : []);

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
