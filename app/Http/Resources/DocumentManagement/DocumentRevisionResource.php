<?php

namespace App\Http\Resources\DocumentManagement;

use App\Models\DocumentRevision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DocumentRevision */
class DocumentRevisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'revision_number' => $this->revision_number,
            'title' => $this->title,
            'content' => $this->content,
            'changed_at' => $this->changed_at->toIso8601String(),
        ];
    }
}
