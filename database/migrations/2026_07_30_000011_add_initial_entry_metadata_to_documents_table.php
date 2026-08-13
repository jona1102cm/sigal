<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->boolean('is_initial')->default(false)->index();
            $table->string('origin_number', 255)->nullable();
            $table->date('origin_date')->nullable();
            $table->foreignId('issuing_office_id')->nullable()->change();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE documents
            ADD CONSTRAINT documents_initial_origin_metadata_check
            CHECK (NOT is_initial OR (origin_number IS NOT NULL AND origin_date IS NOT NULL))
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX documents_one_initial_per_expedient
            ON documents (expedient_id)
            WHERE is_initial
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS documents_one_initial_per_expedient');
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_initial_origin_metadata_check');

        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['is_initial', 'origin_number', 'origin_date']);
            $table->foreignId('issuing_office_id')->nullable(false)->change();
        });
    }
};
