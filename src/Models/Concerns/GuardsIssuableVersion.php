<?php

namespace Controlink\Templates\Models\Concerns;

use Controlink\Templates\Exceptions\ImmutableVersionException;
use Controlink\Templates\Versions\VersionState;

/**
 * Model-level immutability guard for a version model (RA-03).
 *
 * An active or archived version's layout is frozen. The endpoint refuses to save one,
 * but that is a guard on one route; a job, a command or a future controller would slip
 * past it. Issued documents point at the exact version that produced them, so a layout
 * that changes underneath them makes every one of those documents unreproducible.
 *
 * Mount it on any version model whose `state` attribute casts to `VersionState`.
 * Override `frozenVersionAttributes()` to change which attributes are frozen. The
 * state and the activation metadata are deliberately not frozen: activation and
 * archiving must be able to write them.
 */
trait GuardsIssuableVersion
{
    public static function bootGuardsIssuableVersion(): void
    {
        static::updating(function ($version): void {
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
                $version->frozenVersionAttributes(),
            );

            if ($frozen !== []) {
                throw new ImmutableVersionException(
                    'A version in state '.$previous->value
                    .' cannot change its layout ('.implode(', ', $frozen).').',
                );
            }
        });
    }

    /**
     * @return array<int, string>
     */
    protected function frozenVersionAttributes(): array
    {
        return ['project_data', 'html', 'css', 'hash', 'number', 'template_id'];
    }
}
