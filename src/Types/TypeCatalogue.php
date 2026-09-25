<?php

namespace Controlink\Templates\Types;

/**
 * Every declared type, read once from config. `validate()` is structural only: it
 * never opens a database connection, because it runs at boot, before any tenant
 * middleware has pointed a connection at a real database.
 */
class TypeCatalogue
{
    /** @var array<string, TemplateType>|null */
    private ?array $types = null;

    /** @param array<string, array<string, mixed>> $config */
    public function __construct(private readonly array $config) {}

    /** @return array<string, TemplateType> */
    public function all(): array
    {
        if ($this->types === null) {
            $this->types = [];

            foreach ($this->config as $code => $type) {
                $this->types[$code] = TemplateType::fromConfig((string) $code, (array) $type);
            }
        }

        return $this->types;
    }

    public function has(string $code): bool
    {
        return isset($this->all()[$code]);
    }

    public function find(string $code): TemplateType
    {
        return $this->all()[$code] ?? throw InvalidTemplateType::unknown($code);
    }

    /** @return array<string, TemplateType> the types a user may create from scratch */
    public function creatable(): array
    {
        return array_filter($this->all(), fn (TemplateType $type): bool => ! $type->seeded);
    }

    public function validate(): void
    {
        foreach ($this->all() as $type) {
            $this->validateType($type);
        }
    }

    private function validateType(TemplateType $type): void
    {
        $fail = fn (string $reason) => throw InvalidTemplateType::because($type->code, $reason);

        if (! in_array($type->output, TemplateType::OUTPUTS, true)) {
            $fail("unknown output [{$type->output}]");
        }

        if ($type->isPdf() && $type->pdf === []) {
            $fail('output is pdf but the `pdf` block (paper_format, margins, visibility_limits) is missing');
        }

        $formats = (array) config('templates.formats');
        $seen    = [];

        foreach ($type->bindings as $binding) {
            $key = (string) ($binding['key'] ?? '');

            if ($key === '') {
                $fail('a binding has no key');
            }

            if (isset($seen[$key])) {
                $fail("duplicate binding key [{$key}]");
            }

            $seen[$key] = true;

            if (! in_array($binding['type'] ?? null, TemplateType::BINDING_TYPES, true)) {
                $fail("binding [{$key}] has an unknown type [".($binding['type'] ?? 'null').']');
            }

            if ($binding['legal'] && ! $binding['mandatory']) {
                $fail("binding [{$key}] is legal but not mandatory; every legal field is mandatory");
            }

            if ($binding['scope'] !== null && ! $binding['legal']) {
                $fail("binding [{$key}] declares a scope but is not legal");
            }

            if ($binding['scope'] !== null && ! in_array($binding['scope'], TemplateType::SCOPES, true)) {
                $fail("binding [{$key}] has an unknown scope [{$binding['scope']}]");
            }

            if ($binding['default_format'] !== null && $binding['default_format'] !== '' && ! in_array($binding['default_format'], $formats, true)) {
                $fail("binding [{$key}] has an unknown default format [{$binding['default_format']}]");
            }

            if ($binding['columns'] !== null && $binding['type'] !== 'collection') {
                $fail("binding [{$key}] declares columns but is not a collection");
            }

            if ($binding['type'] !== 'collection') {
                $prefix = str_contains($key, '.') ? substr($key, 0, strrpos($key, '.')) : $key;

                if (! isset($type->nodes[$prefix])) {
                    $fail("binding [{$key}] belongs to node [{$prefix}], which is not declared in `nodes`");
                }
            }
        }

        foreach ($type->nodes as $id => $node) {
            if (array_key_exists('singleton', $node) && $node['singleton'] !== false && ! is_string($node['singleton'])) {
                $fail("node [{$id}]: `singleton` must be the name of a static method (a string), not a boolean");
            }

            if (is_string($node['singleton'] ?? null) && ! method_exists((string) $node['model'], $node['singleton'])) {
                $fail("node [{$id}]: singleton method [{$node['singleton']}] does not exist on [{$node['model']}]");
            }
        }

        $this->validateEveryPageFields($type, $fail);
    }

    /** Every `every_page` legal field must start in the header or footer of the initial layout. */
    private function validateEveryPageFields(TemplateType $type, callable $fail): void
    {
        $html = $type->initialLayout['html'];

        foreach ($type->bindings as $binding) {
            if ($binding['scope'] !== 'every_page') {
                continue;
            }

            $inBand = false;

            foreach (['header', 'footer'] as $zone) {
                if (preg_match('/data-tpl-zone="'.$zone.'".*?data-tpl-binding="'.preg_quote($binding['key'], '/').'"/s', $html) === 1
                    && $this->bindingIsInsideZone($html, $binding['key'], $zone)) {
                    $inBand = true;
                }
            }

            if (! $inBand) {
                $fail("binding [{$binding['key']}] has scope every_page but the initial layout does not place it in the header or footer");
            }
        }
    }

    private function bindingIsInsideZone(string $html, string $key, string $zone): bool
    {
        $zoneStart = strpos($html, 'data-tpl-zone="'.$zone.'"');

        if ($zoneStart === false) {
            return false;
        }

        $nextZone = preg_match('/data-tpl-zone="(?!'.$zone.')/', $html, $m, PREG_OFFSET_CAPTURE, $zoneStart) === 1 ? $m[0][1] : strlen($html);
        $field    = strpos($html, 'data-tpl-binding="'.$key.'"');

        return $field !== false && $field > $zoneStart && $field < $nextZone;
    }
}
