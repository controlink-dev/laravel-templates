<?php

namespace Controlink\Templates\Codes;

use RuntimeException;

/**
 * The content a fixed-text code carries.
 *
 * ONE implementation, on purpose. Two callers use it: `CodeImageGenerator`, which draws
 * the image, and the host's render validator, which decodes the code back out of the
 * generated document and compares it with what was expected. If the composition existed
 * twice, the validation would confirm its own mistake — a wrongly composed QR compared
 * against the same wrong expectation passes.
 *
 * The template, the escapes and the optional query parameters all come from
 * `config('templates.code_types')`, so a new type is a configuration entry with no code
 * behind it.
 */
class CodePayload
{
    /**
     * @param  array<string, string>  $values  one entry per field the type declares
     */
    public static function compose(string $type, array $values): string
    {
        $definition = config('templates.code_types.'.$type);

        if ($definition === null) {
            throw new RuntimeException("Unknown code content type: {$type}.");
        }

        $escape  = (array) ($definition['escape'] ?? []);
        $payload = (string) $definition['payload'];

        foreach ((array) $definition['fields'] as $field) {
            $payload = str_replace(
                '{'.$field.'}',
                self::escaped((string) ($values[$field] ?? ''), $escape),
                $payload,
            );
        }

        return $payload.self::query($definition, $values);
    }

    /**
     * The optional parameters a payload can carry, appended only when at least one of
     * them has a value.
     *
     * This exists for `mailto:` and RFC 6068. A subject is an optional `hfield`: a blank
     * one must leave no `?subject=` behind — that is not an empty header, it is a
     * malformed one — and a subject containing a space, an `&` or a `/` ends the header
     * early unless it is percent-encoded. `rawurlencode` is deliberately conservative: it
     * encodes more than the RFC's `qchar` set strictly requires, and every extra escape
     * is still a valid `pct-encoded`.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, string>  $values
     */
    private static function query(array $definition, array $values): string
    {
        $parameters = [];

        foreach ((array) ($definition['query'] ?? []) as $field => $name) {
            $value = trim((string) ($values[$field] ?? ''));

            if ($value !== '') {
                $parameters[] = $name.'='.rawurlencode($value);
            }
        }

        return $parameters === [] ? '' : '?'.implode('&', $parameters);
    }

    /**
     * The content of a slot, read from its own attributes — or null when the slot is a
     * dynamic one, whose content comes from the dataset and not from a template.
     *
     * A slot with no `data-tpl-code-source` at all is dynamic too: that is every slot drawn
     * before this feature existed, and they must keep working untouched.
     *
     * @param  array<string, string>  $attributes
     */
    public static function fromAttributes(array $attributes): ?string
    {
        if (($attributes['data-tpl-code-source'] ?? 'dynamic') !== 'fixed') {
            return null;
        }

        $type       = (string) ($attributes['data-tpl-code-type'] ?? '');
        $definition = config('templates.code_types.'.$type);

        if ($definition === null) {
            throw new RuntimeException("A code slot is set to a content type that does not exist: {$type}.");
        }

        $values = [];

        foreach ((array) $definition['fields'] as $field) {
            $values[$field] = (string) ($attributes['data-tpl-code-'.$field] ?? '');
        }

        return self::compose($type, $values);
    }

    /**
     * The backslash goes FIRST, always. Escaping it after the separators would double the
     * backslashes the separator escaping had just introduced.
     *
     * @param  array<int, string>  $escape
     */
    private static function escaped(string $value, array $escape): string
    {
        foreach ($escape as $character) {
            $value = str_replace($character, '\\'.$character, $value);
        }

        return $value;
    }
}
