# Integration guide

This package is a stateless toolkit (see the README). A host wires it up by supplying the
stateful pieces the toolkit deliberately does not own: two Eloquent models and their
table, a handful of HTTP endpoints the editor talks to, an authorization policy, and the
version lifecycle. This document is the exact contract for each.

Everything here is the **host's** code. The only package types referenced are the toolkit
facade (`Toolkit\Documents`), the type catalogue (`Templates` facade / `TypeCatalogue`),
the `VersionState` enum, and the `GuardsIssuableVersion` trait.

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

    protected $fillable = ['template_id', 'number', 'state', 'project_data', 'html', 'css', 'hash', 'geometry'];

    protected $casts = [
        'state'        => VersionState::class,
        'project_data' => 'array',
        'geometry'     => 'array',
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
    $t->text('html')->nullable();
    $t->text('css')->nullable();
    $t->string('hash')->nullable();
    $t->json('geometry')->nullable();
    $t->timestamps();
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
$geometry = $request->input('geometry') ?: $type->pdf;

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
    'state'        => \Controlink\Templates\Versions\VersionState::Validated,
]);

return response()->json(['ok' => true]);
```

### `preview` — `POST`

No body. Returns the rendered PDF **bytes** (`Content-Type: application/pdf`) on success;
the editor opens them as a blob. On failure, the JSON error contract.

```php
$pdf = $documents->render(
    ['project_data' => $version->project_data, 'html' => $version->html, 'css' => $version->css],
    $type->fixture,            // preview renders against the type's sample data
    $version->geometry ?: $type->pdf,
    '',
    $type->bindings,
);

return response($pdf, 200, ['Content-Type' => 'application/pdf']);
```

### `activate` — `POST`

No body. Renders, runs render validation, and — only if it passes — flips the version to
`active` (archiving the previous active one) and records the hash.

```php
$layout = ['project_data' => $version->project_data, 'html' => $version->html, 'css' => $version->css];
$fields = $type->bindings;
$geo    = $version->geometry ?: $type->pdf;
$data   = $type->fixture;

['pdf' => $pdf, 'hash' => $hash, 'seconds' => $seconds] = $documents->renderForActivation($layout, $data, $geo, '', $fields);

$boxes    = $documents->codeSlotBoxes($layout, $fields, $geo);
$problems = $documents->validateRender($pdf, $fields, $data, $geo, $seconds, $boxes);

if ($problems !== []) {
    return response()->json(['message' => __('…'), 'problems' => $problems], 422);
}

DB::transaction(function () use ($template, $version, $hash) {
    $template->versions()->where('state', 'active')->update(['state' => 'archived']);
    $version->update(['state' => 'active', 'hash' => $hash]);
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

return response()->file($face['path'], ['Content-Type' => $face['mime']]);
```

---

## 4. Authorization

The component renders the activate button only inside `@can('activate', $template)`. The
host registers an ordinary Laravel policy for its own `Template` model:

```php
// AuthServiceProvider
protected $policies = [\App\Models\Template::class => \App\Policies\TemplatePolicy::class];
```

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
