<?php

namespace Controlink\Templates\Output\Pdf;

/**
 * A walkable view of one `project_data` document.
 *
 * The static validation is a deterministic traversal of the JSON, and this is the
 * traversal: it flattens the component tree into blocks that each know their zone,
 * their ancestors, their resolved style and — where the style says so — their box in
 * millimetres.
 *
 * Written against a document captured from the real editor, not against an assumed
 * shape. What matters and is easy to get wrong:
 *
 * - the tree is `pages[].frames[].component`, and the frame's component is a wrapper
 *   whose only child is the `.page`;
 * - styles live in a flat `styles` array whose selectors are `#id` for an id but the
 *   bare name for a class (`page`, not `.page`);
 * - lengths arrive in whatever unit the user's input produced, and GrapesJS rewrites a
 *   plain `0` to `0px`, so a millimetre layout still contains pixels.
 */
class LayoutDocument
{
    /**
     * @param  array<string, array<string, string>>  $rulesById
     * @param  array<string, array<string, string>>  $rulesByClass
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private function __construct(
        private readonly array $rulesById,
        private readonly array $rulesByClass,
        private readonly array $blocks,
    ) {}

    /**
     * @param  array<string, mixed>  $projectData
     */
    public static function fromProjectData(array $projectData): self
    {
        [$rulesById, $rulesByClass] = self::indexRules($projectData['styles'] ?? []);

        $document = new self($rulesById, $rulesByClass, []);
        $blocks   = [];

        foreach ($projectData['pages'] ?? [] as $page) {
            foreach ($page['frames'] ?? [] as $frame) {
                $document->collect($frame['component'] ?? [], [], null, $blocks);
            }
        }

        return new self($rulesById, $rulesByClass, $blocks);
    }

    /**
     * Every component that can carry a rule, flattened.
     *
     * @return array<int, array{
     *     id: string,
     *     type: string,
     *     classes: array<int, string>,
     *     attributes: array<string, string>,
     *     zone: string|null,
     *     ancestors: array<int, array<string, mixed>>,
     *     style: array<string, string>,
     *     index: int
     * }>
     */
    public function blocks(): array
    {
        return $this->blocks;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function dataFields(): array
    {
        return array_values(array_filter(
            $this->blocks,
            fn (array $block): bool => ($block['attributes']['data-tpl-type'] ?? '') === 'data-field',
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function blocksOfType(string $type): array
    {
        return array_values(array_filter(
            $this->blocks,
            fn (array $block): bool => ($block['attributes']['data-tpl-type'] ?? '') === $type,
        ));
    }

    /**
     * The style a component ends up with: its class rules first, then its id rule, so
     * the id wins — the same order the browser applies.
     *
     * @param  array<string, mixed>  $block
     * @return array<string, string>
     */
    public function styleOf(array $block): array
    {
        $style = [];

        foreach ($block['classes'] as $class) {
            $style = array_merge($style, $this->rulesByClass[$class] ?? []);
        }

        if ($block['id'] !== '') {
            $style = array_merge($style, $this->rulesById[$block['id']] ?? []);
        }

        return array_merge($style, $block['inlineStyle']);
    }

    /**
     * The box of a block, in millimetres relative to its zone, or null where the
     * layout does not say.
     *
     * A block in a flowing zone has no position until something lays it out, and a
     * block with no explicit width has no width until text is measured. Returning null
     * rather than a guess is what keeps the geometric rules honest: they check what the
     * document states and leave the rest to the render pass.
     *
     * @param  array<string, mixed>  $block
     * @return array{left: float|null, top: float|null, width: float|null, height: float|null}
     */
    public function boxOf(array $block): array
    {
        $style = $this->styleOf($block);

        return [
            'left'   => self::millimetres($style['left'] ?? null),
            'top'    => self::millimetres($style['top'] ?? null),
            'width'  => self::millimetres($style['width'] ?? null),
            'height' => self::millimetres($style['height'] ?? null),
        ];
    }

    /**
     * A length in millimetres, or null when it is absent or in a unit that says
     * nothing on its own (`%`, `auto`, `em`).
     */
    public static function millimetres(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $value = mb_strtolower(trim($value));

        if (! preg_match('/^(-?[\d.]+)(mm|cm|in|px|pt|pc)$/', $value, $matches)) {
            return null;
        }

        $amount = (float) $matches[1];

        return match ($matches[2]) {
            'mm' => $amount,
            'cm' => $amount * 10,
            'in' => $amount * 25.4,
            // 1 CSS px = 1/96in, 1 pt = 1/72in, 1 pc = 12pt.
            'px' => $amount * 25.4 / 96,
            'pt' => $amount * 25.4 / 72,
            'pc' => $amount * 12 * 25.4 / 72,
        };
    }

    /** A font size in points, or null when the unit says nothing on its own. */
    public static function points(?string $value): ?float
    {
        $millimetres = self::millimetres($value);

        return $millimetres === null ? null : $millimetres * 72 / 25.4;
    }

    /**
     * Index the flat style array by id and by class.
     *
     * Selectors arrive either as plain strings (`#tpl-atcud`, `page`) or as objects with
     * a `name` — both shapes exist in documents the editor produces, and treating only
     * one of them as valid loses rules silently.
     *
     * @param  array<int, array<string, mixed>>  $styles
     * @return array{0: array<string, array<string, string>>, 1: array<string, array<string, string>>}
     */
    private static function indexRules(array $styles): array
    {
        $byId    = [];
        $byClass = [];

        foreach ($styles as $rule) {
            $declarations = array_map(
                fn (mixed $value): string => (string) $value,
                (array) ($rule['style'] ?? []),
            );

            if ($declarations === []) {
                continue;
            }

            foreach ((array) ($rule['selectors'] ?? []) as $selector) {
                $name = is_array($selector) ? ($selector['name'] ?? '') : (string) $selector;
                $name = trim((string) $name);

                if ($name === '') {
                    continue;
                }

                if (str_starts_with($name, '#')) {
                    $id = substr($name, 1);

                    $byId[$id] = array_merge($byId[$id] ?? [], $declarations);

                    continue;
                }

                $class = ltrim($name, '.');

                $byClass[$class] = array_merge($byClass[$class] ?? [], $declarations);
            }
        }

        return [$byId, $byClass];
    }

    /**
     * @param  array<string, mixed>  $component
     * @param  array<int, array<string, mixed>>  $ancestors
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private function collect(array $component, array $ancestors, ?string $zone, array &$blocks): void
    {
        $classes    = self::classesOf($component);
        $attributes = array_map(
            fn (mixed $value): string => (string) $value,
            (array) ($component['attributes'] ?? []),
        );

        $zone = $attributes['data-tpl-zone'] ?? self::zoneFromClasses($classes) ?? $zone;

        $entry = [
            'id'          => (string) ($attributes['id'] ?? ''),
            'type'        => (string) ($component['type'] ?? ''),
            'classes'     => $classes,
            'attributes'  => $attributes,
            'zone'        => $zone,
            'ancestors'   => $ancestors,
            'inlineStyle' => array_map(
                fn (mixed $value): string => (string) $value,
                (array) ($component['style'] ?? []),
            ),
            'index' => count($blocks),
        ];

        // The wrapper and text nodes carry no rules of their own and would only be
        // noise in every rule that walks the list.
        if (! in_array($entry['type'], ['wrapper', 'textnode'], true)) {
            $blocks[] = $entry;
        }

        foreach ((array) ($component['components'] ?? []) as $child) {
            $this->collect((array) $child, array_merge($ancestors, [$entry]), $zone, $blocks);
        }
    }

    /**
     * @param  array<string, mixed>  $component
     * @return array<int, string>
     */
    private static function classesOf(array $component): array
    {
        $classes = [];

        foreach ((array) ($component['classes'] ?? []) as $class) {
            $name = is_array($class) ? ($class['name'] ?? '') : (string) $class;
            $name = trim((string) $name);

            if ($name !== '') {
                $classes[] = $name;
            }
        }

        return $classes;
    }

    /**
     * @param  array<int, string>  $classes
     */
    private static function zoneFromClasses(array $classes): ?string
    {
        foreach (['header', 'body', 'footer'] as $zone) {
            if (in_array('zone--'.$zone, $classes, true)) {
                return $zone;
            }
        }

        return null;
    }
}
