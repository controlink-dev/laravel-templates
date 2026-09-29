<?php

namespace Controlink\Templates\Versions;

/** SHA-256 of `project_data` with sorted keys, so key order never changes the hash. */
final class CanonicalHash
{
    /**
     * @param array<string, mixed>      $projectData
     * @param array<string, mixed>|null $geometry    part of the reproducible layout, so it is hashed too
     */
    public static function of(array $projectData, ?array $geometry = null): string
    {
        $canon = self::canonicalise($projectData);

        // Geometry is appended only when present, so the hash of every layout that
        // predates geometry (or has none) stays exactly what it always was.
        return hash('sha256', ($geometry === null || $geometry === []) ? $canon : $canon.'|'.self::canonicalise($geometry));
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
