<?php

namespace Controlink\Templates\Models;

use Controlink\Templates\Exceptions\ImmutableVersionException;
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
    ];

    protected $casts = [
        'project_data'    => 'array',
        'state'           => VersionState::class,
        'number'          => 'integer',
        'layout_revision' => 'integer',
        'activated_at'    => 'datetime',
    ];

    /**
     * An active or archived version's layout is frozen (RA-03).
     *
     * The endpoint refuses to save one, but that is a guard on one route; a job, a
     * command or a future controller would slip past it. Issued documents point at the
     * exact version that produced them, so a layout that changes underneath them makes
     * every one of those documents unreproducible — which is the one thing the
     * certification cannot allow.
     *
     * The state and the activation metadata are explicitly not frozen: activation and
     * archiving are exactly what has to be able to write them.
     */
    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            // `getOriginal` returns the cast value, so this is already the enum — but a
            // row written before the cast existed would hand back a string, and a guard
            // that throws while deciding whether to throw protects nothing.
            $original = $version->getOriginal('state');

            $previous = $original instanceof VersionState
                ? $original
                : VersionState::tryFrom((string) $original);

            if (! ($previous?->isIssuable() ?? false)) {
                return;
            }

            $frozen = array_intersect(
                array_keys($version->getDirty()),
                ['project_data', 'html', 'css', 'hash', 'number', 'template_id'],
            );

            if ($frozen !== []) {
                throw new ImmutableVersionException(
                    'A version in state '.$previous->value
                    .' cannot change its layout ('.implode(', ', $frozen).').',
                );
            }
        });
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(config('templates.models.template'), 'template_id');
    }

    public function isEditable(): bool
    {
        return $this->state->isEditable();
    }
}
