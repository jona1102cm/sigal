<?php

namespace App\Http\Requests\DocumentManagement;

use App\Domain\DocumentManagement\Enums\ExpedientOrigin;
use App\Domain\DocumentManagement\Enums\ExpedientPriority;
use App\Domain\DocumentManagement\Enums\SenderType;
use App\Models\Expedient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpedientRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->filled('priority')) {
            $this->merge(['priority' => ExpedientPriority::Normal->value]);
        }

        self::resolveSingleResponsibleOffice($this);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', Expedient::class) ?? false;
    }

    public function rules(): array
    {
        return self::registrationRules();
    }

    /** @return array<string, list<mixed>> */
    public static function registrationRules(): array
    {
        return [
            'expedient_type_id' => ['required', 'integer', 'exists:expedient_types,id'],
            'confidentiality_level_id' => ['nullable', 'integer', 'exists:confidentiality_levels,id'],
            'subject' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string'],
            'origin' => ['required', Rule::enum(ExpedientOrigin::class)],
            'sender_type' => ['nullable', 'required_if:origin,external', Rule::enum(SenderType::class)],
            'sender_name' => ['nullable', 'required_if:origin,external', 'string', 'max:255'],
            'origin_office_id' => ['nullable', 'integer', 'exists:offices,id', 'prohibited_if:origin,external'],
            'responsible_office_id' => ['required', 'integer', 'exists:offices,id'],
            'received_on' => ['required', 'date_format:Y-m-d'],
            'priority' => ['required', Rule::enum(ExpedientPriority::class)],
            'due_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:received_on'],
            'classification' => ['nullable', 'string', 'max:255'],
            'observations' => ['nullable', 'string'],
            ...self::optionalDerivationRules(),
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function optionalDerivationRules(): array
    {
        return [
            'primary_office_ids' => ['nullable', 'array', 'min:1', 'required_with:copy_office_ids'],
            'primary_office_ids.*' => ['required', 'integer', 'distinct', 'exists:offices,id'],
            'copy_office_ids' => ['nullable', 'array'],
            'copy_office_ids.*' => ['required', 'integer', 'distinct', 'exists:offices,id'],
            'requires_response' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public static function registrationMessages(): array
    {
        return [
            'expedient_type_id.exists' => 'El tipo de expediente seleccionado ya no está disponible. Actualice el formulario y vuelva a seleccionarlo.',
            'confidentiality_level_id.exists' => 'El nivel de confidencialidad seleccionado ya no está disponible. Actualice el formulario y vuelva a seleccionarlo.',
            'responsible_office_id.required' => 'No fue posible determinar la oficina responsable. Actualice la sesión o solicite a Recursos Humanos verificar su asignación vigente.',
            'responsible_office_id.exists' => 'La oficina responsable seleccionada ya no está disponible. Actualice el formulario y vuelva a seleccionarla.',
            'origin_office_id.exists' => 'La oficina de origen seleccionada ya no está disponible.',
        ];
    }

    public function messages(): array
    {
        return self::registrationMessages();
    }

    public static function resolveSingleResponsibleOffice(FormRequest $request): void
    {
        $responsibleOfficeId = $request->input('responsible_office_id');

        if (filter_var($responsibleOfficeId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false) {
            return;
        }

        $officeIds = $request->user()?->currentOfficeMemberships()
            ->whereHas('office', fn ($query) => $query->active()->supportingStaffing())
            ->pluck('office_id')
            ->unique()
            ->values();

        $request->merge([
            'responsible_office_id' => $officeIds?->count() === 1 ? $officeIds->first() : null,
        ]);
    }
}
