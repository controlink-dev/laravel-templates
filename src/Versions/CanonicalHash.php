<?php

namespace Controlink\Templates\Versions;

/** SHA-256 of `project_data` with sorted keys, so key order never changes the hash. */
final class CanonicalHash
{
    /** @param array<string, mixed> $projectData */
    public static function of(array $projectData): string
    {
        return hash('sha256', self::canonicalise($projectData));
    }

    /** @param array<string, mixed> $projectData */
    public static function canonicalise(array $projectData): string
    {
        $sort = function (array $value) use (&$sort): array {
            if (! array_is_list($value)) {
                ksort($value);
            }

            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $value[$key] = $sort($item);
                }
            }

            return $value;
        };

        return json_encode($sort($projectData), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
