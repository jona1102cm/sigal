<?php

namespace App\Http\Resources\HumanResources;

use App\Models\EmployeeAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmployeeAttachment */
class EmployeeAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_type' => $this->document_type->value,
            'document_type_label' => $this->document_type->label(),
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'sha256' => $this->sha256,
            'uploaded_at' => $this->uploaded_at->toIso8601String(),
            'download_url' => "/api/human-resources/attachments/{$this->id}/download",
        ];
    }
}
