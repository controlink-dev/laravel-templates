<?php

namespace Controlink\Templates\Formulas;

use Controlink\Templates\Exceptions\UnsafeTemplateException;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * The formulas a layout carries, as the compositor needs them (CGE-37).
 *
 * The editor exports a formula next to where its result prints: a data field bound
 * to `formula.<id>` carries its source in `data-tpl-formula`, and a line table's
 * formula column carries it as the `formula` property of its entry in
 * `data-tpl-columns`. Both are read here, evaluated into the dataset, and removed
 * from the markup — because the source is user-typed text and the markup is about
 * to go through the HTML guard and Twig. A `{{` or `javascript:` inside a text
 * literal must cost nothing, so the guard and the template engine must never see it.
 *
 * Reading goes through DOM (decoded attribute values, like PageStructure). Removing
 * does not: re-serialising the body through DOM rewrites markup PageStructure and
 * Twig depend on, so the two attributes are cut out of the original string and
 * nothing else in it moves. A layout without formulas comes back byte-identical.
 */
final class FormulaSet
{
    /*
     * The id starts with a letter: the key is printed as a Twig path (`{{ formula.<id> }}`,
     * `{{ linha.formula_<id> }}`), and Twig reads `formula.3b…` as the number 3 followed by
     * a name — a layout that saved but could not print.
     */
    private const FIELD_KEY = '/^formula\.[a-z][a-z0-9]{5,11}$/';

    private const COLUMN_KEY = '/^formula_[a-z][a-z0-9]{5,11}$/';

    /** An opening tag, with quoted values consumed whole so a `>` inside one does not end it. */
    private const TAG = '/<[a-zA-Z][^\s\/>]*(?:\s+[^\s"\'>\/=]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?)*\s*\/?>/';

    /** One attribute inside an opening tag: leading space, name, and optional value. */
    private const ATTRIBUTE = '/(\s+)([^\s"\'>\/=]+)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?/';

    /**
     * @param  array<string, string>  $fields
     * @param  list<array{collection: string, key: string, source: string}>  $columns
     */
    private function __construct(
        private readonly array $fields,
        private readonly array $columns,
        private readonly string $strippedHtml,
    ) {}

    /**
     * @throws UnsafeTemplateException when a formula source survives the removal, or the
     *                                 markup cannot be scanned (regex engine limit)
     */
    public static function fromHtml(string $html): self
    {
        // Nothing to read and nothing to remove: the markup is left exactly as it was.
        if (stripos($html, 'formula') === false) {
            return new self([], [], $html);
        }

        [$fields, $columns] = self::read($html);
        $stripped = self::strip($html);

        // The removal is a string operation; DOM is the judge of whether it worked.
        // A source the pattern missed would reach Twig, so that is refused outright.
        if (self::carriesSources($stripped)) {
            throw new UnsafeTemplateException('Template HTML carries a formula source that could not be removed.');
        }

        return new self($fields, $columns, $stripped);
    }

    /** Whether a data field's binding is a formula key, `formula.<id>`. */
    public static function isFieldKey(string $key): bool
    {
        return preg_match(self::FIELD_KEY, $key) === 1;
    }

    /** Whether a line-table column key is a formula key, `formula_<id>`. */
    public static function isColumnKey(string $key): bool
    {
        return preg_match(self::COLUMN_KEY, $key) === 1;
    }

    /**
     * Data-field formulas, binding key ⇒ source. Only keys of the exported shape are
     * listed; a field with a valid key but no source maps to '' and so prints empty.
     *
     * @return array<string, string>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /** @return list<array{collection: string, key: string, source: string}> */
    public function columns(): array
    {
        return $this->columns;
    }

    /** The markup with every `data-tpl-formula` and every column `formula` property removed. */
    public function strippedHtml(): string
    {
        return $this->strippedHtml;
    }

    /**
     * The dataset with every result added: `formula.<id>` for each field, and
     * `formula_<id>` on each row of a formula column's collection.
     *
     * Everything is evaluated against the dataset as it arrived — the whole
     * collection, before the compositor slices it per sheet — so a SUM is the
     * document's total on every sheet, and no formula can see another's result.
     *
     * @param  array<string, mixed>  $dataset
     * @return array<string, mixed>
     */
    public function inject(array $dataset): array
    {
        $original = $dataset;

        foreach ($this->fields as $key => $source) {
            $dataset[$key] = Formula::evaluate($source, $original);
        }

        foreach ($this->columns as $column) {
            $rows = $original[$column['collection']] ?? null;

            // A table bound to a collection the dataset lacks has no rows to fill.
            if (! is_array($rows)) {
                continue;
            }

            foreach ($rows as $index => $row) {
                if (is_array($row)) {
                    $dataset[$column['collection']][$index][$column['key']] = Formula::evaluate(
                        $column['source'],
                        $original,
                        $row,
                        $column['collection'],
                    );
                }
            }
        }

        return $dataset;
    }

    /**
     * @return array{0: array<string, string>, 1: list<array{collection: string, key: string, source: string}>}
     */
    private static function read(string $html): array
    {
        $xpath   = new DOMXPath(self::parse($html));
        $fields  = [];
        $columns = [];

        foreach ($xpath->query('//*[@data-tpl-binding]') ?: [] as $node) {
            if ($node instanceof DOMElement && preg_match(self::FIELD_KEY, $node->getAttribute('data-tpl-binding')) === 1) {
                $fields[$node->getAttribute('data-tpl-binding')] = $node->getAttribute('data-tpl-formula');
            }
        }

        foreach ($xpath->query('//*[@data-tpl-type="line-table"][@data-tpl-columns]') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $collection = $node->getAttribute('data-tpl-collection');
            $declared   = json_decode($node->getAttribute('data-tpl-columns'), true);

            foreach (is_array($declared) ? $declared : [] as $column) {
                $key = is_array($column) ? ($column['key'] ?? null) : null;

                if ($collection !== '' && is_string($key) && preg_match(self::COLUMN_KEY, $key) === 1) {
                    $columns[] = [
                        'collection' => $collection,
                        'key'        => $key,
                        'source'     => is_string($column['formula'] ?? null) ? $column['formula'] : '',
                    ];
                }
            }
        }

        return [$fields, $columns];
    }

    /**
     * Cuts the two kinds of source out of every opening tag, attribute by attribute, so
     * text content (`{{ }}`, `{% %}`) and every other attribute keep their exact bytes.
     * Every source goes, whatever its key: a malformed one is still user text.
     */
    private static function strip(string $html): string
    {
        return self::replace(self::TAG, function (array $tag): string {
            if (stripos($tag[0], 'formula') === false) {
                return $tag[0];
            }

            return self::replace(self::ATTRIBUTE, function (array $attribute): string {
                $name = strtolower($attribute[2]);

                if ($name === 'data-tpl-formula') {
                    return '';
                }

                if ($name === 'data-tpl-columns' && isset($attribute[3])) {
                    $columns = self::columnsWithoutSources($attribute[3]);

                    return $columns === null ? $attribute[0] : $attribute[1].'data-tpl-columns="'.$columns.'"';
                }

                return $attribute[0];
            }, $tag[0]);
        }, $html);
    }

    /**
     * The column list re-encoded without `formula`, or null when no column had one
     * (then the attribute is left exactly as it was).
     */
    private static function columnsWithoutSources(string $quoted): ?string
    {
        $value = $quoted[0] === '"' || $quoted[0] === "'" ? substr($quoted, 1, -1) : $quoted;
        $list  = json_decode(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);

        if (! is_array($list)) {
            return null;
        }

        $changed = false;

        foreach ($list as $index => $column) {
            if (is_array($column) && array_key_exists('formula', $column)) {
                unset($list[$index]['formula']);
                $changed = true;
            }
        }

        if (! $changed) {
            return null;
        }

        $json = json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // An empty attribute would silently drop every column of the table.
        if ($json === false) {
            throw new UnsafeTemplateException('A line table\'s columns could not be re-encoded without their formulas.');
        }

        return htmlspecialchars($json, ENT_QUOTES, 'UTF-8');
    }

    /**
     * preg_replace_callback that refuses on an engine failure (backtrack or JIT
     * limit). PHP returns null then, and a null cast to '' would hand the
     * compositor an empty body — a blank document printed with no error — or,
     * in the inner pass, delete a whole tag.
     *
     * @throws UnsafeTemplateException
     */
    private static function replace(string $pattern, callable $callback, string $subject): string
    {
        $result = preg_replace_callback($pattern, $callback, $subject);

        if ($result === null || preg_last_error() !== PREG_NO_ERROR) {
            throw new UnsafeTemplateException('Template HTML could not be scanned for formula sources: '.preg_last_error_msg());
        }

        return $result;
    }

    /** Whether any element still carries a source, as the HTML parser reads it. */
    private static function carriesSources(string $html): bool
    {
        if (stripos($html, 'formula') === false) {
            return false;
        }

        $xpath = new DOMXPath(self::parse($html));

        $sources = $xpath->query('//*[@data-tpl-formula]');

        if ($sources !== false && $sources->length > 0) {
            return true;
        }

        foreach ($xpath->query('//*[@data-tpl-columns]') ?: [] as $node) {
            $declared = $node instanceof DOMElement ? json_decode($node->getAttribute('data-tpl-columns'), true) : null;

            foreach (is_array($declared) ? $declared : [] as $column) {
                if (is_array($column) && array_key_exists('formula', $column)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** The same fragment parse as PageStructure: a wrapper root and libxml kept quiet. */
    private static function parse(string $html): DOMDocument
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        $document->loadHTML(
            '<?xml encoding="utf-8" ?><div id="tpl-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }
}
