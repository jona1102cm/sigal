<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expedients', function (Blueprint $table): void {
            $table->boolean('is_uat')->default(false)->index();
        });

        Schema::table('warehouse_receipts', function (Blueprint $table): void {
            $table->boolean('is_uat')->default(false)->index();
        });

        Schema::table('warehouse_stock_movements', function (Blueprint $table): void {
            $table->boolean('is_uat')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_stock_movements', fn (Blueprint $table) => $table->dropColumn('is_uat'));
        Schema::table('warehouse_receipts', fn (Blueprint $table) => $table->dropColumn('is_uat'));
        Schema::table('expedients', fn (Blueprint $table) => $table->dropColumn('is_uat'));
    }
};
