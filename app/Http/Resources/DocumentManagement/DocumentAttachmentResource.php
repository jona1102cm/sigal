<?php

namespace App\Http\Resources\DocumentManagement;

use App\Models\DocumentAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DocumentAttachment */
class DocumentAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'content_hash' => $this->content_hash,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
