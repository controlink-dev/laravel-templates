<?php

namespace Controlink\Templates\Tests\Fixtures\Models;

use Controlink\Templates\Types\TemplateType;
use Controlink\Templates\Types\TypeCatalogue;
use Illuminate\Database\Eloquent\Model;

/** Test-only, never persisted: the minimal template shape the editor component reads. */
class EditorTemplate extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function type(): TemplateType
    {
        return app(TypeCatalogue::class)->find($this->type_code);
    }
}
