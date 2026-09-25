<?php

namespace Controlink\Templates\Versions;

/**
 * The lifecycle of one layout snapshot (spec 4.1).
 *
 * `draft` -> `validated` on a save that passes static validation, `validated` ->
 * `active` on an activation that passes render validation, and `active` -> `archived`
 * when another version takes over. Only one version per template is `active`.
 *
 * `active` and `archived` are immutable: editing an active version creates a new
 * draft from it rather than changing it, because issued documents keep pointing at
 * the exact version that produced them.
 */
enum VersionState: string
{
    case Draft     = 'draft';
    case Validated = 'validated';
    case Active    = 'active';
    case Archived  = 'archived';

    public function label(): string
    {
        return __('templates::editor.states.'.$this->value);
    }

    /** Whether the layout of a version in this state may still be changed. */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Validated;
    }

    /** Whether a version in this state may be referenced by an issued document. */
    public function isIssuable(): bool
    {
        return $this === self::Active || $this === self::Archived;
    }
}
