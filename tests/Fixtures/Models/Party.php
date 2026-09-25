<?php

namespace Controlink\Templates\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A minimal tenant-owned model for `DocumentGraphTest`: enough columns to exercise every
 * introspection rule `Data\NodeFields` and `Data\TableColumns` apply, without pulling in
 * a host application's own models. Its `parties` table is created (and dropped) by the
 * test itself with `Schema::create`.
 */
class Party extends Model
{
    protected $table = 'parties';

    protected $guarded = [];

    protected $hidden = ['secret'];

    public $timestamps = false;
}
