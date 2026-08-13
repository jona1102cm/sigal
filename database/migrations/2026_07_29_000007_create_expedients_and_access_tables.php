<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_capabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->restrictOnDelete();
            $table->string('capability', 50);
            $table->timestampsTz();

            $table->unique(['office_id', 'capability']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE office_capabilities
            ADD CONSTRAINT office_capabilities_capability_check
            CHECK (capability IN ('archive_expedients', 'close_expedients', 'void_expedients', 'approve_reopenings'))
        SQL);

        Schema::create('expedients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legislature_id')->constrained()->restrictOnDelete();
            $table->foreignId('expedient_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('confidentiality_level_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('route_number');
            $table->string('route_code')->unique();
            $table->string('subject');
            $table->text('summary');
            $table->string('origin', 20);
            $table->string('sender_type', 20);
            $table->string('sender_name');
            $table->foreignId('origin_office_id')->nullable()->constrained('offices')->restrictOnDelete();
            $table->foreignId('responsible_office_id')->constrained('offices')->restrictOnDelete();
            $table->date('received_on');
            $table->string('priority', 100)->nullable();
            $table->date('due_on')->nullable();
            $table->string('classification', 255)->nullable();
            $table->text('observations')->nullable();
            $table->string('status', 30)->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->timestampsTz();

            $table->unique(['legislature_id', 'route_number']);
            $table->index(['responsible_office_id', 'status']);
            $table->index(['created_by', 'status']);
            $table->index(['confidentiality_level_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE expedients
            ADD CONSTRAINT expedients_origin_check
            CHECK (origin IN ('internal', 'external'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE expedients
            ADD CONSTRAINT expedients_sender_type_check
            CHECK (sender_type IN ('person', 'organization'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE expedients
            ADD CONSTRAINT expedients_status_check
            CHECK (status IN (
                'registered', 'in_process', 'derived', 'pending_response', 'partially_responded',
                'fully_responded', 'observed', 'archived', 'closed', 'voided'
            ))
        SQL);

        Schema::create('expedient_access_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expedient_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('office_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->text('reason')->nullable();
            $table->timestampsTz();

            $table->index(['expedient_id', 'effective_from']);
            $table->index(['user_id', 'effective_from']);
            $table->index(['office_id', 'effective_from']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE expedient_access_grants
            ADD CONSTRAINT expedient_access_grants_target_check
            CHECK ((user_id IS NULL) <> (office_id IS NULL))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE expedient_access_grants
            ADD CONSTRAINT expedient_access_grants_effective_range_check
            CHECK (effective_to IS NULL OR effective_to >= effective_from)
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX expedient_access_grants_active_user_unique
            ON expedient_access_grants (expedient_id, user_id)
            WHERE effective_to IS NULL AND user_id IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX expedient_access_grants_active_office_unique
            ON expedient_access_grants (expedient_id, office_id)
            WHERE effective_to IS NULL AND office_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('expedient_access_grants');
        Schema::dropIfExists('expedients');
        Schema::dropIfExists('office_capabilities');
    }
};
