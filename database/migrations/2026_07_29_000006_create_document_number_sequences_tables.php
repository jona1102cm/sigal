<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institutional_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legislature_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('last_issued_number')->default(0);
            $table->timestampsTz();
        });

        Schema::create('office_document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legislature_id')->constrained()->restrictOnDelete();
            $table->foreignId('office_id')->constrained()->restrictOnDelete();
            $table->string('prefix', 30);
            $table->unsignedSmallInteger('padding')->default(3);
            $table->unsignedBigInteger('last_issued_number')->default(0);
            $table->timestampsTz();

            $table->unique(['legislature_id', 'office_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE institutional_sequences
            ADD CONSTRAINT institutional_sequences_last_issued_number_check
            CHECK (last_issued_number >= 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE office_document_sequences
            ADD CONSTRAINT office_document_sequences_last_issued_number_check
            CHECK (last_issued_number >= 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE office_document_sequences
            ADD CONSTRAINT office_document_sequences_padding_check
            CHECK (padding BETWEEN 1 AND 12)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('office_document_sequences');
        Schema::dropIfExists('institutional_sequences');
    }
};
