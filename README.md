# laravel-templates

## What it is

`controlink/laravel-templates` gives a Laravel host versioned document templates: an
embeddable GrapesJS editor for laying out a document on an A4/A5 canvas, a Twig-sandbox
renderer that turns a saved layout plus a data payload into a final document, and, for
templates declared `output => 'pdf'`, PDF output produced by sending the composed,
self-contained HTML to a Gotenberg instance and validating the result against the
document type's legibility rules before it can be activated.

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

A project that only declares non-PDF types needs none of this: no Gotenberg, no
poppler-utils, no `ext-gd`. (Phase 1 of the package only ships `output => 'pdf'`, but the
config and the type schema do not assume it.)

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

Then publish what the host needs:

```bash
# optional — the host can also just read/override config('templates.*') directly
php artisan vendor:publish --tag=templates-config

# required for the editor component to render with styling and behaviour
php artisan vendor:publish --tag=templates-assets

# optional — overrides for resources/lang/*.json shipped with the package
php artisan vendor:publish --tag=templates-lang
```

`templates-assets` publishes the pre-built editor bundle (`dist/`, committed in the
package) to `public/vendor/templates`. Re-run it whenever the package updates the
bundle; the editor component cache-busts each file by its own content hash
(`Editor::assetUrl()`), so a stale copy under `public/vendor/templates` is simply served
under its old URL until republished, not silently mixed with the new one.

Finally, run the package's migrations. How, depends on the tenancy mode (below):

- **`none`** — a single database, so `php artisan migrate` picks the package's
  migrations up the same way as any other package's, via the service provider's
  `loadMigrationsFrom()`.
- **`column`** or **`connection`** — one database per tenant, so the host's own
  per-tenant migration pass must also run these migrations against each tenant
  connection. Add `\Controlink\Templates\Templates::migrationPath()` (or
  `app(\Controlink\Templates\TemplatesManager::class)->migrationPath()`) to the list of
  `--path` values the host's tenant-migration command already runs.

## Tenancy

`templates.tenancy.mode` is one of:

| Mode | Meaning |
|---|---|
| `none` | Single database. `Template`/`TemplateVersion` use the default connection. |
| `column` | Shared database. Every row carries a `tenant_id` column, filled from `TenantResolver::currentId()` on create and enforced by a global scope (`HasTemplatesConnection::bootHasTemplatesConnection()`). |
| `connection` | One database per tenant. `Template`/`TemplateVersion::getConnectionName()` returns `templates.tenancy.connection`, which the host is responsible for pointing at the current tenant before the package's code runs. |

The package never picks a tenant on its own — it asks the host through the
`TenantResolver` contract:

```php
interface TenantResolver
{
    /** The current tenant's identifier, or null when there is none (mode `none`, or console). */
    public function currentId(): ?string;

    /** Every tenant identifier, for commands that iterate. */
    public function all(): iterable;

    /**
     * Run $callback with the tenant's database in scope. Implementations that switch a
     * connection MUST purge and reconnect it so `getDatabaseName()` reflects the tenant.
     */
    public function runAs(string $tenantId, callable $callback): mixed;
}
```

That last requirement matters in practice: a naive `runAs()` that only calls
`DB::reconnect()` (no `Illuminate\Support\Facades\DB::purge()` first) can leave
`getDatabaseName()` reporting the previous tenant's database in a long-running process
(a queue worker, a command that iterates `all()`) that switches tenant more than once —
which then miseducates anything that calls `Schema::hasTable()`/`getColumns()` against
the "current" connection. Purge before reconnect.

A minimal example, for a host keyed by subdomain with one database per tenant:

```php
class SubdomainTenantResolver implements TenantResolver
{
    public function currentId(): ?string
    {
        return app('currentTenantUuid'); // however the host already tracks this
    }

    public function all(): iterable
    {
        return Company::pluck('uuid');
    }

    public function runAs(string $tenantId, callable $callback): mixed
    {
        $company = Company::where('uuid', $tenantId)->firstOrFail();

        config(['database.connections.tenant.database' => $company->database]);

        DB::purge('tenant');
        DB::reconnect('tenant');

        return $callback();
    }
}
```

Bind it in the host's own service provider — the package only `bindIf()`s a
`NullTenantResolver` (always returns `null`/`['default']`, `runAs()` just calls the
callback), so a host binding wins as long as it boots after the package (an ordinary
provider registered in `config/app.php` or auto-discovered does).

## Contracts

Five interfaces, each `bindIf()`-bound by `TemplatesServiceProvider` to a default the
host can override by binding its own implementation:

| Contract | Signature | Default binding | Override when |
|---|---|---|---|
| `TenantResolver` | `currentId(): ?string`; `all(): iterable`; `runAs(string $tenantId, callable $callback): mixed` | `Support\NullTenantResolver` | `templates.tenancy.mode` is `column` or `connection`, or any command needs to iterate tenants. |
| `MoneyFormatter` | `format(float\|string\|null $value): string` | `Support\IntlMoneyFormatter` (PHP `NumberFormatter`/ICU, locale from `app()->getLocale()`, currency from `templates.money.currency`) | The host needs a currency/format convention `NumberFormatter` cannot express, or wants to avoid the `ext-intl` dependency's exact rounding/locale behaviour. |
| `Auditor` | `record(string $event, Model $subject, array $context = []): void` | `Support\NullAuditor` (no-op) | The host wants `templates.activated` / `templates.archived` / `templates.render_failed` written to its own audit trail (e.g. `OwenIt\Auditing`, as cgest does elsewhere). |
| `EncryptedFieldMap` | `for(string $modelClass): array` returning `array{encrypted: list<string>, blind_indexes: list<string>}` | `Support\EmptyEncryptedFieldMap` (empty arrays) | A document graph node's model (declared in a type's `nodes`) encrypts columns the field picker should still be able to offer, or exclude, correctly. |
| `PdfEngine` | `render(string $html, PageGeometry $page): string` (returns PDF bytes) | Whatever class `templates.pdf.engine` names — `Output\Pdf\GotenbergEngine` by default | A host wants a different rendering backend, or a test double that skips a real Gotenberg call. |

`MoneyFormatter`'s default is worth calling out explicitly: it is `IntlMoneyFormatter`,
built on ICU (`ext-intl`) via `NumberFormatter::CURRENCY`, not a hand-rolled
symbol/decimal-separator table — the currency it formats to comes from
`templates.money.currency` (default `EUR`), the locale from the app's current locale.

## Authorization

The package ships no policy. Each HTTP endpoint calls `$this->authorize($ability, ...)`
against `config('templates.models.template')` (or a `Template`/`TemplateVersion`
instance) exactly the way any other Laravel controller would, so the host registers an
ordinary policy for its own `Template`/`TemplateVersion` model classes (or for the
package's base classes directly, if it never extends them). If the host extends the
models and registers the policy against the *base* classes only, Laravel's own policy
discovery still resolves it for the subclass — `Gate::getPolicyFor()` walks up the class
hierarchy when no policy is registered for the exact class. No policy registered for
either the subclass or an ancestor means every `authorize()` call denies with a 403.

The endpoints check these abilities:

| Ability | Where |
|---|---|
| `viewAny` | `GET catalogue/{type}` (`CatalogueController::show`) |
| `create` | `POST templates` (`TemplateController::store`), and `POST templates/{template}/duplicate` (`TemplateController::duplicate` — duplication authorizes as `create`, not a separate `duplicate` ability) |
| `view` | `GET templates/{template}/versions/{version}` and `POST .../preview` |
| `update` | `POST templates/{template}/versions` (new draft) and `PUT .../versions/{version}` |
| `activate` | `POST templates/{template}/versions/{version}/activate` — its own ability, deliberately: drawing a layout and putting the host's issued documents behind it are different decisions |

A host building its own CRUD around `Template` (which is soft-deletable —
`scopeActive`/`scopeInactive`, `isActive()`/`isInactive()`) beyond what this package's
routes cover will typically also want `delete`, `restore` and `forceDelete` on the same
policy, for consistency with Laravel's usual resource-policy conventions — the package
itself never calls those three, so they are a recommendation for the host's own
surrounding UI, not a requirement this package enforces.

## Declaring a type

A template type is one entry of `config('templates.types')`, keyed by a type code
(`invoice`, `receipt`, …). See `tests/Fixtures/types/invoice.php` for a complete,
realistic example (Portuguese invoice, adapted from cgest's own `print_forms.php`); the
shape:

```php
'invoice' => [
    'label'            => 'Invoice',       // translatable string, shown in the UI
    'output'           => 'pdf',           // the only value phase 1 supports
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

`TypeCatalogue::validate()` runs this structurally at boot (no database access — a
tenant connection may not be selected yet) and throws `InvalidTemplateType` on a
malformed entry: an unknown binding `type`, a `legal` field that is not `mandatory`, a
`scope` on a field that is not `legal`, a binding whose key prefix has no matching
`nodes` entry, an `every_page`-scoped legal field the initial layout does not actually
place in the header/footer, and more.

The package's vocabulary is fixed and English regardless of the host's own locale:

- Binding `type`: `text`, `number`, `date`, `money`, `collection`, `image`, `qr`,
  `barcode`.
- Legal-field `scope`: `any_page`, `every_page`.
- Template filters (usable in a layout's Twig, and as a binding's `default_format`):
  `date`, `number`, `money`, `upper` (`templates.formats`).

## The editor component

```blade
<x-templates::editor
    :template="$template"
    :version="$version"
    height="calc(100vh - 8rem)"
    :actions="true"
/>
```

`Editor` (`src/View/Components/Editor.php`) takes `Template $template`,
`TemplateVersion $version` (must belong to `$template`, or it throws), `string $height =
'100%'`, `bool $actions = true` (render the save/preview/activate bar, or leave that to
the host page), and `string $mode = 'print'` — phase 1 supports only `print` and throws
on anything else.

The component mounts its own bundle inside its own container: no host globals, no
assumptions about the surrounding page, its own stylesheet/script published under
`public/vendor/templates`, and its own translated strings passed through `data-*`
attributes (it deliberately never touches the host's JavaScript translation layer).

It communicates outward through `CustomEvent`s dispatched on (and bubbling from) its own
root element, so a host page listens the ordinary DOM way rather than polling:

| Event | When |
|---|---|
| `templates:loaded` | The canvas has finished loading the version's layout. |
| `templates:dirty` | The unsaved-changes state changed (`detail.dirty: boolean`). |
| `templates:saved` | A save (`PUT .../versions/{version}`) succeeded. |
| `templates:validation-failed` | A save was rejected by the static layout rules. |
| `templates:preview-ready` | A preview render finished (`detail.url`). |
| `templates:activated` | The version was successfully activated. |

`templates:refresh`, dispatched *at* `document` (not the component's own root), is the
reverse direction: the host fires it to make every currently-mounted editor pane remount
— useful after e.g. injecting a pane into the DOM after the bundle already evaluated.

Theming goes through CSS custom properties, all read with a fallback so an unthemed host
gets sane defaults — `--tpl-primary`, `--tpl-primary-strong`, `--tpl-on-primary`,
`--tpl-surface-base/raised/muted/sunken`, `--tpl-edge`/`--tpl-edge-subtle`,
`--tpl-slate-{400,500,600,700,900}`, plus layout tokens like `--tpl-paper-width`/
`--tpl-paper-height`. The bundle never reads any of the host's own CSS variables — only
these `--tpl-*` ones, set on whatever ancestor element the host chooses.

When `templates.http.tenant_header` is set (a header name, e.g. `X-Tenant-Id`), the
editor's own `fetch()` calls to the package's API send that header with the current
tenant id (`Editor::tenantId()`, via `TenantResolver::currentId()`) on every request —
the same pattern as a `X-License-Id`-style tenant header a host's own API gateway might
already expect. Leave it `null` (the default) to send no such header.

## Models are configurable

`config('templates.models.template')` and `config('templates.models.version')` name the
Eloquent classes the package actually instantiates — never `Controlink\Templates\Models\Template`
by name anywhere in the package's own code. A host that wants auditing, extra relations
or its own traits extends them:

```php
namespace App\Models;

use Controlink\Templates\Models\Template as BaseTemplate;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Template extends BaseTemplate implements AuditableContract
{
    use Auditable;
}
```

```php
// config/templates.php
'models' => [
    'template' => \App\Models\Template::class,
    'version'  => \Controlink\Templates\Models\TemplateVersion::class,
],
```

## PDF output

For a type declared `output => 'pdf'`, rendering (preview and activation) sends composed
HTML to Gotenberg's Chromium route with `preferCssPageSize=true` and zero engine-side
margins — the layout's own `@page` rule (from the print stylesheet) is the only place
page size and margins are decided, never a duplicate set of engine flags.

Gotenberg is called **with no network access and JavaScript disabled** on the rendered
page. That is why `DocumentComposer` embeds every font and image as a `data:` URI before
sending the document, rather than letting Chromium fetch them, and why it refuses (with
`UnsafeTemplateException`) a layout whose CSS uses `@import` or whose HTML/CSS carries a
`<script>` tag, a `javascript:` reference, or any external (non-`data:`) URL reference —
these would silently do nothing (no network) or fail loudly in the worst case (the
engine substitutes a missing asset instead of failing), and either way the composer
would rather refuse the save/preview than let a validated document quietly not match
what a real host prints.

The activation path additionally reads the PDF back with `poppler-utils` (`pdftotext
-bbox` for text geometry, `pdftoppm` for rasterizing fills, QR/barcode crops, and the
visual-regression baseline) and `ext-gd` for the raster comparisons — see
`docs/NOTAS-FIDELIDADE.md` for the fidelity measurements this validation is built on
(pixel-CSS quantization of fills, why a whole-page QR image never decodes, why the
engine's own page-break header/footer repetition is not usable for certified documents,
and more).

## Dev notes

- The pre-built editor bundle (`dist/editor.js`, `dist/editor.css`, `dist/manifest.json`)
  is **committed** to the repository — a consumer never has to run the package's own
  frontend build to get a working editor; `vendor:publish --tag=templates-assets` just
  copies these files.
- `composer.json` pins `config.platform.php` to `8.1.0` so Composer's own dependency
  resolution never picks something that needs PHP above the package's declared floor,
  and `require-dev` narrows `orchestra/testbench` to `^8.0` and `phpunit/phpunit` to
  `^10.0` accordingly (newer majors of either need PHP 8.2+; the package's own `php`
  requirement was never raised to match).
- `config.policy.advisories.ignore: ["laravel/framework"]` (also dev-only) exists because
  resolving `orchestra/testbench ^8.0` pulls in `laravel/framework` on the 10.x line, and
  every 10.x release currently has open Composer security advisories that its
  advisory-block policy would otherwise refuse to resolve against. This only affects
  this package's own dev/test sandbox — `laravel/framework` is not a direct dependency of
  the package — and does not change what a consumer resolves for their own Laravel
  version. See `docs/decisoes-tecnicas.md`-equivalent notes inline in `composer.json`'s
  neighbourhood for the exact advisory ids.
- Because of the above, `orchestra/testbench ^8.0` means the package's **own** test
  suite runs against Laravel 10, regardless of the `^10.48 || ^11 || ^12 || ^13` range it
  declares support for.
- `VisualRegressionTest` and the initial-layout render test skip automatically (not
  fail) when Gotenberg and/or poppler-utils are not reachable from the machine running
  the suite.
