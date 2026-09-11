<?php

namespace App\Http\Resources\DocumentManagement;

use App\Models\Expedient;
use App\Policies\ExpedientPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Expedient */
class ExpedientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isDetail = $request->route('expedient') instanceof Expedient;
        $user = $request->user();
        $permissions = $isDetail && $user
            ? app(ExpedientPolicy::class)->detailPermissions($user, $this->resource)
            : [];

        return [
            'id' => $this->id,
            'route_number' => $this->route_number,
            'route_code' => $this->route_code,
            'subject' => $this->subject,
            'summary' => $this->summary,
            'origin' => $this->origin->value,
            'origin_label' => $this->origin->label(),
            'sender_type' => $this->sender_type->value,
            'sender_type_label' => $this->sender_type->label(),
            'sender_name' => $this->sender_name,
            'received_on' => $this->received_on->toDateString(),
            'priority' => $this->priority->value,
            'priority_label' => $this->priority->label(),
            'due_on' => $this->due_on?->toDateString(),
            'classification' => $this->classification,
            'observations' => $this->observations,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'current_holder_office_ids' => $this->when(
                $isDetail,
                fn () => $this->currentHolderOfficeIds()->values()->all(),
            ),
            // El frontend consume estas capacidades ya resueltas por las Policies; no replica reglas sensibles.
            'permissions' => $this->when($isDetail, fn () => $permissions),
            'legislature' => $this->whenLoaded('legislature', fn () => [
                'id' => $this->legislature->id,
                'period_label' => $this->legislature->period_label,
            ]),
            'expedient_type' => $this->whenLoaded('expedientType', fn () => [
                'id' => $this->expedientType->id,
                'code' => $this->expedientType->code,
                'name' => $this->expedientType->name,
                'category' => $this->expedientType->category,
            ]),
            'confidentiality_level' => $this->whenLoaded('confidentialityLevel', fn () => [
                'id' => $this->confidentialityLevel->id,
                'code' => $this->confidentialityLevel->code,
                'name' => $this->confidentialityLevel->name,
                'requires_explicit_access' => $this->confidentialityLevel->requires_explicit_access,
            ]),
            'origin_office' => $this->whenLoaded('originOffice', fn () => $this->originOffice === null ? null : [
                'id' => $this->originOffice->id,
                'code' => $this->originOffice->code,
                'name' => $this->originOffice->name,
            ]),
            'responsible_office' => $this->whenLoaded('responsibleOffice', fn () => [
                'id' => $this->responsibleOffice->id,
                'code' => $this->responsibleOffice->code,
                'name' => $this->responsibleOffice->name,
            ]),
            'created_by' => $this->whenLoaded('createdBy', fn () => [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ]),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
