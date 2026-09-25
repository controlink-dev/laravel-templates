<?php

namespace Controlink\Templates\Http\Controllers;

use Controlink\Templates\Contracts\MoneyFormatter;
use Controlink\Templates\Data\DocumentGraph;
use Controlink\Templates\Data\FixtureDataset;
use Controlink\Templates\Rendering\TemplateRenderer;
use Controlink\Templates\Templates;
use Controlink\Templates\Types\TemplateType;
use Controlink\Templates\Types\TypeCatalogue;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * The document type's catalogue: the fields available for placement, the legibility
 * limits and the fixture.
 *
 * One endpoint instead of several. The editor needs the field list, the legibility
 * limits and a realistic fixture value all at once to draw a single block, so separate
 * round trips would only be separate ways for them to arrive out of step.
 *
 * The legibility limits are readable, never writable here: they are declared by the
 * host in a template type's `pdf.visibility_limits`.
 */
class CatalogueController extends Controller
{
    use AuthorizesRequests;

    public function show(string $type): JsonResponse
    {
        $this->authorize('viewAny', config('templates.models.template'));

        abort_if(! app(TypeCatalogue::class)->has($type), Response::HTTP_NOT_FOUND);

        $documentType = Templates::type($type);

        return response()->json([
            'bindings' => array_map(
                fn (array $binding): array => [
                    'key'       => $binding['key'],
                    'label'     => $binding['label'],
                    'type'      => $binding['type'],
                    'mandatory' => (bool) ($binding['mandatory'] ?? false),
                    'legal'     => (bool) ($binding['legal'] ?? false),
                    // Only legal fields have a scope; sending null for the rest keeps
                    // the editor from having to know that rule twice.
                    'scope'          => ($binding['legal'] ?? false) ? ($binding['scope'] ?? null) : null,
                    'default_format' => $binding['default_format'] ?? '',
                    'columns'        => $binding['columns'] ?? null,
                ],
                $documentType->bindings,
            ),
            /*
             * The document's graph: the nodes the editor offers as "Model", each with the
             * fields it offers as "Data source".
             *
             * `bindings` above stays exactly as it is and is NOT replaced by this: it is
             * the layer of rules over the graph — `mandatory`, `legal`, `scope`, the default
             * format and the hand-written fixture all come from it, and it is the host's
             * own declared list, not something the schema can know.
             */
            'nodes'             => DocumentGraph::for($documentType),
            'formats'           => (array) config('templates.formats'),
            'code_types'        => (array) config('templates.code_types'),
            'visibility_limits' => $documentType->pdf['visibility_limits'] ?? [],
            'rule_widths'       => (array) config('templates.rule_widths'),
            'image_max_bytes'   => (int) config('templates.image_max_bytes'),
            'fixture'           => $this->presentedFixture($documentType),
            'zones'             => $documentType->initialLayout['zones'],
        ]);
    }

    /**
     * The fixture as the canvas should show it.
     *
     * Monetary values are rendered here, by `MoneyFormatter`, and reach the browser as
     * finished strings. No symbol, side or separator is ever decided in JavaScript or in
     * a template.
     *
     * Collections are included, formatted column by column: the line table draws one
     * sample row on the canvas, and without the collection it would fall back to
     * printing each column's own label.
     *
     * `fixture_line_repeats` is forced to 1 for this one caller — see
     * `FixtureDataset::for()`'s `$repeats` parameter — because the canvas draws a single
     * sample row, not a paginated preview.
     *
     * @return array<string, string|list<array<string, string>>>
     */
    private function presentedFixture(TemplateType $type): array
    {
        $monetary = array_column(
            array_filter($type->bindings, fn (array $binding): bool => $binding['type'] === 'money'),
            'key',
        );

        $formatter = app(MoneyFormatter::class);
        $presented = [];

        $fixture = app(FixtureDataset::class)->for($type, 1);

        foreach ($fixture as $key => $value) {
            if (is_array($value)) {
                $presented[$key] = $this->presentedCollection($type, (string) $key, $value);

                continue;
            }

            $presented[$key] = in_array($key, $monetary, true)
                ? $formatter->format((float) $value)
                : (string) $value;
        }

        return $presented;
    }

    /**
     * One fixture collection, each column through the format its binding declares.
     *
     * The renderer's own filters do the work, so the sample row on the canvas and the
     * cell in the printed document are formatted by one implementation rather than two.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array<string, string>>
     */
    private function presentedCollection(TemplateType $type, string $key, array $rows): array
    {
        $binding = $type->binding($key);
        $formats = [];

        foreach ($binding['columns'] ?? [] as $column) {
            $formats[$column['key']] = $column['default_format'] ?? '';
        }

        $renderer  = app(TemplateRenderer::class);
        $presented = [];

        foreach ($rows as $row) {
            $cells = [];

            foreach ((array) $row as $column => $value) {
                $cells[$column] = $renderer->formatValue($value, $formats[$column] ?? '');
            }

            $presented[] = $cells;
        }

        return $presented;
    }
}
