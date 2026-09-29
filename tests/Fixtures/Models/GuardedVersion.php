<?php

namespace Controlink\Templates\Tests\Fixtures\Models;

use Controlink\Templates\Models\Concerns\GuardsIssuableVersion;
use Controlink\Templates\Versions\VersionState;
use Illuminate\Database\Eloquent\Model;

/** Test-only version model proving `GuardsIssuableVersion` without any package model. */
class GuardedVersion extends Model
{
    use GuardsIssuableVersion;

    protected $table = 'guarded_versions';

    protected $guarded = [];

    protected $casts = [
        'project_data' => 'array',
        'state'        => VersionState::class,
        'number'       => 'integer',
    ];

    public function isEditable(): bool
    {
        return $this->state->isEditable();
    }
}
