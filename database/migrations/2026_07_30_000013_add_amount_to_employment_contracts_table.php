<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employment_contracts', function (Blueprint $table) {
            $table->decimal('contract_amount', 14, 2)->nullable()->after('contract_type');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE employment_contracts
            ADD CONSTRAINT employment_contracts_amount_check
            CHECK (contract_amount IS NULL OR contract_amount >= 0)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE employment_contracts DROP CONSTRAINT employment_contracts_amount_check');

        Schema::table('employment_contracts', function (Blueprint $table) {
            $table->dropColumn('contract_amount');
        });
    }
};
