<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_document_access_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->unique()->constrained()->restrictOnDelete();
            $table->string('mode', 30)->default('manager_assignment');
            $table->foreignId('configured_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE office_document_access_settings
            ADD CONSTRAINT office_document_access_settings_mode_check
            CHECK (mode IN ('manager_assignment', 'authorized_team', 'all_members'))
        SQL);

        Schema::create('office_document_access_authorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_document_access_setting_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('authorized_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'effective_from']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE office_document_access_authorizations
            ADD CONSTRAINT office_document_access_authorizations_range_check
            CHECK (effective_to IS NULL OR effective_to >= effective_from)
        SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX office_document_access_authorizations_active_unique
            ON office_document_access_authorizations (office_document_access_setting_id, user_id)
            WHERE effective_to IS NULL
        SQL);

        Schema::create('expedient_internal_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expedient_movement_recipient_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('assignment_role', 20);
            $table->foreignId('assigned_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'effective_from']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE expedient_internal_assignments
            ADD CONSTRAINT expedient_internal_assignments_role_check
            CHECK (assignment_role IN ('responsible', 'collaborator'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE expedient_internal_assignments
            ADD CONSTRAINT expedient_internal_assignments_range_check
            CHECK (effective_to IS NULL OR effective_to >= effective_from)
        SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX expedient_internal_assignments_active_user_unique
            ON expedient_internal_assignments (expedient_movement_recipient_id, user_id)
            WHERE effective_to IS NULL
        SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX expedient_internal_assignments_one_responsible_unique
            ON expedient_internal_assignments (expedient_movement_recipient_id)
            WHERE effective_to IS NULL AND assignment_role = 'responsible'
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('expedient_internal_assignments');
        Schema::dropIfExists('office_document_access_authorizations');
        Schema::dropIfExists('office_document_access_settings');
    }
};
