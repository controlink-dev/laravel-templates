<?php

namespace Controlink\Templates\Http\Resources;

use Controlink\Templates\Models\Template;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Template */
class TemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'type_code'         => $this->type_code,
            'active_version_id' => $this->active_version_id,
            'is_active'         => $this->isActive(),
            'created_at'        => $this->created_at,
            'updated_at'        => $this->updated_at,
        ];
    }
}
