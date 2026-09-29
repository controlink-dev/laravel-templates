<?php

namespace Controlink\Templates\Tests\Concerns;

use Controlink\Templates\Types\TemplateType;

/**
 * The declared fixture of a type, repeated up to `fixtureRepeats`, with no schema
 * reads and no document graph. The package's own tests render declared fixtures only.
 */
trait BuildsFixtureDataset
{
    /**
     * @return array<string, mixed>
     */
    protected function fixtureDataset(TemplateType $type, ?int $repeats = null): array
    {
        $fixture = $type->fixture;
        $repeats = max(1, $repeats ?? $type->fixtureRepeats);

        if ($repeats === 1) {
            return $fixture;
        }

        foreach ($fixture as $key => $rows) {
            if (! is_array($rows) || $rows === []) {
                continue;
            }

            $repeated = [];

            for ($pass = 0; $pass < $repeats; $pass++) {
                foreach ($rows as $row) {
                    $first = array_key_first($row);

                    if ($first !== null) {
                        $row[$first] = $row[$first].'-'.str_pad((string) ($pass + 1), 2, '0', STR_PAD_LEFT);
                    }

                    $repeated[] = $row;
                }
            }

            $fixture[$key] = $repeated;
        }

        return $fixture;
    }
}
