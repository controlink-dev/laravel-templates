<?php

namespace Controlink\Templates\Http\Resources;

use Controlink\Templates\Models\TemplateVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TemplateVersion */
class TemplateVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'template_id'     => $this->template_id,
            'number'          => $this->number,
            'state'           => $this->state->value,
            'is_editable'     => $this->isEditable(),
            'project_data'    => $this->project_data,
            'hash'            => $this->hash,
            'layout_revision' => $this->layout_revision,
            'created_by'      => $this->created_by_name,
            'activated_by'    => $this->activated_by_name,
            'activated_at'    => $this->activated_at,
            'created_at'      => $this->created_at,
            'updated_at'      => $this->updated_at,
        ];
    }
}
