<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employment_rotations', function (Blueprint $table) {
            $table->foreignId('destination_office_id')
                ->nullable()
                ->after('source_office_membership_id')
                ->constrained('offices')
                ->restrictOnDelete();
            $table->string('position_assignment_mode', 30)
                ->nullable()
                ->after('destination_office_position_id');
            $table->string('destination_position_title')
                ->nullable()
                ->after('position_assignment_mode');
            $table->string('destination_membership_role', 30)
                ->nullable()
                ->after('destination_position_title');
        });

        // Las rotaciones anteriores utilizaron un cargo ya existente. Se
        // conserva ese hecho sin atribuirles retroactivamente una creación.
        DB::statement(<<<'SQL'
            UPDATE employment_rotations AS rotation
            SET destination_office_id = position.office_id,
                position_assignment_mode = 'legacy_existing',
                destination_position_title = position.name,
                destination_membership_role = position.membership_role
            FROM office_positions AS position
            WHERE rotation.destination_office_position_id = position.id
        SQL);

        DB::statement('ALTER TABLE employment_rotations ALTER COLUMN destination_office_id SET NOT NULL');
        DB::statement('ALTER TABLE employment_rotations ALTER COLUMN position_assignment_mode SET NOT NULL');
        DB::statement('ALTER TABLE employment_rotations ALTER COLUMN destination_position_title SET NOT NULL');
        DB::statement('ALTER TABLE employment_rotations ALTER COLUMN destination_membership_role SET NOT NULL');
        DB::statement('ALTER TABLE employment_rotations ALTER COLUMN destination_office_position_id DROP NOT NULL');

        DB::statement(<<<'SQL'
            ALTER TABLE employment_rotations
            ADD CONSTRAINT employment_rotations_position_mode_check
            CHECK (position_assignment_mode IN ('preserve_current', 'create_new', 'legacy_existing'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE employment_rotations
            ADD CONSTRAINT employment_rotations_destination_role_check
            CHECK (destination_membership_role IN ('manager', 'official'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE employment_rotations
            ADD CONSTRAINT employment_rotations_position_reference_check
            CHECK (
                (position_assignment_mode = 'preserve_current' AND destination_office_position_id IS NULL)
                OR
                (position_assignment_mode IN ('create_new', 'legacy_existing') AND destination_office_position_id IS NOT NULL)
            )
        SQL);

        Schema::table('employment_rotations', function (Blueprint $table) {
            $table->index(
                ['destination_office_id', 'effective_from'],
                'employment_rotations_destination_office_from_index',
            );
        });
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE employment_rotations DROP CONSTRAINT IF EXISTS employment_rotations_position_reference_check');
        DB::statement('ALTER TABLE employment_rotations DROP CONSTRAINT IF EXISTS employment_rotations_destination_role_check');
        DB::statement('ALTER TABLE employment_rotations DROP CONSTRAINT IF EXISTS employment_rotations_position_mode_check');

        // El esquema anterior exigía una referencia. Para permitir un rollback
        // técnico, las rotaciones que conservaron cargo apuntan al cargo
        // contractual original, sin borrar su historial.
        DB::statement(<<<'SQL'
            UPDATE employment_rotations AS rotation
            SET destination_office_position_id = contract.office_position_id
            FROM employment_contracts AS contract
            WHERE rotation.employment_contract_id = contract.id
              AND rotation.destination_office_position_id IS NULL
        SQL);
        DB::statement('ALTER TABLE employment_rotations ALTER COLUMN destination_office_position_id SET NOT NULL');

        Schema::table('employment_rotations', function (Blueprint $table) {
            $table->dropIndex('employment_rotations_destination_office_from_index');
            $table->dropConstrainedForeignId('destination_office_id');
            $table->dropColumn([
                'position_assignment_mode',
                'destination_position_title',
                'destination_membership_role',
            ]);
        });
    }
};
