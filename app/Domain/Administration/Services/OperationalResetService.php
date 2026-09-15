<?php

namespace App\Domain\Administration\Services;

use App\Domain\Administration\DTOs\OperationalResetResult;
use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Domain\Audit\Services\ActivityLogger;
use App\Domain\Authorization\Enums\RoleCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Limpia datos transaccionales de la etapa beta conservando estructura institucional.
 *
 * Es una operación extraordinaria, restringida y auditable; no sustituye políticas
 * de retención, respaldos ni migraciones de base de datos.
 */
class OperationalResetService
{
    public function __construct(private readonly ActivityLogger $activityLogger) {}

    /** @return array<string, int> */
    public function summary(): array
    {
        return [
            'expedients' => DB::table('expedients')->count(),
            'documents' => DB::table('documents')->count(),
            'document_attachments' => DB::table('document_attachments')->count(),
            'legislatures' => DB::table('legislatures')->count(),
            'employees' => DB::table('employees')->count(),
            'employee_accounts' => DB::table('users')->whereNotNull('employee_id')->count(),
            'employment_contracts' => DB::table('employment_contracts')->count(),
            'employee_attachments' => DB::table('employee_attachments')->count(),
            'office_memberships' => DB::table('office_memberships')->count(),
            'material_requests' => DB::table('material_requests')->count(),
            'warehouse_receipts' => DB::table('warehouse_receipts')->count(),
            'warehouse_stock_movements' => DB::table('warehouse_stock_movements')->count(),
        ];
    }

    public function perform(User $actor, RequestAuditContext $context): OperationalResetResult
    {
        $files = $this->filesToDelete();
        $removed = DB::transaction(function () use ($actor, $context): array {
            $summary = $this->summary();
            $systemAccountIds = $this->systemAccountIds($actor);
            $auditActorIds = DB::table('activity_logs')->whereNotNull('actor_id')->distinct()->pluck('actor_id')->all();
            $preservedUserIds = array_values(array_unique([...$systemAccountIds, ...$auditActorIds]));
            $employeeUserIds = DB::table('users')->whereNotNull('employee_id')->pluck('id')->all();
            $historicalUserIds = array_values(array_diff($preservedUserIds, $systemAccountIds));
            $deletableUserIds = DB::table('users')->when(
                $preservedUserIds !== [],
                fn ($query) => $query->whereNotIn('id', $preservedUserIds),
            )->pluck('id')->all();

            DB::table('warehouse_stock_movements')->delete();
            DB::table('warehouse_delivery_lines')->delete();
            DB::table('warehouse_deliveries')->delete();
            DB::table('warehouse_receipt_attachments')->delete();
            DB::table('warehouse_receipt_lines')->delete();
            DB::table('warehouse_receipts')->delete();
            DB::table('material_request_decisions')->delete();
            DB::table('material_request_items')->delete();
            DB::table('material_requests')->delete();
            DB::table('warehouse_number_sequences')->delete();
            DB::table('warehouse_items')->update(['stock_on_hand' => 0]);

            DB::table('document_expedient_movement')->delete();
            DB::table('document_attachments')->delete();
            DB::table('document_revisions')->delete();
            DB::table('documents')->update(['supersedes_document_id' => null]);
            DB::table('documents')->delete();
            DB::table('document_number_series')->delete();
            DB::table('expedient_reopening_requests')->delete();
            DB::table('expedient_movement_recipients')->delete();
            DB::table('expedient_movements')->delete();
            DB::table('expedient_access_grants')->delete();
            DB::table('expedients')->delete();
            DB::table('office_document_sequences')->delete();
            DB::table('institutional_sequences')->delete();
            DB::table('legislature_board_assignments')->delete();
            DB::table('legislatures')->delete();

            DB::table('office_memberships')->delete();
            DB::table('employee_attachments')->delete();
            DB::table('user_role_assignments')->update(['employment_contract_id' => null]);
            DB::table('user_role_assignments')->whereNotIn('user_id', $systemAccountIds ?: [-1])->delete();
            DB::table('employment_contracts')->delete();
            DB::table('users')->whereNotNull('employee_id')->update(['employee_id' => null]);
            DB::table('employees')->delete();

            if ($deletableUserIds !== []) {
                DB::table('office_positions')->whereIn('created_by', $deletableUserIds)->update(['created_by' => null]);
                DB::table('user_role_assignments')->whereIn('assigned_by', $deletableUserIds)->update(['assigned_by' => null]);
                DB::table('personal_access_tokens')->whereIn('tokenable_id', $deletableUserIds)->where('tokenable_type', User::class)->delete();
                DB::table('users')->whereIn('id', $deletableUserIds)->delete();
            }

            $historicalEmployeeUserIds = array_values(array_intersect($employeeUserIds, $historicalUserIds));
            foreach ($historicalEmployeeUserIds as $userId) {
                DB::table('users')->where('id', $userId)->update([
                    'name' => "Cuenta historica {$userId}",
                    'email' => "historico-{$userId}@reset.sigal.local",
                    'status' => 'inactive',
                    'updated_at' => now(),
                ]);
            }

            DB::table('password_reset_tokens')->delete();
            DB::table('sessions')->delete();
            DB::table('jobs')->delete();
            DB::table('job_batches')->delete();
            DB::table('failed_jobs')->delete();

            $this->activityLogger->record(
                event: 'administration.operational_reset.performed',
                actor: $actor,
                subject: null,
                context: $context,
                oldValues: $summary,
                newValues: [
                    'preserved_offices' => DB::table('offices')->count(),
                    'preserved_office_positions' => DB::table('office_positions')->count(),
                    'preserved_system_accounts' => count($systemAccountIds),
                ],
            );

            return $summary;
        });

        $filesDeleted = 0;
        foreach ($files as $file) {
            try {
                if (Storage::disk($file->disk)->delete($file->path)) {
                    $filesDeleted++;
                }
            } catch (Throwable) {
                // The database reset remains valid even if a storage backend is temporarily unavailable.
            }
        }

        return new OperationalResetResult($removed, $filesDeleted, count($files) - $filesDeleted);
    }

    /** @return list<object{disk: string, path: string}> */
    private function filesToDelete(): array
    {
        return [
            ...DB::table('document_attachments')->get([
                'storage_disk as disk',
                'storage_path as path',
            ])->all(),
            ...DB::table('employee_attachments')->get([
                'disk',
                'path',
            ])->all(),
            ...DB::table('warehouse_receipt_attachments')->get([
                'storage_disk as disk',
                'storage_path as path',
            ])->all(),
        ];
    }

    /** @return list<int> */
    private function systemAccountIds(User $actor): array
    {
        $technicalAccountIds = DB::table('users')
            ->join('user_role_assignments', 'user_role_assignments.user_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'user_role_assignments.role_id')
            ->whereNull('users.employee_id')
            ->whereIn('roles.code', [RoleCode::SuperAdministrator->value, RoleCode::HumanResourcesManager->value])
            ->where('user_role_assignments.effective_from', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('user_role_assignments.effective_to')
                    ->orWhere('user_role_assignments.effective_to', '>', now());
            })
            ->distinct()
            ->pluck('users.id')
            ->all();

        return array_values(array_unique([...$technicalAccountIds, $actor->id]));
    }
}
