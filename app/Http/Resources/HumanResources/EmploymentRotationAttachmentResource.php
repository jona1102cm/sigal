<?php

namespace App\Http\Resources\HumanResources;

use App\Models\EmploymentRotationAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmploymentRotationAttachment */
class EmploymentRotationAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'sha256' => $this->sha256,
            'uploaded_at' => $this->uploaded_at?->toIso8601String(),
            'download_url' => "/api/human-resources/rotation-attachments/{$this->id}/download",
        ];
    }
}
