<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_manager_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->restrictOnDelete();
            $table->foreignId('office_membership_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->text('designation_note')->nullable();
            $table->text('closure_note')->nullable();
            $table->foreignId('designated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['office_id', 'effective_from']);
            $table->index(['user_id', 'effective_from']);
            $table->index(['office_membership_id', 'effective_from']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE office_manager_assignments
            ADD CONSTRAINT office_manager_assignments_effective_range_check
            CHECK (effective_to IS NULL OR effective_to >= effective_from)
        SQL);

        // La exclusión protege incluso ante solicitudes concurrentes: una oficina
        // nunca puede tener dos responsables con vigencias superpuestas.
        DB::statement(<<<'SQL'
            ALTER TABLE office_manager_assignments
            ADD CONSTRAINT office_manager_assignments_no_overlap
            EXCLUDE USING gist (
                office_id WITH =,
                tstzrange(effective_from, COALESCE(effective_to, 'infinity'::timestamptz), '[)') WITH &&
            )
        SQL);

        // Conserva como punto de partida la jefatura vigente más reciente de cada
        // oficina. Desde esta migración, la designación temporal es la autoridad.
        DB::statement(<<<'SQL'
            INSERT INTO office_manager_assignments (
                office_id,
                office_membership_id,
                user_id,
                effective_from,
                effective_to,
                designation_note,
                designated_by,
                created_at,
                updated_at
            )
            SELECT DISTINCT ON (membership.office_id)
                membership.office_id,
                membership.id,
                membership.user_id,
                membership.effective_from,
                membership.effective_to,
                'Migrado desde la jefatura contractual vigente.',
                membership.assigned_by,
                NOW(),
                NOW()
            FROM office_memberships AS membership
            WHERE membership.membership_role = 'manager'
              AND membership.effective_from <= NOW()
              AND (membership.effective_to IS NULL OR membership.effective_to > NOW())
            ORDER BY membership.office_id, membership.effective_from DESC, membership.id DESC
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('office_manager_assignments');
    }
};
