<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expedient_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expedient_id')->constrained()->restrictOnDelete();
            $table->foreignId('sender_office_id')->constrained('offices')->restrictOnDelete();
            $table->text('instruction')->nullable();
            $table->string('priority', 100)->nullable();
            $table->date('due_on')->nullable();
            $table->foreignId('sent_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('sent_at');
            $table->timestampsTz();

            $table->index(['expedient_id', 'sent_at']);
        });

        Schema::create('expedient_movement_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expedient_movement_id')->constrained()->restrictOnDelete();
            $table->foreignId('recipient_office_id')->constrained('offices')->restrictOnDelete();
            $table->string('recipient_kind', 20);
            $table->string('status', 20)->default('pending')->index();
            $table->text('action_note')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('received_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['expedient_movement_id', 'recipient_office_id']);
            $table->index(['recipient_office_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE expedient_movement_recipients
            ADD CONSTRAINT expedient_movement_recipients_kind_check
            CHECK (recipient_kind IN ('primary', 'copy'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE expedient_movement_recipients
            ADD CONSTRAINT expedient_movement_recipients_status_check
            CHECK (status IN ('pending', 'received', 'in_process', 'responded', 'returned', 'rejected'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('expedient_movement_recipients');
        Schema::dropIfExists('expedient_movements');
    }
};
