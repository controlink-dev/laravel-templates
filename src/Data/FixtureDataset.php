<?php

namespace Controlink\Templates\Data;

use Controlink\Templates\Types\TemplateType;

/**
 * Builds the dataset a preview renders with.
 *
 * Three things separate this from simply reading `fixture` out of the type.
 *
 * The declared rows are repeated until the document spans at least the number of pages
 * the type asks for (`fixtureRepeats`). A header and a footer have to be proven to
 * repeat correctly, and a fixture short enough to fit on one page would let broken
 * pagination pass every test. Repeating a handful of declared rows keeps the type
 * readable — nobody reviews a hundred hand-written lines — while still producing a
 * document that actually paginates.
 *
 * A calculated field — one the graph offers from the database schema but that the type
 * never hand-declares — gets a fixture generated from its column. Not for a truncation
 * rule: there is none. For the preview. The canvas shows a fixture and never a template
 * expression, and `TemplateRenderer` builds its Twig environment with
 * `strict_variables => true` — so a calculated field placed on the canvas with no sample
 * has no dataset key, and the preview throws instead of rendering.
 *
 * And the values stay raw. The formatting filters are what turn a number into money or
 * a date into a date, so pre-formatting here would mean the preview measured a string
 * the real document never contains.
 */
class FixtureDataset
{
    /**
     * The dataset the canvas and the validations read.
     *
     * Two layers, and the declared one is on top: whatever `fixture` says by hand wins,
     * always. A legal field's sample is the type author's choice and stays hand-written.
     * Only a key nobody declared gets a value generated from its column — and an
     * encrypted one among those gets a short declared sample rather than a measured one,
     * because a blob's width is the ciphertext's and not the value's.
     *
     * @return array<string, mixed>
     */
    public function for(TemplateType $type, ?int $repeats = null): array
    {
        $fixture = $type->fixture;
        $repeats = max(1, $repeats ?? $type->fixtureRepeats);

        foreach ($fixture as $key => $value) {
            if (! is_array($value) || $value === []) {
                continue;
            }

            $fixture[$key] = $this->repeat($value, $repeats);
        }

        // `$declared + $generated` keeps the left side on a key collision — PHP's array
        // union never overwrites — so a hand-written value can never be clobbered by a
        // generated one.
        return $fixture + $this->generated($type);
    }

    /**
     * A sample for every field the graph offers and the type does not declare.
     *
     * Returned as the LOWER layer of the union in `for()`, so a declared key is never
     * overwritten — `$declared + $generated` keeps what the left side already has.
     *
     * @return array<string, string>
     */
    private function generated(TemplateType $type): array
    {
        /*
         * No tenant guard here: `DocumentGraph::for()` carries it, because that is the
         * class that reads schemas and it has callers of its own. Outside a resolved
         * tenant the graph offers declared fields only, and this loop then generates
         * nothing new — which is the same answer as before, from the right place.
         */
        $generated = [];

        foreach (DocumentGraph::for($type) as $node) {
            if ($node['collection']) {
                // A collection's rows are declared: there is no line model to read a
                // column from, so there is nothing to generate here.
                continue;
            }

            foreach ($node['fields'] as $field) {
                $generated[$field['key']] = FixtureValue::for(new ColumnDescriptor(
                    name: $field['key'],
                    bindingType: $field['type'],
                    // A boolean prints as a translated yes/no label, so its sample is
                    // that label and not sample prose. The flag travels on the field
                    // because `type` alone cannot say it — a boolean IS a `text`
                    // binding.
                    isBoolean: (bool) ($field['is_boolean'] ?? false),
                    // An encrypted column is a blob: what the driver reports is the
                    // width of the CIPHERTEXT, which is not the width of the value a
                    // document prints. There is no length here to measure, so the
                    // sample is a short declared one rather than a measurement
                    // pretending to be one.
                    length: ($field['encrypted'] ?? false)
                        ? FixtureValue::ENCRYPTED_TEXT_LENGTH
                        : ($field['length'] ?? null),
                    scale: $field['scale'] ?? null,
                ));
            }
        }

        return $generated;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function repeat(array $rows, int $times): array
    {
        if ($times === 1) {
            return $rows;
        }

        $repeated = [];

        for ($pass = 0; $pass < $times; $pass++) {
            foreach ($rows as $row) {
                // The pass is suffixed onto the first column of the row so that no two
                // rows are identical: a rendered document where every row reads the
                // same cannot show a misplaced page break. The first column is a
                // structural choice, not a hard-coded name — a type's own collection
                // may not have a `codigo` column at all.
                $first = array_key_first($row);

                if ($first !== null) {
                    $row[$first] = $row[$first].'-'.str_pad((string) ($pass + 1), 2, '0', STR_PAD_LEFT);
                }

                $repeated[] = $row;
            }
        }

        return $repeated;
    }
}
