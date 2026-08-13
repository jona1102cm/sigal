<?php

namespace App\Http\Resources\DocumentManagement;

use App\Models\OfficeDocumentSequence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OfficeDocumentSequence */
class OfficeDocumentSequenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'legislature_id' => $this->legislature_id,
            'office_id' => $this->office_id,
            'prefix' => $this->prefix,
            'padding' => $this->padding,
            'last_issued_number' => $this->last_issued_number,
        ];
    }
}
