# laravel-templates

## What it is

`controlink/laravel-templates` is a **stateless toolkit** a Laravel host uses to build
versioned document templates. It gives the host three things and owns no state of its
own:

1. an embeddable **GrapesJS editor** component for laying a document out on an A4/A5
   canvas (or a roll/talão), as a Blade component the host drops into its own page;
2. a **pure rendering pipeline** — compose a saved layout with a data payload through a
   Twig sandbox, render it to PDF through a Gotenberg engine, and validate the result —
   reached through one facade, `Toolkit\Documents`;
3. a **type catalogue and a reproducibility conformance-kit** the host declares its
   document types against and tests its own persistence with.

The host owns everything stateful: the `Template`/`TemplateVersion` Eloquent models, the
database and its migrations, the HTTP routes and controllers the editor talks to, the
authorization policy, the version lifecycle, and auditing. The package reaches into none
of it — see **What the host owns** below, and `docs/INTEGRATION.md` for how to wire it.

> **Coming from phase 1?** This package used to ship the models, routes, controllers and
> migrations itself. It no longer does. See **Breaking changes from phase 1** at the end.

## Requirements

- PHP `^8.1`.
- Laravel `^10.48 || ^11.0 || ^12.0 || ^13.0`.
- `ext-json`, `ext-intl`, `ext-dom` — required unconditionally (`composer.json`).

Only for template types declared `output => 'pdf'`, the host additionally needs, at
render/activation time:

- **Gotenberg 8** reachable over HTTP (`templates.pdf.gotenberg.url`) — the engine that
  turns composed HTML into a PDF.
- **poppler-utils** on the server `PATH` (`pdftotext`, `pdftoppm`, `pdfinfo`) — used by
  the render-validation layer to measure the PDF the engine returns.
- **`ext-gd`** — used by the same validation layer's raster checks (visual regression
  comparison, code-slot cropping for QR/barcode decoding).

A project that only declares non-PDF types needs none of this.

## Installation

```bash
composer require controlink/laravel-templates
```

For local development against a checkout, point Composer at the path instead of a
registry release, in the host's `composer.json`:

```json
{
    "repositories": [
        { "type": "path", "url": "../laravel-templates" }
    ]
}
```

For real use ahead of a Packagist release, point at the private GitHub repository
instead:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/controlink-dev/laravel-templates" }
    ]
}
```

Then scaffold the host side:

```bash
php artisan templates:install
```

This generates the host's models, migration, editor controller (the seven endpoints),
routes and policy, and publishes the editor bundle (the config is left merged from the
package — see the note under `templates-config` below). It never overwrites an existing
file (pass `--force` to replace), so it is safe to re-run. See `docs/INTEGRATION.md`,
"Quick start", for what it writes and the next steps. Everything it generates is the
host's own code — the package ships none of it.

Prefer to wire it by hand, or publish only part of it? The individual publish tags are
still there:

```bash
# optional — the host can also just read/override config('templates.*') directly.
# NOTE: the shipped default font path is relative to the package's own config file, so a
# published copy in the host's config/ breaks it — fix fonts.*.weights.*.path if you publish.
php artisan vendor:publish --tag=templates-config

# required for the editor component to render with styling and behaviour
php artisan vendor:publish --tag=templates-assets

# optional — overrides for the package's translated strings
php artisan vendor:publish --tag=templates-lang
```

`templates-assets` publishes the pre-built editor bundle (`dist/`, committed in the
package) to `public/vendor/templates`. Re-run it whenever the package updates the bundle;
the editor component cache-busts each file by its own content hash (`Editor::assetUrl()`),
so a stale copy is served under its old URL until republished, not silently mixed with
the new one.

**No package migrations to run.** The package ships no models and no `database/` folder —
the host defines its own `templates` / `template_versions` tables and migrates them its
own way. `docs/INTEGRATION.md` has a reference schema.

## What the package owns vs. what the host owns

| Concern | Owner | Where |
|---|---|---|
| Compose / render / validate / hash a document | **package** | `Toolkit\Documents` |
| Type catalogue + structural validation | **package** | `config('templates.types')`, `TypeCatalogue` |
| The editor UI (canvas, blocks, validation panel) | **package** | `<x-templates::editor>` + `dist/` bundle |
| Reproducibility & immutability conformance-kit | **package** | `Testing\AssertsReproducibility`, `Models\Concerns\GuardsIssuableVersion` |
| `Template` / `TemplateVersion` models + table + migrations | **host** | `docs/INTEGRATION.md` |
| HTTP routes & controllers the editor calls | **host** | `docs/INTEGRATION.md` |
| Authorization policy (`activate`, …) | **host** | `docs/INTEGRATION.md` |
| Version lifecycle (draft → validated → active → archived) | **host**, with the package's guard trait | `docs/INTEGRATION.md` |
| Tenancy (which database, scoping) | **host** | `TenantResolver` contract |
| Auditing | **host** | — (the package no longer ships an `Auditor`) |

## The toolkit facade

`Toolkit\Documents` is the whole pipeline behind one stateless, container-resolved object
(`app(Documents::class)`). It owns no catalogue and no persistence — callers hand it plain
arrays, exactly as the editor exports them:

- **layout** — `{project_data, html, css?}`, as a saved version stores it;
- **fields** — the type's bindings, each `{key, label, type, mandatory?, legal?, scope?,
  default_format?, columns?}`;
- **geometry** — the type's `pdf` block (`paper_format`, `margins`, `max_pages`,
  `visibility_limits`, `render_time_limit_seconds`, `line_row_height_mm`,
  `body_reserve_mm`) plus an optional `zones` entry (`header_height`, `footer_height`).

```php
use Controlink\Templates\Toolkit\Documents;

$documents = app(Documents::class);

// Static (layer-1) validation of a layout against its fields and geometry.
$problems = $documents->validateLayout($layout, $fields, $geometry);
// => array of { code: 'V-10', component_id: '…', message: '…' }

// Render the layout with data to PDF bytes.
$pdf = $documents->render($layout, $data, $geometry, $css = '', $fields);

// Render and also get the hash and the render duration (what activation records).
['pdf' => $pdf, 'hash' => $hash, 'seconds' => $s]
    = $documents->renderForActivation($layout, $data, $geometry, $css = '', $fields);

// Render (layer-2) validation of the produced PDF.
$codeBoxes = $documents->codeSlotBoxes($layout, $fields, $geometry);
$problems  = $documents->validateRender($pdf, $fields, $data, $geometry, $seconds, $codeBoxes);
// => array of { code: 'R-07', page: int|null, message: '…' }

// The reproducibility anchor over the inputs: same layout + geometry, same hash.
$hash = $documents->inputHash($projectData, $geometry = null);
```

The facade is stateless and side-effect free: it never reads the database, never picks a
tenant, never writes. Persisting the result, deciding whether a render's problems block
activation, and recording the hash are the host's to do.

## Declaring a type

A template type is one entry of `config('templates.types')`, keyed by a type code
(`invoice`, `receipt`, …). See `tests/Fixtures/types/invoice.php` for a complete,
realistic example (a Portuguese invoice); the shape:

```php
'invoice' => [
    'label'            => 'Invoice',       // translatable string, shown in the UI
    'output'           => 'pdf',           // the only value phase 1 ships
    'seeded'           => true,            // catalogue-provided type: not user-creatable from scratch
    'layout_revision'  => 1,               // bump to push a catalogue layout update to existing templates

    'bindings' => [
        // one entry per data field the editor can place on the canvas
        ['key' => 'emitente.nif', 'label' => 'Issuer VAT number', 'type' => 'text',
         'mandatory' => true, 'legal' => true, 'scope' => 'any_page'],
        ['key' => 'totais.total_a_pagar', 'label' => 'Total due', 'type' => 'money',
         'mandatory' => true, 'legal' => true, 'scope' => 'any_page', 'default_format' => 'money'],
        ['key' => 'linhas', 'label' => 'Lines', 'type' => 'collection', 'mandatory' => true,
         'columns' => [/* one entry per column, same shape as a binding */]],
    ],

    'nodes' => [
        // the document graph: which model (if any) each binding's key prefix maps to
        'emitente' => ['model' => null, 'label' => 'Issuer'],
        'linha'    => ['model' => null, 'collection' => true, 'label' => 'Line'],
    ],

    'fixture' => [
        // realistic, worst-case-length sample values, keyed the same as bindings
        'emitente.nif' => '999999999',
        'linhas'       => [['codigo' => '...', /* … */]],
    ],

    'initial_layout' => [
        'html'  => '...', // the canvas's starting document, in the block markup the editor understands
        'css'   => '...',
        'zones' => ['header_height' => 55.0, 'footer_height' => 35.0], // millimetres
    ],

    'pdf' => [ // required when output is pdf
        'paper_format' => 'A4',
        'margins'      => ['top' => 15.0, 'right' => 15.0, 'bottom' => 15.0, 'left' => 15.0],
        'visibility_limits' => [
            'font_size_min_pt' => 7, 'contrast_min' => 4.5, 'opacity_min' => 1.0,
            'occlusion' => 0, 'truncation' => 0, 'qr_size_min_mm' => 30,
        ],
        'line_row_height_mm' => 4.5,
        'body_reserve_mm'    => 32.0,
        'max_pages'          => 10,
    ],
],
```

`TypeCatalogue::validate()` runs this structurally at boot (no database access — a tenant
connection may not be selected yet) and throws `InvalidTemplateType` on a malformed entry:
an unknown binding `type`, a `legal` field that is not `mandatory`, a `scope` on a field
that is not `legal`, a binding whose key prefix has no matching `nodes` entry, an
`every_page`-scoped legal field the initial layout does not actually place in the
header/footer, and more.

The package's vocabulary is fixed and English regardless of the host's own locale:

- Binding `type`: `text`, `number`, `date`, `money`, `collection`, `image`, `qr`,
  `barcode`.
- Legal-field `scope`: `any_page`, `every_page`.
- Template filters (usable in a layout's Twig, and as a binding's `default_format`):
  `date`, `number`, `money`, `upper` (`templates.formats`).

The catalogue is reachable at runtime through the `Templates` facade:
`Templates::type('invoice')` / `Templates::types()`.

## The editor component

```blade
<x-templates::editor
    :template="$template"
    :version="$version"
    height="calc(100vh - 8rem)"
    :actions="true"
    :urls="[
        'load'       => route('templates.versions.load', [$template, $version]),
        'save'       => route('templates.versions.save', [$template, $version]),
        'preview'    => route('templates.versions.preview', [$template, $version]),
        'activate'   => route('templates.versions.activate', [$template, $version]),
        'catalogue'  => route('templates.catalogue', $template->type_code),
        'stylesheet' => route('templates.stylesheet'),
        'font'       => route('templates.font', ['family' => '{family}', 'weight' => '{weight}']),
    ]"
/>
```

`Editor` (`src/View/Components/Editor.php`) takes:

- `Model $template`, `Model $version` — **any** Eloquent models (the package ships none).
  The editor is a dumb canvas: it reads `$template->type()` (→ a `TemplateType`),
  `$template->type_code`, `$version->template_id` (must match, or it throws),
  `$version->html` / `$version->css` / `$version->geometry`, and `$version->isEditable()`.
  `docs/INTEGRATION.md` specifies that model shape exactly.
- `string $height = '100%'`, `bool $actions = true` (render the save/preview/activate bar,
  or leave that to the host page), `string $mode = 'print'` (phase 1 supports only
  `print` and throws on anything else).
- `array $urls = []` — **the host's own endpoints**. The package registers no routes; the
  editor's `fetch()` calls go to whatever URLs the host passes here. Keys: `load`, `save`,
  `preview`, `activate`, `catalogue`, `stylesheet`, `font` (the last with `{family}` and
  `{weight}` placeholders). The request/response contract for each is in
  `docs/INTEGRATION.md`.

The activate button renders only inside `@can('activate', $template)`, so the host's
policy decides whether it appears.

The component mounts its own bundle inside its own container: no host globals, no
assumptions about the surrounding page, its own stylesheet/script, and its own translated
strings passed through `data-*` attributes (it never touches the host's JavaScript
translation layer).

It communicates outward through `CustomEvent`s dispatched on (and bubbling from) its own
root element:

| Event | When |
|---|---|
| `templates:loaded` | The canvas finished loading the version's layout. |
| `templates:dirty` | The unsaved-changes state changed (`detail.dirty: boolean`). |
| `templates:saved` | A save (`PUT .../save`) succeeded. |
| `templates:validation-failed` | A save was rejected by the static layout rules. |
| `templates:preview-ready` | A preview render finished (`detail.url`). |
| `templates:activated` | The version was successfully activated. |
| `templates:message` | A user-facing outcome to present (`detail.level: 'success' \| 'error'`, `detail.message: string`, `detail.problems: array`). |

`templates:message` carries every outcome the editor would otherwise show in its own
status line — a save that went through, a layout refused for validation, a render that
failed — already translated. By default the editor still shows it inline; pass
`:host-notices="true"` on the component and the editor stops writing these to its status
line (progress such as "Saving…" stays), leaving the host to present them its own way (a
toast, a dialog). Turn it on only where the host actually listens, or the message is lost.
The existing `templates:saved`/`templates:validation-failed`/`templates:activated` events
still fire alongside it, unchanged.

| Event (cancelable) | When |
|---|---|
| `templates:confirm` | The editor needs a yes/no answer (`detail.message`, `detail.confirmLabel`, `detail.cancelLabel`, `detail.respond(boolean)`). |
| `templates:alert` | The editor needs to tell the user something (`detail.message`, `detail.closeLabel`, `detail.respond()`). |

These let the host show the editor's dialogs in its own style. A host that will answer
calls `event.preventDefault()` and later `event.detail.respond(...)` (only the first call
counts; anything but `true` is a "no"). If nobody claims the event, the editor opens its
own dialog, so no flag is needed and a confirmation is never left unanswered.

`templates:refresh`, dispatched *at* `document`, makes every mounted editor pane remount —
useful after injecting a pane into the DOM once the bundle already evaluated.

Theming goes through CSS custom properties, all read with a fallback so an unthemed host
gets sane defaults — `--tpl-primary`, `--tpl-primary-strong`, `--tpl-on-primary`,
`--tpl-surface-base/raised/muted/sunken`, `--tpl-edge`/`--tpl-edge-subtle`,
`--tpl-slate-{400,500,600,700,900}`, plus layout tokens like `--tpl-paper-width`/
`--tpl-paper-height`. The bundle reads only these `--tpl-*` variables, never the host's
own.

When `templates.editor.tenant_header` is set (a header name, e.g. `X-Tenant-Id`), the
editor sends that header with the current tenant id (`TenantResolver::currentId()`) on
every request to the host's endpoints. Leave it `null` (the default) to send no such
header.

## Contracts

Four interfaces, each `bindIf()`-bound by `TemplatesServiceProvider` to a default the host
can override by binding its own implementation (the host's provider boots later, so its
binding wins):

| Contract | Signature | Default binding | Override when |
|---|---|---|---|
| `TenantResolver` | `currentId(): ?string`; `all(): iterable`; `runAs(string, callable): mixed` | `Support\NullTenantResolver` | The editor must tell the host's endpoints which tenant a request is for. **The toolkit itself calls only `currentId()`** (for the editor's tenant header); `all()`/`runAs()` exist for a host that iterates tenants in its own commands. |
| `MoneyFormatter` | `format(float\|string\|null $value): string` | `Support\IntlMoneyFormatter` (ICU `NumberFormatter::CURRENCY`, locale from `app()->getLocale()`, currency from `templates.money.currency`, default `EUR`) | A currency/format convention ICU cannot express, or to avoid `ext-intl`. |
| `PdfEngine` | `render(string $html, PageGeometry $page): string` (PDF bytes) | the class `templates.pdf.engine` names — `Output\Pdf\GotenbergEngine` by default | A different rendering backend, or a test double that skips a real Gotenberg call. |
| `BindingResolver` | `resolve(string $key, TemplateType $type): ?array` | `Support\NullBindingResolver` (declared-only) | Layer-1 validation should accept a binding key the type never declared (e.g. a field computed from the host's schema). A resolved key is never legal nor mandatory. |

## PDF output

For a type declared `output => 'pdf'`, rendering (preview and activation) sends composed
HTML to Gotenberg's Chromium route with `preferCssPageSize=true` and zero engine-side
margins — the layout's own `@page` rule (from the print stylesheet) is the only place page
size and margins are decided.

Gotenberg is called **with no network access and JavaScript disabled** on the rendered
page. That is why `DocumentComposer` embeds every font and image as a `data:` URI before
sending the document, and why it refuses (with `UnsafeTemplateException`) a layout whose
CSS uses `@import` or whose HTML/CSS carries a `<script>` tag, a `javascript:` reference,
or any external (non-`data:`) URL reference — these would silently do nothing (no network)
or fail loudly, and either way the composer would rather refuse the save/preview than let
a validated document quietly not match what a real host prints.

The render-validation path additionally reads the PDF back with `poppler-utils`
(`pdftotext -bbox` for text geometry, `pdftoppm` for rasterizing fills, QR/barcode crops,
and the visual-regression baseline) and `ext-gd` for the raster comparisons — see
`docs/NOTAS-FIDELIDADE.md` for the fidelity measurements this validation is built on.

The print stylesheet is host-agnostic and ships with a placeholder font family; the real,
configured family (`templates.fonts`) is written after it so the later declaration wins.
The canvas and the compositor read exactly the same stylesheet bytes (served raw through
the host's `stylesheet` URL) and embed exactly the same font bytes — that identity is what
makes a width measured on the canvas mean the same thing in the PDF.

## Reproducibility conformance-kit

The toolkit leaves the reproducibility and immutability discipline to the host; the kit is
the safety net that proves the host did not silently drop it. Mount
`Testing\AssertsReproducibility` in the host's own test case and run it against the host's
own version model and the toolkit facade:

```php
use Controlink\Templates\Testing\AssertsReproducibility;

class TemplateReproducibilityTest extends TestCase
{
    use AssertsReproducibility;

    public function test_input_hash_is_stable(): void
    {
        $this->assertInputHashStable($projectData, $geometry);
    }

    public function test_issued_versions_are_immutable(): void
    {
        $this->assertVersionImmutableWhenIssuable(fn () => /* a persisted active version */);
    }

    public function test_re_render_reproduces(): void
    {
        $this->assertReRenderReproduces($layout, $data, $geometry, $css = '', $fields);
    }
}
```

`assertInputHashStable()` and `assertVersionImmutableWhenIssuable()` run without a PDF
engine; `assertReRenderReproduces()` needs Gotenberg + poppler-utils (skip it when they
are absent). The immutability guard the host mounts on its version model is
`Models\Concerns\GuardsIssuableVersion` — see `docs/INTEGRATION.md`.

## Breaking changes from phase 1

The package was extracted from a host that owned the whole feature. Everything stateful
moved back to the host:

- **Gone from `src/`:** the HTTP layer (`Http/`, routes, controllers), the `Template` /
  `TemplateVersion` models, `HasTemplatesConnection`, the data-resolution cluster
  (`Data/`), `VersionActivator`, and the package's own migrations.
- **Gone contracts:** `Auditor` and `EncryptedFieldMap`. Auditing is the host's concern
  now (register an audit trail on the host's own model); field encryption is the host's
  schema concern.
- **Gone config:** the `tenancy.mode` / `tenancy.connection` block (the package reads no
  connection of its own). The editor's tenant header moved from `templates.http.*` to
  `templates.editor.tenant_header`. The `templates.models.*` block is gone — the editor
  takes model instances directly.
- **New:** `Toolkit\Documents` (the stateless facade), `BindingResolver`,
  `GuardsIssuableVersion` (a mountable trait), and `AssertsReproducibility` (the
  conformance-kit).
- **New in 1.1:** the editor accepts `:format-selector="false"` and a `urls['format_switch']`
  endpoint, so a host that gives each page format its own version lineage can drive format
  switching from its own tabs (`[data-tpl-format-tab]`) instead of the built-in selector.
  See `docs/INTEGRATION.md`.

A host integrating this version implements the models, the table/migrations, the routes,
the policy and the version lifecycle itself. `docs/INTEGRATION.md` is the step-by-step.

## Dev notes

- The pre-built editor bundle (`dist/editor.js`, `dist/editor.css`, `dist/manifest.json`)
  is **committed** — a consumer never runs the package's own frontend build;
  `vendor:publish --tag=templates-assets` just copies these files.
- `composer.json` pins `config.platform.php` to `8.1.0` so Composer never resolves a
  dependency that needs PHP above the package's declared floor, and `require-dev` narrows
  `orchestra/testbench` to `^8.0` and `phpunit/phpunit` to `^10.0` accordingly (newer
  majors need PHP 8.2+). The package's own test suite therefore runs against Laravel 10,
  regardless of the `^10.48 || ^11 || ^12 || ^13` range it supports.
- `config.policy.advisories.ignore: ["laravel/framework"]` (dev-only) exists because
  resolving `orchestra/testbench ^8.0` pulls in `laravel/framework` on the 10.x line,
  whose open advisories the advisory-block policy would otherwise refuse. It affects only
  this package's dev/test sandbox — `laravel/framework` is not a direct dependency — and
  does not change what a consumer resolves.
- `VisualRegressionTest`, the initial-layout render test and the other render tests skip
  automatically (via `TestCase::skipWithoutPdfEngine()`) when Gotenberg and/or
  poppler-utils are not reachable from the machine running the suite.
