<?php

namespace Controlink\Templates\Types;

final class TemplateType
{
    public const OUTPUTS       = ['pdf', 'html'];
    public const BINDING_TYPES = ['text', 'number', 'date', 'money', 'collection', 'image', 'qr', 'barcode'];
    public const SCOPES        = ['any_page', 'every_page'];

    /**
     * @param  list<array<string, mixed>>  $bindings
     * @param  array<string, array<string, mixed>>  $nodes
     * @param  array<string, mixed>  $fixture
     * @param  array{html: string, css: string, zones: array<string, float>}  $initialLayout
     * @param  array<string, mixed>  $pdf  empty unless output is pdf
     */
    public function __construct(
        public readonly string $code,
        public readonly string $label,
        public readonly string $output,
        public readonly bool $seeded,
        public readonly int $layoutRevision,
        public readonly array $bindings,
        public readonly array $nodes,
        public readonly array $fixture,
        public readonly int $fixtureRepeats,
        public readonly array $initialLayout,
        public readonly array $pdf,
        private readonly array $raw,
    ) {}

    /** @param array<string, mixed> $config one entry of `templates.types` */
    public static function fromConfig(string $code, array $config): self
    {
        $bindings = [];

        foreach ((array) ($config['bindings'] ?? []) as $binding) {
            $bindings[] = $binding + ['mandatory' => false, 'legal' => false, 'scope' => null, 'default_format' => null, 'columns' => null];
        }

        return new self(
            code: $code,
            label: (string) ($config['label'] ?? $code),
            output: (string) ($config['output'] ?? 'pdf'),
            seeded: (bool) ($config['seeded'] ?? false),
            layoutRevision: (int) ($config['layout_revision'] ?? 1),
            bindings: $bindings,
            nodes: (array) ($config['nodes'] ?? []),
            fixture: (array) ($config['fixture'] ?? []),
            fixtureRepeats: max(1, (int) ($config['fixture_repeats'] ?? 1)),
            initialLayout: [
                'html'  => (string) ($config['initial_layout']['html'] ?? '<div class="page"></div>'),
                'css'   => (string) ($config['initial_layout']['css'] ?? ''),
                'zones' => (array) ($config['initial_layout']['zones'] ?? []),
            ],
            pdf: is_array($config['pdf'] ?? null) ? $config['pdf'] : [],
            raw: $config,
        );
    }

    public function isPdf(): bool
    {
        return $this->output === 'pdf';
    }

    /** @return array<string, mixed>|null */
    public function binding(string $key): ?array
    {
        foreach ($this->bindings as $binding) {
            if ($binding['key'] === $key) {
                return $binding;
            }
        }

        return null;
    }

    /** @return array<string, array<string, mixed>> bindings indexed by key */
    public function bindingsByKey(): array
    {
        $indexed = [];

        foreach ($this->bindings as $binding) {
            $indexed[$binding['key']] = $binding;
        }

        return $indexed;
    }

    /** The config array as declared, for callers that still read it as an array. */
    public function toArray(): array
    {
        return $this->raw;
    }
}
