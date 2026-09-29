<?php

namespace Controlink\Templates\Models;

use Controlink\Templates\Models\Concerns\GuardsIssuableVersion;
use Controlink\Templates\Models\Concerns\HasTemplatesConnection;
use Controlink\Templates\Versions\VersionState;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One snapshot of a layout.
 *
 * `project_data` is the source of truth; `html` and `css` are derived from it by the
 * editor and kept only so that reissuing a document never depends on running the
 * editor again.
 *
 * There is no soft delete: a version is not disabled, it moves through the states in
 * `VersionState`.
 */
class TemplateVersion extends Model
{
    use GuardsIssuableVersion;
    use HasTemplatesConnection;
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'tpl_template_versions';

    protected $fillable = [
        'template_id',
        'number',
        'state',
        'project_data',
        'html',
        'css',
        'hash',
        'layout_revision',
        'created_by',
        'created_by_name',
        'activated_by',
        'activated_by_name',
        'activated_at',
        'tenant_id',
    ];

    protected $casts = [
        'project_data'    => 'array',
        'state'           => VersionState::class,
        'number'          => 'integer',
        'layout_revision' => 'integer',
        'activated_at'    => 'datetime',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(config('templates.models.template'), 'template_id');
    }

    public function isEditable(): bool
    {
        return $this->state->isEditable();
    }
}
