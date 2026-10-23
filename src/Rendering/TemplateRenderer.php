<?php

namespace Controlink\Templates\Rendering;

use RuntimeException;
use Throwable;
use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Sandbox\SecurityError;
use Twig\Sandbox\SecurityPolicy;
use Twig\TwigFilter;

/**
 * Renders a template against a dataset.
 *
 * **Twig and never Blade.** Blade compiles to PHP, so a user-editable template
 * compiled by Blade is arbitrary code execution with extra steps. Twig runs
 * interpreted, inside a sandbox, from an `ArrayLoader` that has no filesystem access.
 *
 * The sandbox is closed rather than open: one tag, a fixed list of filters, no
 * functions, no object methods and no object properties. The dataset is a plain array,
 * so there is no object for a template to reach into even if the policy let it.
 *
 * A template can only have come from the editor, which offers no way to write template
 * syntax at all — so a policy violation is not a user mistake to report but evidence
 * that something built a template by another route, and it is recorded as a render
 * failure.
 */
class TemplateRenderer
{
    /**
     * The only tag the policy allows.
     *
     * `for` exists for the line table and nothing else. `if` is deliberately absent:
     * conditional logic in templates is excluded from the module's scope, and
     * allowing the tag would be the whole of that exclusion undone.
     */
    private const ALLOWED_TAGS = ['for'];

    public function __construct(private readonly FilterRegistry $filters, private readonly string $autoescape = 'html') {}

    /**
     * @param  array<string, mixed>  $dataset
     *
     * @throws RuntimeException on a policy violation or a template error
     */
    public function render(string $template, array $dataset): string
    {
        $environment = $this->environment($template);

        try {
            return $environment->render('form', $this->flattenForTwig($dataset));
        } catch (SecurityError $error) {
            // Not a validation message: the editor cannot produce this.
            throw new RuntimeException(
                'The template violated the sandbox policy: '.$error->getMessage(),
                previous: $error,
            );
        } catch (Throwable $error) {
            throw new RuntimeException(
                'The template failed to render: '.$error->getMessage(),
                previous: $error,
            );
        }
    }

    private function environment(string $template): Environment
    {
        $environment = new Environment(new ArrayLoader(['form' => $template]), [
            // Values are escaped as HTML: a company name carrying `&` or `<` has to
            // reach the page as text, not as markup.
            'autoescape' => $this->autoescape === 'false' ? false : $this->autoescape,
            // A binding the dataset does not carry is a system error, not something to
            // paper over with a blank space.
            'strict_variables' => true,
            'cache'            => false,
            'debug'            => false,
        ]);

        $environment->addExtension(new SandboxExtension(
            new SecurityPolicy(
                allowedTags: self::ALLOWED_TAGS,
                allowedFilters: $this->allowedFilters(),
                allowedMethods: [],
                allowedProperties: [],
                allowedFunctions: [],
            ),
            sandboxed: true,
        ));

        foreach ($this->filters() as $name => $callback) {
            $environment->addFilter(new TwigFilter($name, $callback));
        }

        return $environment;
    }

    /**
     * The filter names the policy allows: the closed list from config, plus `escape`,
     * which Twig itself inserts for every printed value when autoescaping is on and
     * which the sandbox would otherwise reject.
     *
     * @return array<int, string>
     */
    private function allowedFilters(): array
    {
        return array_merge($this->filters->names(), ['escape']);
    }

    /**
     * One value through one named filter, without a template around it.
     *
     * For callers that need the same string the renderer would produce but are not
     * rendering a document — an editor's canvas preview being the obvious one. It
     * exists so the formatting lives in exactly one place: a second copy of the
     * currency or number rules would be a second source of truth to keep in sync.
     */
    public function formatValue(mixed $value, string $format, array $arguments = []): string
    {
        $filter = $this->filters()[$format] ?? null;

        return $filter ? $filter($value, ...array_values($arguments)) : (string) $value;
    }

    /**
     * The formatting filters, one per name in `config/templates.php`'s `formats`.
     *
     * No filter writes a currency symbol, a separator or a date order by hand: `money`
     * goes through `MoneyFormatter`, and the others go through ICU too.
     *
     * @return array<string, callable>
     */
    private function filters(): array
    {
        return $this->filters->filters();
    }

    /**
     * Bindings are dotted keys (`emitente.nif`) but a template reads them as nested
     * lookups, so the flat dataset becomes a tree.
     *
     * @param  array<string, mixed>  $dataset
     * @return array<string, mixed>
     */
    private function flattenForTwig(array $dataset): array
    {
        $tree = [];

        foreach ($dataset as $key => $value) {
            $value    = $this->nestRows($value);
            $segments = explode('.', (string) $key);
            $cursor   = &$tree;

            foreach ($segments as $index => $segment) {
                if ($index === count($segments) - 1) {
                    $cursor[$segment] = $value;

                    continue;
                }

                if (! isset($cursor[$segment]) || ! is_array($cursor[$segment])) {
                    $cursor[$segment] = [];
                }

                $cursor = &$cursor[$segment];
            }

            unset($cursor);
        }

        return $tree;
    }

    /**
     * A list of rows gets each row's dotted keys (linked columns: `family.name`) nested
     * as well, so `linha.family.name` resolves under strict variables. The flat key stays
     * because formulas read rows flat.
     */
    private function nestRows(mixed $value): mixed
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            return $value;
        }

        foreach ($value as $index => $row) {
            if (is_array($row) && ! array_is_list($row)) {
                $value[$index] = $this->nestRowKeys($row);
            }
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function nestRowKeys(array $row): array
    {
        $nested = $row;

        foreach ($row as $key => $value) {
            $key = (string) $key;

            if (! str_contains($key, '.')) {
                continue;
            }

            $segments = explode('.', $key);

            // A scalar already lives under the first segment: that key stays flat only.
            if (array_key_exists($segments[0], $row) && ! is_array($row[$segments[0]])) {
                continue;
            }

            $cursor = &$nested;

            foreach ($segments as $index => $segment) {
                if ($index === count($segments) - 1) {
                    $cursor[$segment] = $value;

                    break;
                }

                if (! isset($cursor[$segment]) || ! is_array($cursor[$segment])) {
                    $cursor[$segment] = [];
                }

                $cursor = &$cursor[$segment];
            }

            unset($cursor);
        }

        return $nested;
    }
}
