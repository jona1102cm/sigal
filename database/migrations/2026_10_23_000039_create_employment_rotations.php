<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('office_memberships', function (Blueprint $table) {
            $table->foreignId('office_position_id')
                ->nullable()
                ->after('office_id')
                ->constrained('office_positions')
                ->restrictOnDelete();
        });

        // Las pertenencias contractuales existentes representan la asignación
        // operativa inicial y, por tanto, heredan el cargo original del contrato.
        DB::statement(<<<'SQL'
            UPDATE office_memberships AS membership
            SET office_position_id = contract.office_position_id
            FROM employment_contracts AS contract
            WHERE membership.employment_contract_id = contract.id
              AND membership.office_position_id IS NULL
        SQL);

        Schema::create('employment_rotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employment_contract_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_office_membership_id')->constrained('office_memberships')->restrictOnDelete();
            $table->foreignId('destination_office_position_id')->constrained('office_positions')->restrictOnDelete();
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->text('reason')->nullable();
            $table->string('memorandum_number', 100)->nullable();
            $table->date('memorandum_date')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['employment_contract_id', 'effective_from']);
            $table->index(['destination_office_position_id', 'effective_from'], 'employment_rotations_destination_from_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE employment_rotations
            ADD CONSTRAINT employment_rotations_effective_range_check
            CHECK (effective_to IS NULL OR effective_to >= effective_from)
        SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX employment_rotations_one_open_per_contract
            ON employment_rotations (employment_contract_id)
            WHERE effective_to IS NULL
        SQL);

        Schema::table('office_memberships', function (Blueprint $table) {
            $table->foreignId('employment_rotation_id')
                ->nullable()
                ->after('employment_contract_id')
                ->unique()
                ->constrained('employment_rotations')
                ->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX office_memberships_one_open_per_contract
            ON office_memberships (employment_contract_id)
            WHERE employment_contract_id IS NOT NULL AND effective_to IS NULL
        SQL);

        Schema::create('employment_rotation_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employment_rotation_id')->unique()->constrained()->restrictOnDelete();
            $table->string('disk', 50);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('uploaded_at');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employment_rotation_attachments');

        DB::statement('DROP INDEX IF EXISTS office_memberships_one_open_per_contract');
        Schema::table('office_memberships', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employment_rotation_id');
        });

        Schema::dropIfExists('employment_rotations');

        Schema::table('office_memberships', function (Blueprint $table) {
            $table->dropConstrainedForeignId('office_position_id');
        });
    }
};
