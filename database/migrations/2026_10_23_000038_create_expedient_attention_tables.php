<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expedient_recipient_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expedient_movement_recipient_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestampTz('first_opened_at');
            $table->timestampsTz();

            $table->unique(
                ['expedient_movement_recipient_id', 'user_id'],
                'expedient_recipient_reads_recipient_user_unique',
            );
            $table->index(['user_id', 'first_opened_at']);
        });

        Schema::create('expedient_assignment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expedient_movement_recipient_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->text('justification')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('decision_note')->nullable();
            $table->timestampTz('requested_at');
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();

            $table->index(['expedient_movement_recipient_id', 'status'], 'expedient_assignment_requests_recipient_status_index');
            $table->index(['requested_by', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE expedient_assignment_requests
            ADD CONSTRAINT expedient_assignment_requests_status_check
            CHECK (status IN ('pending', 'accepted', 'rejected', 'cancelled'))
        SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX expedient_assignment_requests_pending_requester_unique
            ON expedient_assignment_requests (expedient_movement_recipient_id, requested_by)
            WHERE status = 'pending'
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('expedient_assignment_requests');
        Schema::dropIfExists('expedient_recipient_reads');
    }
};
