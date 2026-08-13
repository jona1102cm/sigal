<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expedients', function (Blueprint $table) {
            $table->foreignId('archived_by')->nullable()->after('archived_at')->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by')->nullable()->after('closed_at')->constrained('users')->restrictOnDelete();
            $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->restrictOnDelete();
        });

        Schema::create('expedient_reopening_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expedient_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->text('justification');
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestampsTz();

            $table->index(['expedient_id', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE expedient_reopening_requests
            ADD CONSTRAINT expedient_reopening_requests_status_check
            CHECK (status IN ('pending', 'approved', 'rejected'))
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX expedient_reopening_requests_pending_unique
            ON expedient_reopening_requests (expedient_id)
            WHERE status = 'pending'
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('expedient_reopening_requests');

        Schema::table('expedients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropConstrainedForeignId('closed_by');
            $table->dropConstrainedForeignId('archived_by');
        });
    }
};
