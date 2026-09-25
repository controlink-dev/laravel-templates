<?php

namespace Controlink\Templates\Data;

use BackedEnum;
use Controlink\Templates\Types\TemplateType;
use DateTimeInterface;
use UnitEnum;

/**
 * The GRAPH-OWNED portion of the `key => value` map a Twig render is fed, built from a
 * real document.
 *
 * It is not the whole dataset and it can never be. `isOwned()` skips every node the
 * document cannot reach — a node with no model, and one with a model but no `singleton`
 * and no `via` — so whatever a host's own document-identity and totals nodes declare
 * stays absent from what comes back. Twig runs with `strict_variables => true`, so
 * wiring this in as *the* dataset produces a render failure on a host's own binding
 * rather than a blank field: **the host has to supply its own declared nodes and merge
 * them with what this returns.**
 *
 * Three things worth knowing before changing it:
 *
 * - **The traversal goes through the MODEL, never the table.** `data_get($root, 'entity.name')`
 *   reads `$root->entity->name`, and any decrypting trait on the model decrypts on the
 *   way out. A raw table read would return ciphertext and the document would print it.
 * - **Values leave here raw, but printable.** Formatting is the Twig filter's job in the
 *   host's compositor. The one exception stays as it is: the fixture that goes to the
 *   CANVAS arrives already formatted, because the canvas has no Twig. Raw does not mean
 *   "whatever the model returns", though — see `printable()`: an object Twig cannot
 *   print is a render failure, not a blank field.
 *
 * A node the document OWNS (a `singleton`, or one with a declared `via`) always
 * contributes every one of its fields, even when the path resolves to nothing — an
 * empty string, never an absent key. `TemplateRenderer` builds its Twig environment
 * with `strict_variables => true`: a binding the dataset does not carry is a render
 * failure, not something Twig papers over with a blank space. An entity with no address
 * is an ordinary state, and it must still print the field blank rather than crash the
 * document. Only a node with neither is not the graph's to fill, and is skipped
 * outright.
 */
class DocumentDataset
{
    /**
     * @param  object  $root  the issued document
     * @return array<string, mixed>
     */
    public function for(object $root, TemplateType $type): array
    {
        $dataset      = [];
        $fieldsByNode = $this->fieldsByNode($type);

        foreach ($type->nodes as $id => $node) {
            if (! $this->isOwned($node)) {
                // Not ours to fill: the declared part of the document, with no model and
                // no path — its values never come from the graph.
                continue;
            }

            $source = $this->sourceOf($root, $node);

            foreach ($fieldsByNode[$id] ?? [] as $field) {
                $column = substr($field, strlen($id) + 1);

                // The node IS ours, so every field it offers gets a key — '' rather
                // than a value when the path resolved to nothing, per the class
                // docblock's `strict_variables` note.
                $dataset[$field] = $source === null ? '' : $this->printable(data_get($source, $column));
            }
        }

        return $dataset;
    }

    /**
     * Whether a node is the document's to fill: a `singleton`, reached without the
     * document, or a NON-NULL `via` path — even one that turns out to resolve to nothing
     * at run time. A node with neither is the hand-written part of the document and
     * never the graph's, or a line-scoped model whose `via` is declared null because it
     * has to be resolved per row rather than from the document root. Both cases are
     * skipped, and `DocumentGraph::for()` reports the same thing as `reachable`.
     *
     * @param  array<string, mixed>  $node
     */
    private function isOwned(array $node): bool
    {
        return is_string($node['singleton'] ?? null) || ($node['via'] ?? null) !== null;
    }

    /**
     * A value in a form Twig can print.
     *
     * Twig prints strings and numbers and nothing else, and the graph offers whatever the
     * model's casts produce: an enum cast is offered as-is, and a date cast arrives as a
     * `Carbon`. Handing either to the template is a render failure — with
     * `strict_variables` it does not degrade to a blank field — so anything that is not a
     * scalar is reduced here to the string form it has. Scalars and null pass through
     * untouched, because the host's own formatting filters still need a real number and
     * a real date string.
     */
    private function printable(mixed $value): mixed
    {
        return match (true) {
            $value === null, is_scalar($value)  => $value,
            $value instanceof BackedEnum        => $value->value,
            $value instanceof UnitEnum          => $value->name,
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),

            is_object($value) && method_exists($value, '__toString') => (string) $value,

            // An array, or an object with no string form, has no printable value at all:
            // '' is what the `strict_variables` contract in the class docblock promises
            // for a field the document cannot fill.
            default => '',
        };
    }

    /**
     * Where an owned node's values come from: a declared static accessor for a
     * singleton, or the traversal of its declared path from the document root. `null`
     * here means the path resolved to nothing (an entity with no address), not that the
     * node is unowned — that distinction is `isOwned()`'s, decided before this is ever
     * called.
     *
     * @param  array<string, mixed>  $node
     */
    private function sourceOf(object $root, array $node): mixed
    {
        if (is_string($node['singleton'] ?? null)) {
            return ($node['model'])::{$node['singleton']}();
        }

        // No `via` in a well-formed type resolves to a plural relation without a
        // numeric index — a plural `via` would silently turn into null-per-field here
        // rather than flag the misconfiguration, so the first one declared should get
        // its own guard.
        return data_get($root, $node['via']);
    }

    /**
     * Every node's field keys, indexed by node id — the graph is walked once per
     * document rather than once per node.
     *
     * @return array<string, array<int, string>>
     */
    private function fieldsByNode(TemplateType $type): array
    {
        $fields = [];

        foreach (DocumentGraph::for($type) as $node) {
            $fields[$node['id']] = array_column($node['fields'], 'key');
        }

        return $fields;
    }
}
