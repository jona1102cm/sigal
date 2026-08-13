<?php

namespace App\Http\Resources\HumanResources;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Employee */
class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'identity_card' => $this->identity_card,
            'first_names' => $this->first_names,
            'last_names' => $this->last_names,
            'full_name' => $this->fullName(),
            'mobile_phone' => $this->mobile_phone,
            'email' => $this->email,
            'address' => $this->address,
            'cua_number' => $this->cua_number,
            'birth_date' => $this->birth_date->toDateString(),
            'military_service_booklet' => $this->military_service_booklet,
            'academic_degree' => $this->academic_degree,
            'profession' => $this->profession,
            'blood_type' => $this->blood_type,
            'emergency_contact' => $this->emergency_contact,
            'account' => $this->whenLoaded('user', function (): ?array {
                if ($this->user === null) {
                    return null;
                }

                return [
                    'id' => $this->user->id,
                    'email' => $this->user->email,
                    'status' => $this->user->status->value,
                    'status_label' => $this->user->status->label(),
                    'roles' => $this->user->relationLoaded('currentRoleAssignments')
                        ? $this->user->currentRoleAssignments->map(fn ($assignment) => [
                            'code' => $assignment->role->code,
                            'name' => $assignment->role->name,
                        ])->values()
                        : [],
                ];
            }),
            'open_contract' => new EmploymentContractResource($this->whenLoaded('openContract')),
            'contracts' => EmploymentContractResource::collection($this->whenLoaded('contracts')),
            'profile_photo' => new EmployeeAttachmentResource($this->whenLoaded('profilePhoto')),
            'attachments' => EmployeeAttachmentResource::collection($this->whenLoaded('supportingAttachments')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
