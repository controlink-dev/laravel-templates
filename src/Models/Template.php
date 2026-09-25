<?php

namespace Controlink\Templates\Models;

use Controlink\Templates\Models\Concerns\HasTemplatesConnection;
use Controlink\Templates\Types\TemplateType;
use Controlink\Templates\Types\TypeCatalogue;
use Controlink\Templates\Versions\VersionState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A layout bound to one document type. It groups versions and holds no layout itself.
 */
class Template extends Model
{
    use HasTemplatesConnection;
    use HasUlids;
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'tpl_templates';

    protected $fillable = [
        'type_code',
        'name',
        'active_version_id',
        'tenant_id',
    ];

    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value ? trim($value) : null,
        );
    }

    /**
     * Oldest first: a version list is a history, and a history reads forwards.
     */
    public function versions(): HasMany
    {
        return $this->hasMany(config('templates.models.version'), 'template_id')->orderBy('number');
    }

    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(config('templates.models.version'), 'active_version_id');
    }

    /** The document type definition for this template's `type_code`. */
    public function type(): TemplateType
    {
        return app(TypeCatalogue::class)->find($this->type_code);
    }

    /** The next version number for this template. Unique among the versions that currently exist. */
    public function nextVersionNumber(): int
    {
        return (int) $this->versions()->max('number') + 1;
    }

    public function scopeActive(Builder $query): void
    {
        $query->withoutTrashed();
    }

    public function scopeInactive(Builder $query): void
    {
        $query->onlyTrashed();
    }

    public function isActive(): bool
    {
        return ! $this->trashed();
    }

    public function isInactive(): bool
    {
        return $this->trashed();
    }

    /** Whether any version of this template came from the catalogue (spec 8.2). */
    public function isSeeded(): bool
    {
        return $this->versions()->whereNotNull('layout_revision')->exists();
    }

    /** Whether any version has ever been in production, and so describes issued documents. */
    public function hasIssuableVersion(): bool
    {
        return $this->versions()
            ->whereIn('state', [
                VersionState::Active->value,
                VersionState::Archived->value,
            ])
            ->exists();
    }
}
