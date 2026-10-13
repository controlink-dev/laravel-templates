# Integration guide

This package is a stateless toolkit (see the README). A host wires it up by supplying the
stateful pieces the toolkit deliberately does not own: two Eloquent models and their
table, a handful of HTTP endpoints the editor talks to, an authorization policy, and the
version lifecycle. This document is the exact contract for each.

Everything here is the **host's** code. The only package types referenced are the toolkit
facade (`Toolkit\Documents`), the type catalogue (`Templates` facade / `TypeCatalogue`),
the `VersionState` enum, and the `GuardsIssuableVersion` trait.

---

## 0. Quick start

```bash
php artisan templates:install
```

The command scaffolds all of this into the host's own paths — nothing is left in the
package:

| File | What it is |
|---|---|
| `app/Models/Template.php`, `app/Models/TemplateVersion.php` | the two models (section 1) |
| `database/migrations/*_create_templates_table.php` | the reference migration (section 1) |
| `app/Http/Controllers/TemplateEditorController.php` | the seven endpoints, implemented (section 3) |
| `routes/templates.php` | the routes, plus the `urls` prop example (section 3) |
| `app/Policies/TemplatePolicy.php` | the policy, denying everything until wired (section 4) |

It also publishes the editor bundle (`--tag=templates-assets`), which the component
needs. It does **not** publish the config: the package's merged defaults already apply,
and the shipped default font path is resolved relative to the package's own config file —
a copy in the host's `config/` would break that path. It never overwrites a file that
already exists — pass `--force` to replace, and re-running it is safe. Then:

1. `require __DIR__.'/templates.php';` from `routes/web.php`.
2. Grant abilities in `TemplatePolicy` (it denies everything by default).
3. Declare a type in `config/templates.php` — a partial config is merged over the package
   defaults, so you only write the keys you set (README, "Declaring a type"). If you
   publish the full config with `--tag=templates-config`, fix `fonts.*.weights.*.path` to
   point at a font the host can read.
4. `php artisan migrate`.

The rest of this document is the contract behind each generated file, for when the host
adapts them — the generated code is the reference implementation, not a black box.

---

## 1. Models

The editor component takes any two Eloquent models. It never names a concrete class — it
reads a fixed shape off them. The host provides models that expose that shape.

### Template

| Member | Kind | Used for |
|---|---|---|
| `type_code` | string attribute | the type code, keys into `config('templates.types')` |
| `type(): TemplateType` | method | the resolved type definition |
| `getKey()` | Eloquent | matched against `$version->template_id` |

```php
namespace App\Models;

use Controlink\Templates\Templates; // facade; alias "Templates" is auto-registered
use Controlink\Templates\Types\TemplateType;
use Illuminate\Database\Eloquent\Model;

class Template extends Model
{
    protected $fillable = ['type_code', 'name'];

    public function type(): TemplateType
    {
        return Templates::type($this->type_code);
    }

    public function versions()
    {
        return $this->hasMany(TemplateVersion::class);
    }
}
```

### TemplateVersion

| Member | Kind | Used for |
|---|---|---|
| `template_id` | attribute | must equal the template's key, or the editor throws |
| `number` | int attribute | the version number |
| `state` | cast to `VersionState` | the lifecycle state |
| `project_data` | json attribute | the editor's canvas model (`{pages: …}`) |
| `html`, `css` | text attributes | the composed layout |
| `hash` | string attribute | the reproducibility anchor, written on activation |
| `geometry` | json attribute, nullable | the page geometry (sheet/roll), or null |
| `isEditable(): bool` | method | whether the layout may still change |

```php
namespace App\Models;

use Controlink\Templates\Models\Concerns\GuardsIssuableVersion;
use Controlink\Templates\Versions\VersionState;
use Illuminate\Database\Eloquent\Model;

class TemplateVersion extends Model
{
    use GuardsIssuableVersion; // model-level immutability guard for active/archived versions

    protected $fillable = ['template_id', 'number', 'state', 'project_data', 'html', 'css', 'hash', 'geometry', 'activated_at', 'activated_by'];

    protected $casts = [
        'state'        => VersionState::class,
        'project_data' => 'array',
        'geometry'     => 'array',
        'activated_at' => 'datetime',
    ];

    public function isEditable(): bool
    {
        return $this->state->isEditable();
    }

    public function template()
    {
        return $this->belongsTo(Template::class);
    }
}
```

`GuardsIssuableVersion` throws `ImmutableVersionException` if an `active` or `archived`
version's layout attributes (`project_data`, `html`, `css`, `hash`, `number`,
`template_id`) are changed. The state and activation metadata are deliberately *not*
frozen, so activation and archiving can still write them. Override
`frozenVersionAttributes()` to change the frozen set.

### Reference migration

```php
Schema::create('templates', function (Blueprint $t) {
    $t->id();
    $t->string('type_code');
    $t->string('name')->nullable();
    // host-owned tenancy: add a tenant column/scope here if the host is multi-tenant
    $t->timestamps();
    $t->softDeletes(); // optional, if the host wants soft deletes
});

Schema::create('template_versions', function (Blueprint $t) {
    $t->id();
    $t->foreignId('template_id')->constrained('templates')->cascadeOnDelete();
    $t->unsignedInteger('number')->default(1);
    $t->string('state')->default('draft'); // draft | validated | active | archived
    $t->json('project_data')->nullable();
    $t->longText('html')->nullable();
    $t->longText('css')->nullable();
    $t->string('hash')->nullable();        // canonical input hash (reproducibility anchor)
    $t->json('geometry')->nullable();
    $t->timestamp('activated_at')->nullable();
    $t->string('activated_by')->nullable();
    $t->timestamps();

    $t->unique(['template_id', 'number']);
});
```

The host runs this migration its own way. In a multi-database tenancy setup, run it
against each tenant connection — the package has no opinion on how.

---

## 2. Version lifecycle

```
draft ──save (static validation passes)──▶ validated
validated ──activate (render validation passes)──▶ active
active ──another version activates──▶ archived
```

- Only one version per template is `active`.
- `active` and `archived` are **issuable** (an issued document points at the exact version
  that produced it) and therefore immutable — `GuardsIssuableVersion` enforces it at the
  model level.
- Editing an issuable version means creating a new `draft` cloned from it, never mutating
  it.

`VersionState` exposes `isEditable()` (draft/validated) and `isIssuable()`
(active/archived).

---

## 3. HTTP endpoints

The editor component registers no routes. The host defines them and passes their URLs to
the component through the `urls` prop (README, "The editor component"). The editor sends,
on every request: `X-CSRF-TOKEN`, `X-Requested-With: XMLHttpRequest`,
`Accept: application/json`, and — when `templates.editor.tenant_header` is set — that
header with `TenantResolver::currentId()`.

### Error contract (all endpoints)

Any non-2xx response is treated as a failure. For a failure the editor reads JSON:

```json
{ "message": "human-readable reason", "problems": [ /* optional */ ] }
```

`problems` entries differ by layer:

- static (layer-1): `{ "code": "V-10", "component_id": "…", "message": "…" }`
- render (layer-2): `{ "code": "R-07", "page": 2, "message": "…" }`

These are exactly what `Documents::validateLayout()` and `Documents::validateRender()`
return — pass them straight through.

### `load` — `GET`

Returns the version to edit.

```json
{ "data": { "project_data": { "pages": [ … ] }, "html": "…", "css": "…" } }
```

The editor loads `data.project_data`. An empty `project_data` means "never saved" — the
editor keeps the type's initial layout rather than wiping it.

### `save` — `PUT`

Request body:

```json
{ "project_data": { … }, "html": "…", "css": "…", "geometry": { … } }
```

Host handler:

```php
$documents = app(\Controlink\Templates\Toolkit\Documents::class);
$type      = $template->type();

$layout   = ['project_data' => $request->input('project_data'), 'html' => $request->input('html'), 'css' => $request->input('css')];
$fields   = $type->bindings;          // the type's declared bindings
// Geometry must carry the zone bands: the toolkit reads header/footer heights off
// `geometry['zones']`, and they live in the type's initial layout, not its `pdf` block.
$geometry = ($request->input('geometry') ?: $type->pdf);
$geometry['zones'] = $geometry['zones'] ?? ($type->initialLayout['zones'] ?? []);

$problems = $documents->validateLayout($layout, $fields, $geometry);

if ($problems !== []) {
    return response()->json(['message' => __('…'), 'problems' => $problems], 422);
}

abort_unless($version->isEditable(), 409);
$version->update([
    'project_data' => $layout['project_data'],
    'html'         => $layout['html'],
    'css'          => $layout['css'],
    'geometry'     => $geometry,
    // The reproducibility anchor: the canonical hash of the inputs. Written here, at
    // save time, and frozen once the version is issuable — never the rendered-PDF hash.
    'hash'  => $documents->inputHash($layout['project_data'], $geometry),
    'state' => \Controlink\Templates\Versions\VersionState::Validated,
]);

return response()->json(['ok' => true]);
```

### `preview` — `POST`

No body. Returns the rendered PDF **bytes** (`Content-Type: application/pdf`) on success;
the editor opens them as a blob. On failure, the JSON error contract.

```php
$geometry = ($version->geometry ?: $type->pdf);
$geometry['zones'] = $geometry['zones'] ?? ($type->initialLayout['zones'] ?? []);

$pdf = $documents->render(
    ['project_data' => $version->project_data, 'html' => $version->html, 'css' => $version->css],
    $type->fixture,            // preview renders against the type's sample data
    $geometry,
    '',
    $type->bindings,
);

return response($pdf, 200, ['Content-Type' => 'application/pdf']);
```

### `activate` — `POST`

No body. Renders, runs render validation, and — only if it passes — flips the version to
`active` (archiving the previous active one) and records the hash.

```php
abort_unless($version->state === \Controlink\Templates\Versions\VersionState::Validated, 409);

$layout = ['project_data' => $version->project_data, 'html' => $version->html, 'css' => $version->css];
$fields = $type->bindings;
$geo    = ($version->geometry ?: $type->pdf);
$geo['zones'] = $geo['zones'] ?? ($type->initialLayout['zones'] ?? []);
$data   = $type->fixture;

['pdf' => $pdf, 'hash' => $pdfHash, 'seconds' => $seconds] = $documents->renderForActivation($layout, $data, $geo, '', $fields);

$boxes    = $documents->codeSlotBoxes($layout, $fields, $geo);
$problems = $documents->validateRender($pdf, $fields, $data, $geo, $seconds, $boxes);

if ($problems !== []) {
    return response()->json(['message' => __('…'), 'problems' => $problems], 422);
}

DB::transaction(function () use ($template, $version) {
    // Archive the one this replaces, promote this one. The version's `hash` (the
    // canonical input hash set at save) is NOT touched — it is frozen and stays the
    // reproducibility anchor. Record `$pdfHash` in your own audit trail if you keep one.
    $template->versions()
        ->where('state', 'active')
        ->whereKeyNot($version->getKey())
        ->update(['state' => 'archived']);

    $version->update([
        'state'        => \Controlink\Templates\Versions\VersionState::Active,
        'activated_at' => now(),
        'activated_by' => (string) auth()->id(),
    ]);
});

return response()->json(['ok' => true]);
```

### `catalogue` — `GET`

Feeds the editor's block/field pickers. Build it from the type plus the relevant config:

```php
$type = Templates::type($typeCode);

return response()->json([
    'bindings'          => $type->bindings,
    'nodes'             => $type->nodes,
    'formats'           => config('templates.formats'),
    'code_types'        => config('templates.code_types'),
    'visibility_limits' => $type->pdf['visibility_limits'] ?? [],
    'rule_widths'       => config('templates.rule_widths'),
    'image_max_bytes'   => config('templates.image_max_bytes'),
    'fixture'           => $type->fixture,
    'zones'             => $type->initialLayout['zones'] ?? [],
]);
```

### `stylesheet` — `GET`

Serves the print stylesheet **raw**, so the canvas and the PDF engine read identical
bytes. It is public on the composer:

```php
$css = app(\Controlink\Templates\Output\Pdf\DocumentComposer::class)->printStylesheet();

return response($css, 200, ['Content-Type' => 'text/css']);
```

### `font` — `GET`

Serves a font file the component referenced with `{family}`/`{weight}` placeholders (the
package's default font lives inside `vendor/`, outside the host's public directory):

```php
$face = config("templates.fonts.{$family}.weights.{$weight}");
abort_unless($face, 404);

// Absolute path (the package's own font) as-is; a relative one from public/.
$path = preg_match('/^([a-zA-Z]:[\\\\\/]|\/)/', $face['path']) === 1 ? $face['path'] : public_path($face['path']);

return response()->file($path, ['Content-Type' => $face['mime']]);
```

### Routes

The generated `routes/templates.php`. The per-version routes use `scopeBindings()` so a
`{version}` only resolves within its `{template}`; the shared ones are per-type. The
editor never names these — the host hands it their URLs through the `urls` prop.

```php
use App\Http\Controllers\TemplateEditorController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])
    ->prefix('templates')
    ->name('templates.')
    ->group(function () {
        Route::get('{template}/versions/{version}/edit', [TemplateEditorController::class, 'load'])->name('load')->scopeBindings();
        Route::put('{template}/versions/{version}', [TemplateEditorController::class, 'save'])->name('save')->scopeBindings();
        Route::post('{template}/versions/{version}/preview', [TemplateEditorController::class, 'preview'])->name('preview')->scopeBindings();
        Route::post('{template}/versions/{version}/activate', [TemplateEditorController::class, 'activate'])->name('activate')->scopeBindings();

        Route::get('catalogue/{type}', [TemplateEditorController::class, 'catalogue'])->name('catalogue');
        Route::get('stylesheet', [TemplateEditorController::class, 'stylesheet'])->name('stylesheet');
        Route::get('fonts/{family}/{weight}', [TemplateEditorController::class, 'font'])->name('font')->whereNumber('weight');
    });
```

### Passing the URLs to the component

On the page that embeds the editor, build the `urls` prop from the named routes. The
`font` URL keeps the literal `{family}`/`{weight}` placeholders — the component
substitutes them per face — so it is built with marker tokens and swapped back, because
`route()` would URL-encode real braces.

```php
$urls = [
    'load'       => route('templates.load', [$template, $version]),
    'save'       => route('templates.save', [$template, $version]),
    'preview'    => route('templates.preview', [$template, $version]),
    'activate'   => route('templates.activate', [$template, $version]),
    'catalogue'  => route('templates.catalogue', $template->type_code),
    'stylesheet' => route('templates.stylesheet'),
    'font'       => str_replace(
        ['FAMILY_PLACEHOLDER', 'WEIGHT_PLACEHOLDER'],
        ['{family}', '{weight}'],
        route('templates.font', ['family' => 'FAMILY_PLACEHOLDER', 'weight' => 'WEIGHT_PLACEHOLDER']),
    ),
];
```

```blade
<x-templates::editor :template="$template" :version="$version" :urls="$urls" />
```

---

## 4. Authorization

The component renders the activate button only inside `@can('activate', $template)`, and
every endpoint authorizes through `Gate::authorize()` (see the generated controller).
Laravel auto-discovers the policy for the `Template` model
(`App\Models\Template` → `App\Policies\TemplatePolicy`) — on Laravel 11+ there is no
`AuthServiceProvider` and nothing to register. The controller extends
`Illuminate\Routing\Controller` rather than the host's base controller, because that base
no longer carries the authorization trait on Laravel 10–13.

The editor's own endpoints should authorize too — a typical set mirrors Laravel's resource
conventions, with `activate` as its own ability (drawing a layout and putting issued
documents behind it are different decisions):

| Ability | Endpoint |
|---|---|
| `view` | `load`, `preview` |
| `update` | `save` |
| `activate` | `activate` |
| `viewAny` | `catalogue` |

---

## 5. Tenancy

The package owns no database and no tenancy mode. If the host is multi-tenant, it scopes
the host's own `Template`/`TemplateVersion` models the way it scopes everything else
(global scope, per-tenant connection, whatever the host already does).

The only tenancy touch-point the package has is the editor's optional tenant header. Bind
a `TenantResolver` so the editor can tell the host's endpoints which tenant a request is
for:

```php
// the host's own service provider (boots after the package, so this wins)
$this->app->bind(\Controlink\Templates\Contracts\TenantResolver::class, \App\Templates\MyTenantResolver::class);
```

```php
'templates.editor.tenant_header' => 'X-Tenant-Id', // config/templates.php
```

The toolkit calls only `TenantResolver::currentId()`. `all()` and `runAs()` are there for a
host that wants to iterate tenants in its own commands; the package never calls them.

---

## 6. Reproducibility conformance-kit

Mount `AssertsReproducibility` in the host's test suite to prove the host's persistence
kept the discipline the toolkit relies on. See the README, "Reproducibility
conformance-kit", for the three assertions and when each needs a live PDF engine.
