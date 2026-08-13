<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('identity_card', 30)->unique();
            $table->string('first_names');
            $table->string('last_names');
            $table->string('mobile_phone', 40);
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('cua_number', 50)->nullable();
            $table->date('birth_date');
            $table->string('military_service_booklet', 100)->nullable();
            $table->string('academic_degree');
            $table->string('profession');
            $table->string('blood_type', 20)->nullable();
            $table->text('emergency_contact')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['last_names', 'first_names']);
        });

        Schema::create('office_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('membership_role', 20)->default('official');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['office_id', 'name']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE office_positions
            ADD CONSTRAINT office_positions_membership_role_check
            CHECK (membership_role IN ('manager', 'official'))
        SQL);

        Schema::create('employment_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('office_position_id')->constrained()->restrictOnDelete();
            $table->string('contract_type', 30);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['employee_id', 'starts_on']);
            $table->index(['office_position_id', 'starts_on']);
            $table->index(['ends_on', 'ended_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE employment_contracts
            ADD CONSTRAINT employment_contracts_type_check
            CHECK (contract_type IN ('eventual', 'line_consultancy', 'tgn', 'functioning'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE employment_contracts
            ADD CONSTRAINT employment_contracts_date_range_check
            CHECK (ends_on IS NULL OR ends_on >= starts_on)
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX employment_contracts_one_open_contract_per_employee
            ON employment_contracts (employee_id)
            WHERE ended_at IS NULL
        SQL);

        Schema::create('employee_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('document_type', 30);
            $table->string('disk', 50);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('uploaded_at');
            $table->timestampsTz();

            $table->index(['employee_id', 'document_type']);
            $table->unique(['employee_id', 'document_type', 'sha256']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE employee_attachments
            ADD CONSTRAINT employee_attachments_type_check
            CHECK (document_type IN ('rejap_certificate', 'cenvi_certificate', 'electoral_registry_certificate'))
        SQL);

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->unique()->after('id')->constrained()->restrictOnDelete();
        });

        Schema::table('office_memberships', function (Blueprint $table) {
            $table->foreignId('employment_contract_id')->nullable()->after('id')->constrained()->restrictOnDelete();
        });

        Schema::table('user_role_assignments', function (Blueprint $table) {
            $table->foreignId('employment_contract_id')->nullable()->after('id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('user_role_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employment_contract_id');
        });

        Schema::table('office_memberships', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employment_contract_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_id');
        });

        Schema::dropIfExists('employee_attachments');
        Schema::dropIfExists('employment_contracts');
        Schema::dropIfExists('office_positions');
        Schema::dropIfExists('employees');
    }
};
