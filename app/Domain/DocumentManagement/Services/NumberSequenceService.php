<?php

namespace App\Domain\DocumentManagement\Services;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\DocumentManagement\DTOs\OfficeDocumentNumberData;
use App\Domain\DocumentManagement\DTOs\ReservedNumber;
use App\Models\InstitutionalSequence;
use App\Models\Legislature;
use App\Models\Office;
use App\Models\OfficeDocumentSequence;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class NumberSequenceService
{
    public function __construct(private readonly ActivityLogger $activityLogger) {}

    public function configureOfficeDocumentSequence(
        Legislature $legislature,
        Office $office,
        OfficeDocumentNumberData $data,
        User $actor,
        RequestAuditContext $context,
    ): OfficeDocumentSequence {
        return DB::transaction(function () use ($legislature, $office, $data, $actor, $context): OfficeDocumentSequence {
            $sequence = OfficeDocumentSequence::query()
                ->where('legislature_id', $legislature->id)
                ->where('office_id', $office->id)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                $sequence = OfficeDocumentSequence::query()->create([
                    'legislature_id' => $legislature->id,
                    'office_id' => $office->id,
                    'prefix' => $data->prefix,
                    'padding' => $data->padding,
                    'last_issued_number' => 0,
                ]);

                $this->activityLogger->record(
                    event: 'document_management.office_document_sequence.created',
                    actor: $actor,
                    subject: $sequence,
                    context: $context,
                    newValues: $this->officeSequenceSnapshot($sequence),
                );

                return $sequence;
            }

            $oldValues = $this->officeSequenceSnapshot($sequence);
            $sequence->update([
                'prefix' => $data->prefix,
                'padding' => $data->padding,
            ]);

            $this->activityLogger->record(
                event: 'document_management.office_document_sequence.updated',
                actor: $actor,
                subject: $sequence,
                context: $context,
                oldValues: $oldValues,
                newValues: $this->officeSequenceSnapshot($sequence),
            );

            return $sequence;
        });
    }

    public function reserveInstitutionalRouteNumber(Legislature $legislature): ReservedNumber
    {
        return DB::transaction(function () use ($legislature): ReservedNumber {
            DB::table('institutional_sequences')->insertOrIgnore([
                'legislature_id' => $legislature->id,
                'last_issued_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sequence = InstitutionalSequence::query()
                ->where('legislature_id', $legislature->id)
                ->lockForUpdate()
                ->firstOrFail();

            $sequence->increment('last_issued_number');
            $sequence->refresh();

            return new ReservedNumber(
                number: $sequence->last_issued_number,
                formatted: sprintf('SIGAL-%06d/%s', $sequence->last_issued_number, $legislature->period_label),
            );
        });
    }

    public function reserveOfficeDocumentNumber(Legislature $legislature, Office $office): ReservedNumber
    {
        return DB::transaction(function () use ($legislature, $office): ReservedNumber {
            DB::table('office_document_sequences')->insertOrIgnore([
                'legislature_id' => $legislature->id,
                'office_id' => $office->id,
                'prefix' => $office->code,
                'padding' => 3,
                'last_issued_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sequence = OfficeDocumentSequence::query()
                ->where('legislature_id', $legislature->id)
                ->where('office_id', $office->id)
                ->lockForUpdate()
                ->firstOrFail();

            $sequence->increment('last_issued_number');
            $sequence->refresh();

            return new ReservedNumber(
                number: $sequence->last_issued_number,
                formatted: sprintf(
                    '%s-%0'.$sequence->padding.'d/%s',
                    $sequence->prefix,
                    $sequence->last_issued_number,
                    $legislature->period_label,
                ),
            );
        });
    }

    /** @return array<string, mixed> */
    private function officeSequenceSnapshot(OfficeDocumentSequence $sequence): array
    {
        return [
            'id' => $sequence->id,
            'legislature_id' => $sequence->legislature_id,
            'office_id' => $sequence->office_id,
            'prefix' => $sequence->prefix,
            'padding' => $sequence->padding,
            'last_issued_number' => $sequence->last_issued_number,
        ];
    }
}
