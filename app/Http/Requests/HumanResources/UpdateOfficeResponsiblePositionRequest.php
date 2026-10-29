<?php

namespace App\Http\Requests\HumanResources;

use App\Domain\Authorization\Enums\PermissionCode;
use App\Models\Office;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Valida la selección del único cargo responsable perteneciente a una oficina. */
class UpdateOfficeResponsiblePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(PermissionCode::HumanResourcesPositions) ?? false;
    }

    public function rules(): array
    {
        /** @var Office|null $office */
        $office = $this->route('office');

        return [
            'office_position_id' => [
                'required',
                'integer',
                Rule::exists('office_positions', 'id')->where('office_id', $office?->id),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'office_position_id.required' => 'Seleccione el cargo que ejercerá la responsabilidad de la oficina.',
            'office_position_id.exists' => 'El cargo seleccionado no pertenece a esta oficina.',
        ];
    }
}
