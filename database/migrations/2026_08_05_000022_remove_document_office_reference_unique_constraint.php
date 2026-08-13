<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_issuing_office_id_office_reference_unique');
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->unique(['issuing_office_id', 'office_reference']);
        });
    }
};
