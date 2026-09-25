<?php

namespace Controlink\Templates\Data;

use Controlink\Templates\Contracts\EncryptedFieldMap;
use Illuminate\Database\Eloquent\Model;

/**
 * The fields a node offers.
 *
 * The exclusions are RULES and never a list of allowed columns: a whitelist would be
 * maintenance on every new column, which is precisely what dynamic field discovery
 * forbids. So a new blind index excludes itself, because the exclusion reads the model's
 * own encrypted-field map, and a new plain column is offered on its own, because nothing
 * here enumerates columns by name.
 *
 * A key is `<node>.<column>` — the same dotted shape the declared bindings already use, so
 * the validator, the compositor and the Twig template cannot tell a calculated field from
 * a declared one.
 */
class NodeFields
{
    /** Columns that are plumbing, not values a document prints. */
    private const EXCLUDED = ['id', 'created_at', 'updated_at', 'deleted_at'];

    /**
     * @param  array<string, mixed>  $node  one entry of the type's `nodes`
     * @return array<int, array{key: string, label: string, label_missing: bool, type: string, is_boolean: bool, encrypted: bool, length: ?int, scale: ?int}>
     */
    public static function for(string $nodeId, array $node): array
    {
        $class = $node['model'] ?? null;

        if ($class === null) {
            return [];
        }

        /** @var Model $model */
        $model = new $class;

        $map          = app(EncryptedFieldMap::class)->for($class);
        $encrypted    = $map['encrypted'];
        $blindIndexes = $map['blind_indexes'];

        $hidden = $model->getHidden();
        $fields = [];

        foreach (TableColumns::for($model) as $name => $column) {
            if (self::isExcluded($name, $blindIndexes, $hidden)) {
                continue;
            }

            $label = self::resolveLabel($node, $name);

            $fields[] = [
                'key'           => $nodeId.'.'.$name,
                'label'         => $label['label'],
                'label_missing' => $label['missing'],
                'type'          => $column->bindingType,
                // A boolean is a `text` binding (it prints as a translated yes/no
                // label), so the type alone cannot say it is one — and its FIXTURE is
                // the yes label rather than sample prose. Carried separately for
                // exactly that.
                'is_boolean' => $column->isBoolean,
                'encrypted'  => in_array($name, $encrypted, true),
                // Carried so the fixture built from this field is sized to the real
                // column instead of a guessed default: the sample is what the user
                // lays the box out against on the canvas, and it is also the only
                // thing that gives a calculated field a dataset key at all — without
                // one, `strict_variables` makes the preview throw.
                'length' => $column->length,
                'scale'  => $column->scale,
            ];
        }

        return $fields;
    }

    /**
     * @param  array<int, string>  $blindIndexes
     * @param  array<int, string>  $hidden
     */
    private static function isExcluded(string $name, array $blindIndexes, array $hidden): bool
    {
        return in_array($name, self::EXCLUDED, true)
            || in_array($name, $blindIndexes, true)
            || in_array($name, $hidden, true)
            || in_array($name, (array) config('templates.graph.excluded_columns', []), true)
            || str_ends_with($name, '_id');
    }

    /**
     * The label comes from the translations that already describe the model's own screens.
     * `nodes.<id>.lang` (declared on the type) says which language file's `fields.*` keys
     * describe a node's model, so there is no second mapping to keep.
     *
     * A column with no translation still gets offered — a field with no pretty label is
     * still a field a document can bind to — but falls back to its own name and carries
     * `missing: true`. The caller turns that flag into `label_missing` on the field, which
     * is what lets a test assert "no offered field is missing a translation" without
     * relying on the fallback string happening to collide with anything.
     *
     * @param  array<string, mixed>  $node
     * @return array{label: string, missing: bool}
     */
    private static function resolveLabel(array $node, string $name): array
    {
        $lang = $node['lang'] ?? null;

        if (! is_string($lang) || $lang === '') {
            return ['label' => $name, 'missing' => true];
        }

        $key        = $lang.'.fields.'.$name;
        $translated = __($key);

        return $translated === $key
            ? ['label' => $name, 'missing' => true]
            : ['label' => $translated, 'missing' => false];
    }
}
