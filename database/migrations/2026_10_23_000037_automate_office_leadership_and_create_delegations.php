<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('office_manager_assignments', function (Blueprint $table) {
            $table->string('source', 32)->default('contract_position')->after('effective_to');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE office_manager_assignments
            ADD CONSTRAINT office_manager_assignments_source_check
            CHECK (source IN ('contract_position', 'legacy_manual'))
        SQL);

        // El cargo del contrato vuelve a ser la fuente autoritativa. Esto también
        // repara membresías importadas antes de que el vínculo se automatizara.
        DB::statement(<<<'SQL'
            UPDATE office_memberships AS membership
            SET membership_role = position.membership_role,
                position_title = position.name,
                updated_at = NOW()
            FROM employment_contracts AS contract
            INNER JOIN office_positions AS position ON position.id = contract.office_position_id
            WHERE membership.employment_contract_id = contract.id
              AND (
                  membership.membership_role IS DISTINCT FROM position.membership_role
                  OR membership.position_title IS DISTINCT FROM position.name
              )
        SQL);

        DB::statement(<<<'SQL'
            UPDATE office_manager_assignments AS assignment
            SET source = CASE
                    WHEN membership.membership_role = 'manager'
                         AND membership.employment_contract_id IS NOT NULL
                    THEN 'contract_position'
                    ELSE 'legacy_manual'
                END,
                effective_to = CASE
                    WHEN (membership.membership_role <> 'manager'
                          OR membership.employment_contract_id IS NULL)
                         AND (assignment.effective_to IS NULL OR assignment.effective_to > NOW())
                    THEN GREATEST(assignment.effective_from, NOW())
                    ELSE assignment.effective_to
                END,
                closure_note = CASE
                    WHEN (membership.membership_role <> 'manager'
                          OR membership.employment_contract_id IS NULL)
                         AND (assignment.effective_to IS NULL OR assignment.effective_to > NOW())
                    THEN 'Cierre automático: la responsabilidad depende del cargo contractual.'
                    ELSE assignment.closure_note
                END,
                updated_at = NOW()
            FROM office_memberships AS membership
            WHERE membership.id = assignment.office_membership_id
        SQL);

        // Una instalación que haya registrado contratos después de la migración
        // anterior recibe aquí el responsable que le corresponde por su cargo.
        DB::statement(<<<'SQL'
            INSERT INTO office_manager_assignments (
                office_id,
                office_membership_id,
                user_id,
                effective_from,
                effective_to,
                source,
                designation_note,
                designated_by,
                created_at,
                updated_at
            )
            SELECT DISTINCT ON (membership.office_id)
                membership.office_id,
                membership.id,
                membership.user_id,
                GREATEST(membership.effective_from, NOW()),
                membership.effective_to,
                'contract_position',
                'Asignación automática por cargo contractual responsable.',
                membership.assigned_by,
                NOW(),
                NOW()
            FROM office_memberships AS membership
            INNER JOIN offices AS office ON office.id = membership.office_id
            INNER JOIN users AS account ON account.id = membership.user_id
            WHERE membership.membership_role = 'manager'
              AND membership.employment_contract_id IS NOT NULL
              AND membership.effective_from <= NOW()
              AND (membership.effective_to IS NULL OR membership.effective_to > NOW())
              AND office.status = 'active'
              AND office.supports_staffing = TRUE
              AND office.requires_manager = TRUE
              AND account.status = 'active'
              AND NOT EXISTS (
                  SELECT 1
                  FROM office_manager_assignments AS current_assignment
                  WHERE current_assignment.office_id = membership.office_id
                    AND current_assignment.effective_from <= NOW()
                    AND (current_assignment.effective_to IS NULL OR current_assignment.effective_to > NOW())
              )
            ORDER BY membership.office_id, membership.effective_from DESC, membership.id DESC
        SQL);

        Schema::create('office_manager_delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->restrictOnDelete();
            $table->foreignId('principal_assignment_id')->constrained('office_manager_assignments')->restrictOnDelete();
            $table->foreignId('delegate_membership_id')->constrained('office_memberships')->restrictOnDelete();
            $table->foreignId('delegate_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to');
            $table->text('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['office_id', 'effective_from']);
            $table->index(['delegate_user_id', 'effective_from']);
            $table->index(['principal_assignment_id', 'effective_from'], 'office_manager_delegations_principal_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE office_manager_delegations
            ADD CONSTRAINT office_manager_delegations_effective_range_check
            CHECK (effective_to > effective_from)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE office_manager_delegations
            ADD CONSTRAINT office_manager_delegations_no_overlap
            EXCLUDE USING gist (
                office_id WITH =,
                tstzrange(effective_from, effective_to, '[)') WITH &&
            )
            WHERE (cancelled_at IS NULL)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('office_manager_delegations');

        DB::statement('ALTER TABLE office_manager_assignments DROP CONSTRAINT IF EXISTS office_manager_assignments_source_check');
        Schema::table('office_manager_assignments', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
