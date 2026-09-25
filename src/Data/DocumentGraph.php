<?php

namespace Controlink\Templates\Data;

use Controlink\Templates\Contracts\TenantResolver;
use Controlink\Templates\Types\TemplateType;

/**
 * The document's graph: its nodes, and the fields each one offers.
 *
 * Memoised for the duration of the request and NOT cached persistently: a column added
 * to a model has to appear without anyone clearing a cache, which is the request's own
 * requirement. Within one request the answer cannot change, and the introspection costs
 * a schema read per node.
 *
 * A node with no model takes its fields from the declared `bindings` list alone, because
 * nothing represents it yet — there is no model to introspect. A node WITH a model takes
 * both: its declared bindings first, then the schema fields the declared list did not
 * already name.
 */
class DocumentGraph
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private static array $memo = [];

    /**
     * @return array<int, array{id: string, label: string, collection: bool, reachable: bool, fields: array<int, array{key: string, label: string, label_missing: bool, type: string, is_boolean?: bool, encrypted: bool, length?: ?int, scale?: ?int}>}>
     */
    public static function for(TemplateType $type): array
    {
        $key = self::memoKey($type);

        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

        /*
         * The schema layer needs a resolved tenant to read from: `NodeFields` calls
         * `Schema::for($model)` for every node with a model, and outside a tenant that
         * connection is not pointed at a real database. In `tenancy.mode = none` the
         * host's own default connection is always usable, so the guard opens for every
         * request; in every other mode it opens only once a tenant has actually been
         * resolved.
         *
         * The guard belongs HERE and not in `FixtureDataset`, which reaches this class:
         * this is the class that reads schemas, and it also has callers of its own
         * (validation, `DocumentDataset`). With no tenant in scope every node keeps its
         * DECLARED fields and offers no calculated ones — a real limitation, not a
         * neutral fallback: a caller looking for a calculated field outside a resolved
         * tenant will not find it here.
         */
        $hasTenant = config('templates.tenancy.mode') === 'none'
            || app(TenantResolver::class)->currentId() !== null;

        $declared = [];

        foreach ($type->bindings as $binding) {
            $prefix = self::prefixOf((string) $binding['key']);

            $declared[$prefix][] = [
                'key'           => $binding['key'],
                'label'         => $binding['label'],
                'label_missing' => false,
                'type'          => $binding['type'],
                'encrypted'     => false,
            ];
        }

        $graph = [];

        foreach ($type->nodes as $id => $node) {
            $graph[] = [
                'id' => $id,
                'label'      => isset($node['label']) ? __($node['label']) : $id,
                'collection' => (bool) ($node['collection'] ?? false),
                'reachable'  => self::isReachable($node),
                'fields'     => self::fieldsOf($id, $node, $declared[$id] ?? [], $hasTenant),
            ];
        }

        return self::$memo[$key] = $graph;
    }

    /**
     * One binding by key, in the shape the declared list uses — so nothing downstream can
     * tell a calculated field from a declared one.
     *
     * The DECLARED list is consulted first, and that order is the whole safety of this
     * design: a declared key keeps `mandatory`, `legal`, `scope` and its default format,
     * and only a key nobody declared falls through to the schema. A resolved key is
     * therefore never legal and never mandatory — what a rule requires is decided by
     * whoever declared the type, not by whoever draws the layout.
     *
     * @return array<string, mixed>|null null when the key exists in neither source
     */
    public static function resolve(string $key, TemplateType $type): ?array
    {
        $binding = $type->binding($key);

        if ($binding !== null) {
            return $binding;
        }

        foreach (self::for($type) as $node) {
            foreach ($node['fields'] as $field) {
                if ($field['key'] !== $key) {
                    continue;
                }

                return [
                    'key'            => $field['key'],
                    'label'          => $field['label'],
                    'type'           => $field['type'],
                    'mandatory'      => false,
                    'legal'          => false,
                    'scope'          => null,
                    'default_format' => '',
                ];
            }
        }

        return null;
    }

    /**
     * A node's offered fields: its DECLARED bindings first, then the schema fields the
     * declared list did not already name.
     *
     * MERGED, never chosen between. Choosing the schema instead would drop any declared
     * binding under a model-backed prefix that the model's own table has no column for.
     * The declared list is the layer of RULES over the graph, not a substitute for it.
     *
     * Deduplicated by key with the declared entry winning, which is the same precedence
     * `resolve()` applies: a declared key keeps its hand-written label and its rules.
     *
     * A collection node has no fields of its own until a line model exists: a field of a
     * line has no meaning outside a row, so there is nothing here to introspect columns
     * from. Its columns are declared inside its own collection binding instead, which is
     * why the declared lookup finds nothing for it — expected, not a bug to chase.
     *
     * @param  array<string, mixed>  $node
     * @param  array<int, array<string, mixed>>  $declared
     * @return array<int, array<string, mixed>>
     */
    private static function fieldsOf(string $id, array $node, array $declared, bool $hasTenant): array
    {
        if (! $hasTenant || ($node['model'] ?? null) === null) {
            return $declared;
        }

        $byKey = [];

        foreach ($declared as $field) {
            $byKey[$field['key']] = $field;
        }

        foreach (NodeFields::for($id, $node) as $field) {
            $byKey[$field['key']] ??= $field;
        }

        return array_values($byKey);
    }

    /**
     * Whether the document can actually reach a node's values at print time.
     *
     * The same predicate `DocumentDataset::isOwned()` applies: a `singleton` is reached
     * without the document, and a non-null `via` is a path walked from the document root.
     * A node with a model and no `via` is reachable from nothing, and the editor keeps it
     * out of the "Model" select rather than offering fields that can only ever print
     * blank.
     *
     * A node with `model => null` stays reachable: its fields come from the declared
     * list, and its values come from the declared fixture.
     *
     * @param  array<string, mixed>  $node
     */
    private static function isReachable(array $node): bool
    {
        return ($node['model'] ?? null) === null
            || ($node['singleton'] ?? false)
            || ($node['via'] ?? null) !== null;
    }

    /**
     * The memo key.
     *
     * `label`, locale and tenant are not enough on their own: a caller can pass a
     * modified COPY of a type — the idiom the validation tests use — and the graph of the
     * unmodified one would be handed back, so a synthetic declared binding or an added
     * node would simply not be there. The nodes and the declared bindings are therefore
     * hashed into the key, because between them they decide every field the graph offers.
     * Nothing else about a type does.
     */
    private static function memoKey(TemplateType $type): string
    {
        return implode('|', [
            $type->code,
            app()->getLocale(),
            (string) app(TenantResolver::class)->currentId(),
            md5(serialize([$type->nodes, $type->bindings])),
        ]);
    }

    /** Everything before the last dot: `adquirente.nif` belongs to `adquirente`. */
    private static function prefixOf(string $key): string
    {
        $lastDot = strrpos($key, '.');

        return $lastDot === false ? $key : substr($key, 0, $lastDot);
    }

    /**
     * Test seam: the memo is per-request in production, and a suite that adds a column to
     * a tenant table between two reads of the same type has to be able to clear it.
     */
    public static function flush(): void
    {
        self::$memo = [];
    }
}
