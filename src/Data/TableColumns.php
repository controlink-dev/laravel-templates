<?php

namespace Controlink\Templates\Data;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * The columns of a model's table, as descriptors.
 *
 * This class reports EVERY column and excludes nothing — the exclusions are
 * `NodeFields`' job, and keeping them apart is what lets the exclusion rules be tested
 * without a database and this introspection be tested without them.
 */
final class TableColumns
{
    /**
     * @return array<string, ColumnDescriptor>
     */
    public static function for(Model $model): array
    {
        $casts   = $model->getCasts();
        $columns = [];

        foreach (Schema::connection($model->getConnectionName())->getColumns($model->getTable()) as $column) {
            $columns[$column['name']] = self::describe($column, $casts);
        }

        return $columns;
    }

    /**
     * @param  array<string, mixed>  $column  one row of `Schema::getColumns()`
     * @param  array<string, string>  $casts  the model's `getCasts()`
     */
    public static function describe(array $column, array $casts): ColumnDescriptor
    {
        $name     = (string) $column['name'];
        $type     = strtolower((string) ($column['type'] ?? ''));
        $typeName = strtolower((string) ($column['type_name'] ?? $type));
        $cast     = strtolower((string) ($casts[$name] ?? ''));

        $isBoolean = self::isBoolean($type, $typeName, $cast);

        return new ColumnDescriptor(
            name: $name,
            bindingType: self::bindingType($typeName, $cast, $isBoolean),
            isBoolean: $isBoolean,
            length: self::lengthOf($type, $typeName),
            scale: self::scaleOf($type, $typeName),
            nullable: (bool) ($column['nullable'] ?? false),
        );
    }

    /**
     * A cast wins over the column's own type: it is the model's stated intent, and a date
     * kept in a string column is still a date to everything that reads it. A boolean,
     * regardless of how it arrived (cast, type name, or `tinyint(1)`), is always a `text`
     * binding — it prints as a translated yes/no label. The boolean check must come
     * before the numeric type list, because MySQL reports both booleans and unsigned
     * tinyint as `tinyint` type name.
     */
    private static function bindingType(string $typeName, string $cast, bool $isBoolean): string
    {
        // A boolean is always text, regardless of how it was declared.
        if ($isBoolean) {
            return 'text';
        }

        $castRoot = explode(':', $cast)[0];

        if (in_array($castRoot, ['date', 'datetime', 'immutable_date', 'immutable_datetime', 'timestamp'], true)) {
            return 'date';
        }

        if (in_array($castRoot, ['decimal', 'float', 'double', 'integer', 'int'], true)) {
            return 'number';
        }

        if (in_array($typeName, ['date', 'datetime', 'timestamp'], true)) {
            return 'date';
        }

        if (in_array($typeName, ['decimal', 'numeric', 'float', 'double', 'int', 'integer', 'bigint', 'smallint', 'mediumint', 'tinyint'], true)) {
            return 'number';
        }

        // Everything else prints as text.
        return 'text';
    }

    private static function isBoolean(string $type, string $typeName, string $cast): bool
    {
        return $cast === 'boolean' || $cast === 'bool' || $typeName === 'boolean' || $type === 'tinyint(1)';
    }

    /**
     * The declared length, when the driver reports one. MySQL renders it into the type
     * (`varchar(150)`); SQLite does not (`varchar`), and then this is null rather than a
     * guess.
     */
    private static function lengthOf(string $type, string $typeName): ?int
    {
        if (! in_array($typeName, ['varchar', 'char', 'text', 'string'], true)) {
            return null;
        }

        return preg_match('/\((\d+)\)/', $type, $found) === 1 ? (int) $found[1] : null;
    }

    /** The decimal scale, when the driver reports one — `decimal(12,4)` → 4. */
    private static function scaleOf(string $type, string $typeName): ?int
    {
        if (! in_array($typeName, ['decimal', 'numeric', 'float', 'double'], true)) {
            return null;
        }

        return preg_match('/\(\d+\s*,\s*(\d+)\)/', $type, $found) === 1 ? (int) $found[1] : null;
    }
}
