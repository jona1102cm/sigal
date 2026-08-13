<?php

namespace App\Domain\DocumentManagement\Services;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\DocumentManagement\DTOs\CreateExpedientTypeData;
use App\Domain\DocumentManagement\Enums\CatalogStatus;
use App\Models\ExpedientType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ExpedientTypeService
{
    public function __construct(private readonly ActivityLogger $activityLogger) {}

    public function create(CreateExpedientTypeData $data, User $actor, RequestAuditContext $context): ExpedientType
    {
        return DB::transaction(function () use ($data, $actor, $context): ExpedientType {
            $expedientType = ExpedientType::query()->create([
                'code' => $data->code,
                'name' => $data->name,
                'category' => $data->category,
                'status' => CatalogStatus::Active,
            ]);

            $this->activityLogger->record(
                event: 'document_management.expedient_type.created',
                actor: $actor,
                subject: $expedientType,
                context: $context,
                newValues: $this->snapshot($expedientType),
            );

            return $expedientType;
        });
    }

    public function update(
        ExpedientType $expedientType,
        CreateExpedientTypeData $data,
        User $actor,
        RequestAuditContext $context,
    ): ExpedientType {
        return DB::transaction(function () use ($expedientType, $data, $actor, $context): ExpedientType {
            $target = ExpedientType::query()->lockForUpdate()->findOrFail($expedientType->id);
            $oldValues = $this->snapshot($target);

            $target->update([
                'code' => $data->code,
                'name' => $data->name,
                'category' => $data->category,
            ]);

            $this->activityLogger->record(
                event: 'document_management.expedient_type.updated',
                actor: $actor,
                subject: $target,
                context: $context,
                oldValues: $oldValues,
                newValues: $this->snapshot($target),
            );

            return $target;
        });
    }

    public function activate(ExpedientType $expedientType, User $actor, RequestAuditContext $context): ExpedientType
    {
        return $this->changeStatus($expedientType, CatalogStatus::Active, $actor, $context);
    }

    public function inactivate(ExpedientType $expedientType, User $actor, RequestAuditContext $context): ExpedientType
    {
        return $this->changeStatus($expedientType, CatalogStatus::Inactive, $actor, $context);
    }

    private function changeStatus(
        ExpedientType $expedientType,
        CatalogStatus $status,
        User $actor,
        RequestAuditContext $context,
    ): ExpedientType {
        return DB::transaction(function () use ($expedientType, $status, $actor, $context): ExpedientType {
            $target = ExpedientType::query()->lockForUpdate()->findOrFail($expedientType->id);

            if ($target->status === $status) {
                return $target;
            }

            $oldValues = $this->snapshot($target);
            $target->update(['status' => $status]);

            $this->activityLogger->record(
                event: "document_management.expedient_type.{$status->value}",
                actor: $actor,
                subject: $target,
                context: $context,
                oldValues: $oldValues,
                newValues: $this->snapshot($target),
            );

            return $target;
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(ExpedientType $expedientType): array
    {
        return [
            'id' => $expedientType->id,
            'code' => $expedientType->code,
            'name' => $expedientType->name,
            'category' => $expedientType->category,
            'status' => $expedientType->status->value,
        ];
    }
}
