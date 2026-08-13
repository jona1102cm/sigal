<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE expedients ALTER COLUMN summary DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement("UPDATE expedients SET summary = '' WHERE summary IS NULL");
        DB::statement('ALTER TABLE expedients ALTER COLUMN summary SET NOT NULL');
    }
};
