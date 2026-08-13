<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expedient_movements', function (Blueprint $table): void {
            $table->boolean('requires_response')->default(true);
        });

        DB::statement('ALTER TABLE expedient_movement_recipients DROP CONSTRAINT IF EXISTS expedient_movement_recipients_status_check');
        DB::statement("ALTER TABLE expedient_movement_recipients ADD CONSTRAINT expedient_movement_recipients_status_check CHECK (status IN ('pending', 'received', 'in_process', 'responded', 'returned', 'rejected', 'completed'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE expedient_movement_recipients DROP CONSTRAINT IF EXISTS expedient_movement_recipients_status_check');
        DB::statement("ALTER TABLE expedient_movement_recipients ADD CONSTRAINT expedient_movement_recipients_status_check CHECK (status IN ('pending', 'received', 'in_process', 'responded', 'returned', 'rejected'))");

        Schema::table('expedient_movements', function (Blueprint $table): void {
            $table->dropColumn('requires_response');
        });
    }
};
